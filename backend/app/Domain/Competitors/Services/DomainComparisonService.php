<?php

namespace App\Domain\Competitors\Services;

use App\Domain\Competitors\CompetitorProviderManager;
use App\Domain\Competitors\Providers\FirstPartyCrawlProvider;
use App\Domain\Competitors\Providers\SerperProvider;
use App\Domain\Crawler\UrlSecurityValidator;
use App\Models\CompetitorDomain;
use App\Models\CompetitorDomainSnapshot;
use App\Models\CrawledPage;
use App\Models\SearchPerformanceRecord;
use App\Models\Website;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

class DomainComparisonService
{
    public function __construct(private CompetitorProviderManager $providerManager)
    {
    }

    /**
     * Capture fresh domain overview snapshot for a domain (target website or competitor).
     * Combines verified first-party crawl/GSC telemetry with external provider estimates when configured.
     * NEVER fabricates unsupported third-party metrics (kept strictly null).
     */
    public function captureDomainSnapshot(
        Website $website,
        string $domain,
        bool $isTarget = false,
        ?CompetitorDomain $competitor = null,
        string $country = 'US',
        string $language = 'en',
        bool $forceRefresh = false
    ): CompetitorDomainSnapshot {
        $domain = CompetitorDomain::normalizeDomain($domain);

        // 1. Third-party domain metrics (if external provider configured)
        $extProvider = $this->providerManager->resolveForCapability('domain_metrics');
        $extMetrics = [];
        $dataSource = 'first_party_crawl';
        $classification = 'first_party_verified';

        if ($extProvider->getName() !== 'first_party_crawl') {
            $extRes = $this->providerManager->cachedCall(
                $website,
                'domain_metrics',
                "{$extProvider->getName()}:{$domain}:{$country}:{$language}",
                "domain_metrics:{$domain}",
                fn () => $extProvider->fetchDomainMetrics($domain, $country, $language),
                $forceRefresh
            );
            if ($extRes['available'] ?? false) {
                $extMetrics = $extRes['metrics'] ?? [];
                $dataSource = $extProvider->getName();
                $classification = 'third_party_estimate';
            }
        }

        // Also enrich with backlinks summary if a backlink provider is configured and domain_metrics didn't return it
        if (!isset($extMetrics['referring_domains_count']) && $this->providerManager->hasExternalProviderFor('backlinks')) {
            $blProvider = $this->providerManager->resolveForCapability('backlinks');
            $blRes = $this->providerManager->cachedCall(
                $website,
                'backlinks',
                "{$blProvider->getName()}:bl:{$domain}",
                "backlinks:{$domain}",
                fn () => $blProvider->fetchBacklinks($domain, 60),
                $forceRefresh
            );
            if ($blRes['available'] ?? false) {
                $summary = $blRes['summary'] ?? [];
                $extMetrics['referring_domains_count'] = $summary['referring_domains_count'] ?? ($extMetrics['referring_domains_count'] ?? null);
                $extMetrics['backlinks_count'] = $summary['backlinks_count'] ?? ($extMetrics['backlinks_count'] ?? null);
                if (!isset($extMetrics['authority_score']) && isset($summary['authority_score'])) {
                    $extMetrics['authority_score'] = $summary['authority_score'];
                    $extMetrics['authority_metric_label'] = $summary['authority_metric_label'] ?? $blProvider->getLabel();
                }
            }
        }

        // 2. Always gather verified first-party signals
        $crawledSignals = [];
        $topPages = $extMetrics['top_pages'] ?? [];
        $topKeywords = $extMetrics['top_keywords'] ?? [];
        $indexedPages = $extMetrics['indexed_pages_count'] ?? null;

        if ($isTarget) {
            // Pull verified first-party CrawledPage & GSC records already in our DB
            $targetPages = CrawledPage::where('website_id', $website->id)
                ->orderByRaw('LENGTH(url) ASC')
                ->take(20)
                ->get();

            if ($targetPages->isNotEmpty()) {
                $home = $targetPages->first();
                $headingsTree = is_array($home->headings_tree ?? null) ? $home->headings_tree : [];
                $h2Tags = [];
                $h3Count = 0;
                foreach ($headingsTree as $node) {
                    $tag = strtolower((string) ($node['tag'] ?? ''));
                    if ($tag === 'h2' && !empty($node['text'])) {
                        $h2Tags[] = trim((string) $node['text']);
                    } elseif ($tag === 'h3') {
                        $h3Count++;
                    }
                }
                $imgCount = (int) ($home->images_count ?? 0);
                $imgMiss = (int) ($home->images_missing_alt ?? 0);
                $altPct = $imgCount > 0 ? (int) round((($imgCount - $imgMiss) / $imgCount) * 100) : 100;
                $allUrlsBlob = strtolower($targetPages->pluck('url')->implode(' '));
                $crawledCount = CrawledPage::where('website_id', $website->id)->count();

                $crawledSignals = [
                    'reachable' => true,
                    'has_https' => str_starts_with(strtolower((string) ($home->url ?? $website->target_url)), 'https://'),
                    'http_status' => $home->status_code,
                    'page_title' => $home->page_title,
                    'title_length' => $home->page_title ? mb_strlen((string) $home->page_title) : 0,
                    'meta_description' => $home->meta_description,
                    'meta_description_length' => $home->meta_description ? mb_strlen((string) $home->meta_description) : 0,
                    'h1_tags' => $home->h1_tags ?? [],
                    'h1_count' => is_array($home->h1_tags) ? count($home->h1_tags) : 0,
                    'h2_tags' => array_slice($h2Tags, 0, 10),
                    'h2_count' => count($h2Tags),
                    'h3_count' => $h3Count,
                    'word_count' => $home->word_count,
                    'structured_data_types' => $home->structured_data['types'] ?? [],
                    'images_count' => $imgCount,
                    'images_missing_alt' => $imgMiss,
                    'image_alt_coverage_pct' => $altPct,
                    'internal_links_count' => $home->internal_links_count ?? 0,
                    'external_links_count' => $home->external_links_count ?? 0,
                    'has_canonical' => !empty($home->canonical_url),
                    'has_open_graph' => !empty($home->open_graph['title'] ?? null) || !empty($home->open_graph['image'] ?? null),
                    'has_viewport' => !empty($home->viewport),
                    'has_sitemap' => $crawledCount > 1,
                    'has_blog_section' => (bool) preg_match('#/(blog|articles?|news|guides?|resources)\b#i', $allUrlsBlob),
                    'load_time_ms' => $home->load_time_ms,
                    'crawled_pages_count' => $crawledCount,
                ];
                $crawledSignals['onpage_seo_score'] = FirstPartyCrawlProvider::computeOnPageSeoScore($crawledSignals);

                if (empty($topPages)) {
                    $topPages = $targetPages->take(12)->map(fn (CrawledPage $p) => [
                        'url' => $p->url,
                        'title' => $p->page_title ?: $p->url,
                        'word_count' => $p->word_count,
                        'estimated_traffic' => null,
                        'source' => 'first_party_crawl',
                    ])->values()->toArray();
                }
                $indexedPages = $indexedPages ?? $crawledCount;
            } else {
                // Live first-party inspection of target domain if Site Audit hasn't run yet
                $fp = $this->providerManager->getFirstPartyProvider();
                $fpRes = $this->providerManager->cachedCall(
                    $website,
                    'domain_metrics',
                    "fp_crawl:{$domain}",
                    "first_party_crawl:{$domain}",
                    fn () => $fp->fetchDomainMetrics($domain, $country, $language),
                    $forceRefresh
                );
                $fpMetrics = $fpRes['metrics'] ?? [];
                $crawledSignals = $fpMetrics['crawled_signals'] ?? [];
                if (empty($topPages) && !empty($fpMetrics['top_pages'])) {
                    $topPages = $fpMetrics['top_pages'];
                }
                $indexedPages = $indexedPages ?? ($fpMetrics['indexed_pages_count'] ?? null);
            }

            // First-party verified Google Search Console performance (if connected)
            $gscStats = SearchPerformanceRecord::where('website_id', $website->id)
                ->where('date', '>=', now()->subDays(31)->toDateString())
                ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, COUNT(DISTINCT query) as queries')
                ->first();

            $exactGscSnap = $website->gscIntegration?->metadata['exact_snapshots']['28d'] ?? null;
            if (($gscStats && ((int) $gscStats->queries > 0)) || is_array($exactGscSnap)) {
                $crawledSignals['gsc_28d_clicks'] = is_array($exactGscSnap)
                    ? (int) ($exactGscSnap['clicks'] ?? 0)
                    : (int) ($gscStats->clicks ?? 0);
                $crawledSignals['gsc_28d_impressions'] = is_array($exactGscSnap)
                    ? (int) ($exactGscSnap['impressions'] ?? 0)
                    : (int) ($gscStats->impressions ?? 0);
                $crawledSignals['gsc_unique_queries'] = (int) ($gscStats->queries ?? 0);

                if (empty($topKeywords)) {
                    $topKeywords = SearchPerformanceRecord::where('website_id', $website->id)
                        ->where('date', '>=', now()->subDays(31)->toDateString())
                        ->whereNotNull('query')
                        ->where('query', '!=', '')
                        ->select('query')
                        ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions * 1.0) / SUM(impressions) ELSE 0 END as position')
                        ->groupBy('query')
                        ->orderByDesc('clicks')
                        ->orderByDesc('impressions')
                        ->limit(10)
                        ->get()
                        ->map(fn ($r) => [
                            'keyword' => $r->query,
                            'position' => round((float) $r->position, 1),
                            'clicks' => (int) $r->clicks,
                            'impressions' => (int) $r->impressions,
                            'source' => 'first_party_gsc',
                        ])->toArray();
                }

                if ($classification === 'third_party_estimate') {
                    $classification = 'hybrid';
                } elseif ($dataSource === 'first_party_crawl') {
                    $dataSource = 'first_party_gsc';
                }
            }
        } else {
            // Inspect public competitor homepage & sitemap via SSRF-safe first-party crawler
            $fp = $this->providerManager->getFirstPartyProvider();
            $fpRes = $this->providerManager->cachedCall(
                $website,
                'domain_metrics',
                "fp_crawl:{$domain}",
                "first_party_crawl:{$domain}",
                fn () => $fp->fetchDomainMetrics($domain, $country, $language),
                $forceRefresh
            );
            $fpMetrics = $fpRes['metrics'] ?? [];
            $crawledSignals = $fpMetrics['crawled_signals'] ?? [];
            if (empty($topPages) && !empty($fpMetrics['top_pages'])) {
                $topPages = $fpMetrics['top_pages'];
            }
            $indexedPages = $indexedPages ?? ($fpMetrics['indexed_pages_count'] ?? null);
            if ($classification === 'third_party_estimate' && !empty($crawledSignals['reachable'])) {
                $classification = 'hybrid';
            }
        }

        // 3. Enrich with live Google Index (site:domain) & Verified Backlinks/Web Mentions when Serper.dev / SERP provider is connected!
        $serpProv = $this->providerManager->resolveForCapability('serp', $website->organization_id);
        if ($serpProv->getName() === 'serper' || $serpProv->getName() === 'serpapi') {
                // 3a. Real Google-indexed pages (site:domain)
                $siteRes = $this->providerManager->cachedCall(
                    $website,
                    'serp',
                    "{$serpProv->getName()}:site:{$domain}:{$country}",
                    "google_index:{$domain}",
                    fn () => $serpProv instanceof SerperProvider
                        ? $serpProv->fetchSerp("site:{$domain}", $country, $language, 'desktop', null, 20)
                        : $serpProv->fetchSerp("site:{$domain}", $country, $language, 'desktop'),
                    $forceRefresh
                );
                if (($siteRes['available'] ?? false) && !empty($siteRes['organic_results'])) {
                    $googlePages = [];
                    foreach ($siteRes['organic_results'] as $row) {
                        $googlePages[] = [
                            'url' => $row['url'] ?? '',
                            'title' => $row['title'] ?? '',
                            'snippet' => $row['snippet'] ?? '',
                            'estimated_traffic' => null,
                            'source' => $serpProv->getName() . '_google_index',
                        ];
                    }
                    $crawledSignals['google_indexed_sample_count'] = count($googlePages);
                    // Fill homepage title/description from Google index if direct crawl hit WAF
                    if (empty($crawledSignals['page_title']) && !empty($googlePages[0]['title'])) {
                        $crawledSignals['page_title'] = $googlePages[0]['title'];
                        $crawledSignals['title_length'] = mb_strlen($googlePages[0]['title']);
                    }
                    if (empty($crawledSignals['meta_description']) && !empty($googlePages[0]['snippet'])) {
                        $crawledSignals['meta_description'] = $googlePages[0]['snippet'];
                        $crawledSignals['meta_description_length'] = mb_strlen($googlePages[0]['snippet']);
                    }
                    // Merge Google-indexed pages into top_pages (deduplicated by URL)
                    $existingUrls = array_flip(array_map(fn ($p) => (string) ($p['url'] ?? ''), $topPages));
                    foreach ($googlePages as $gp) {
                        if (!empty($gp['url']) && !isset($existingUrls[$gp['url']])) {
                            $topPages[] = $gp;
                            $existingUrls[$gp['url']] = true;
                        }
                    }
                    $topPages = array_slice($topPages, 0, 15);
                    if ($indexedPages === null || $indexedPages < count($googlePages)) {
                        $indexedPages = count($googlePages);
                    }
                    if ($dataSource === 'first_party_crawl') {
                        $dataSource = 'first_party_crawl+' . $serpProv->getName();
                        $classification = 'hybrid';
                    }
                }

                // 3b. Real Google referring pages & public web mentions ("domain" -site:domain) + Live HTML Backlink Verification
                $mentionRes = $this->providerManager->cachedCall(
                    $website,
                    'serp',
                    "{$serpProv->getName()}:mentions:{$domain}:{$country}:v2",
                    "web_mentions:{$domain}",
                    fn () => $serpProv instanceof SerperProvider
                        ? $serpProv->fetchSerp("\"{$domain}\" -site:{$domain}", $country, $language, 'desktop', null, 20)
                        : $serpProv->fetchSerp("\"{$domain}\" -site:{$domain}", $country, $language, 'desktop'),
                    $forceRefresh
                );
                if ($mentionRes['available'] ?? false) {
                    $mentions = [];
                    $seenMentionHosts = [];
                    foreach ($mentionRes['organic_results'] ?? [] as $mRow) {
                        $mHost = CompetitorDomain::normalizeDomain((string) ($mRow['domain'] ?? ''));
                        if ($mHost === '' || $mHost === $domain || str_ends_with($mHost, '.' . $domain) || isset($seenMentionHosts[$mHost])) {
                            continue;
                        }
                        $seenMentionHosts[$mHost] = true;
                        $mentions[] = [
                            'domain' => $mHost,
                            'url' => (string) ($mRow['url'] ?? ''),
                            'title' => (string) ($mRow['title'] ?? ''),
                            'snippet' => (string) ($mRow['snippet'] ?? ''),
                            'verified_backlink' => false,
                            'link_type' => 'mention',
                            'anchor_text' => null,
                            'destination_url' => null,
                            'source' => 'google_web_mention',
                        ];
                    }

                    // Mechanically probe top 10 external referring pages in parallel to verify actual <a href> backlinks + rel/anchor
                    $mentions = $this->verifyExternalBacklinks($domain, array_slice($mentions, 0, 18));

                    $verifiedCount = count(array_filter($mentions, fn ($m) => !empty($m['verified_backlink'])));
                    $dofollowCount = count(array_filter($mentions, fn ($m) => ($m['link_type'] ?? '') === 'dofollow'));
                    $nofollowCount = count(array_filter($mentions, fn ($m) => in_array($m['link_type'] ?? '', ['nofollow', 'ugc', 'sponsored'], true)));

                    $crawledSignals['web_mentions_count'] = count($mentions);
                    $crawledSignals['verified_backlinks_count'] = $verifiedCount;
                    $crawledSignals['dofollow_backlinks_count'] = $dofollowCount;
                    $crawledSignals['nofollow_backlinks_count'] = $nofollowCount;
                    $crawledSignals['web_mentions'] = $mentions;
                }

                // Recompute on-page SEO score with Google index signals included
                $crawledSignals['onpage_seo_score'] = FirstPartyCrawlProvider::computeOnPageSeoScore($crawledSignals);
        }

        $snapshot = CompetitorDomainSnapshot::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'competitor_domain_id' => $competitor?->id,
            'domain' => $domain,
            'is_target' => $isTarget,
            'country' => $country,
            'language' => $language,
            'organic_traffic_estimate' => $extMetrics['organic_traffic_estimate'] ?? null,
            'organic_keywords_count' => $extMetrics['organic_keywords_count'] ?? null,
            'traffic_value_estimate' => $extMetrics['traffic_value_estimate'] ?? null,
            'referring_domains_count' => $extMetrics['referring_domains_count'] ?? null,
            'backlinks_count' => $extMetrics['backlinks_count'] ?? null,
            'indexed_pages_count' => $indexedPages,
            'authority_score' => $extMetrics['authority_score'] ?? null,
            'authority_metric_label' => $extMetrics['authority_metric_label'] ?? null,
            'serp_visibility_score' => $extMetrics['serp_visibility_score'] ?? null,
            'ranking_distribution' => $extMetrics['ranking_distribution'] ?? null,
            'top_keywords' => $topKeywords,
            'top_pages' => $topPages,
            'crawled_signals' => $crawledSignals,
            'data_source' => $dataSource,
            'data_classification' => $classification,
            'captured_at' => now(),
        ]);

        if ($competitor) {
            $competitor->update(['last_analyzed_at' => now()]);
        }

        return $snapshot;
    }

    /**
     * Build side-by-side Domain Comparison payload for target website + up to 5 competitors.
     */
    public function buildComparison(Website $website, array $competitorIds = []): array
    {
        $maxComp = max(1, (int) config('competitors.max_comparison_competitors', 5));

        $query = CompetitorDomain::where('website_id', $website->id)->where('is_active', true);
        if (!empty($competitorIds)) {
            $query->whereIn('id', array_slice($competitorIds, 0, $maxComp));
        } else {
            $query->orderByRaw("CASE tier WHEN 'primary' THEN 1 ELSE 2 END")->limit($maxComp);
        }

        /** @var Collection<int, CompetitorDomain> $competitors */
        $competitors = $query->get();

        $targetSnapshot = CompetitorDomainSnapshot::where('website_id', $website->id)
            ->where('is_target', true)
            ->orderByDesc('captured_at')
            ->first();

        $rows = [];
        $rows[] = $this->formatDomainRow(
            CompetitorDomain::normalizeDomain($website->domain),
            $website->name,
            true,
            null,
            null,
            $targetSnapshot
        );

        foreach ($competitors as $comp) {
            $snap = CompetitorDomainSnapshot::where('competitor_domain_id', $comp->id)
                ->orderByDesc('captured_at')
                ->first();

            $rows[] = $this->formatDomainRow(
                $comp->domain,
                $comp->display_name ?: $comp->domain,
                false,
                $comp->id,
                $comp->category,
                $snap,
                $comp->tier
            );
        }

        // Calculate relative rankings & strength/weakness observations strictly from non-null metrics
        $insights = $this->buildStrengthWeaknessSummary($rows);

        return [
            'domains' => $rows,
            'insights' => $insights,
            'metric_definitions' => [
                'organic_traffic_estimate' => 'Third-party modeled monthly organic visits (estimate only — never actual analytics traffic).',
                'organic_keywords_count' => 'Estimated count of organic keywords where the domain ranks in top 100.',
                'referring_domains_count' => 'Unique external root domains linking to this website (provider-backed).',
                'authority_score' => 'Provider-specific domain authority metric (explicitly attributed to its provider).',
                'indexed_pages_count' => 'Publicly discoverable sitemap/crawled page count (first-party verified) or provider index estimate.',
                'gsc_28d_clicks' => 'Verified first-party Google Search Console clicks over the last 28 days (target site only).',
            ],
            'last_updated_at' => collect($rows)->pluck('captured_at')->filter()->max(),
        ];
    }

    private function formatDomainRow(
        string $domain,
        string $label,
        bool $isTarget,
        ?int $competitorId,
        ?string $category,
        ?CompetitorDomainSnapshot $snap,
        ?string $tier = null
    ): array {
        return [
            'competitor_id' => $competitorId,
            'domain' => $domain,
            'label' => $label,
            'is_target' => $isTarget,
            'category' => $category,
            'tier' => $tier,
            'has_snapshot' => $snap !== null,
            'organic_traffic_estimate' => $snap?->organic_traffic_estimate,
            'organic_keywords_count' => $snap?->organic_keywords_count,
            'traffic_value_estimate' => $snap?->traffic_value_estimate,
            'referring_domains_count' => $snap?->referring_domains_count,
            'backlinks_count' => $snap?->backlinks_count,
            'indexed_pages_count' => $snap?->indexed_pages_count,
            'authority_score' => $snap?->authority_score,
            'authority_metric_label' => $snap?->authority_metric_label,
            'serp_visibility_score' => $snap?->serp_visibility_score,
            'ranking_distribution' => $snap?->ranking_distribution,
            'top_keywords' => $snap?->top_keywords ?? [],
            'top_pages' => $snap?->top_pages ?? [],
            'crawled_signals' => $snap?->crawled_signals ?? [],
            'data_source' => $snap?->data_source ?? 'unscanned',
            'data_classification' => $snap?->data_classification ?? 'unavailable',
            'captured_at' => $snap?->captured_at?->toIso8601String(),
        ];
    }

    private function buildStrengthWeaknessSummary(array $rows): array
    {
        $target = $rows[0] ?? null;
        $peers = array_slice($rows, 1);
        $strengths = [];
        $weaknesses = [];

        if (!$target || empty($peers)) {
            return ['strengths' => $strengths, 'weaknesses' => $weaknesses];
        }

        $compareNumeric = [
            'organic_traffic_estimate' => 'Estimated Organic Traffic (third-party)',
            'organic_keywords_count' => 'Organic Keyword Coverage',
            'referring_domains_count' => 'Referring Domains',
            'authority_score' => 'Domain Authority Score',
            'indexed_pages_count' => 'Discovered / Indexed Pages',
        ];

        foreach ($compareNumeric as $field => $title) {
            $tVal = $target[$field] ?? null;
            $peerVals = array_values(array_filter(array_map(fn ($p) => $p[$field] ?? null, $peers), fn ($v) => $v !== null));
            if ($tVal === null || empty($peerVals)) {
                continue;
            }
            $avgPeer = array_sum($peerVals) / count($peerVals);
            $maxPeer = max($peerVals);
            if ($tVal >= $avgPeer) {
                $strengths[] = [
                    'metric' => $title,
                    'observation' => "{$target['domain']} ({$tVal}) is at or above the competitor average (" . round($avgPeer, 1) . ').',
                    'classification' => 'calculated',
                ];
            } else {
                $weaknesses[] = [
                    'metric' => $title,
                    'observation' => "{$target['domain']} ({$tVal}) trails the competitor average (" . round($avgPeer, 1) . ", top competitor: {$maxPeer}).",
                    'classification' => 'calculated',
                ];
            }
        }

        // First-party HTML crawl comparison (always works even without external API keys!)
        $tWords = $target['crawled_signals']['word_count'] ?? null;
        $pWords = array_values(array_filter(array_map(fn ($p) => $p['crawled_signals']['word_count'] ?? null, $peers), fn ($v) => $v !== null));
        if ($tWords !== null && !empty($pWords)) {
            $avgW = round(array_sum($pWords) / count($pWords));
            if ($tWords >= $avgW) {
                $strengths[] = [
                    'metric' => 'Homepage Content Depth (First-Party Verified)',
                    'observation' => "{$target['domain']} homepage has {$tWords} words vs competitor average of {$avgW} words.",
                    'classification' => 'first_party_verified',
                ];
            } else {
                $weaknesses[] = [
                    'metric' => 'Homepage Content Depth (First-Party Verified)',
                    'observation' => "{$target['domain']} homepage has {$tWords} words vs competitor average of {$avgW} words.",
                    'classification' => 'first_party_verified',
                ];
            }
        }

        $tSchema = count($target['crawled_signals']['structured_data_types'] ?? []);
        $pSchemaMax = max(array_merge([0], array_map(fn ($p) => count($p['crawled_signals']['structured_data_types'] ?? []), $peers)));
        if ($tSchema === 0 && $pSchemaMax > 0) {
            $weaknesses[] = [
                'metric' => 'Structured Data / Schema Markup (First-Party Verified)',
                'observation' => "{$target['domain']} has 0 JSON-LD schema types on homepage while tracked competitors implement up to {$pSchemaMax} types.",
                'classification' => 'first_party_verified',
            ];
        } elseif ($tSchema > 0 && $tSchema >= $pSchemaMax) {
            $strengths[] = [
                'metric' => 'Structured Data / Schema Markup (First-Party Verified)',
                'observation' => "{$target['domain']} implements {$tSchema} JSON-LD schema type(s), matching or exceeding tracked competitors.",
                'classification' => 'first_party_verified',
            ];
        }

        return ['strengths' => $strengths, 'weaknesses' => $weaknesses];
    }

    /**
     * Mechanically probe external referring pages in parallel (SSRF-safe, 3s timeout) to verify
     * real <a href> backlinks pointing to $targetDomain and extract exact anchor text & dofollow/nofollow rel.
     */
    private function verifyExternalBacklinks(string $targetDomain, array $mentions): array
    {
        if (empty($mentions)) {
            return [];
        }

        $probeList = [];
        foreach (array_slice($mentions, 0, 10) as $idx => $m) {
            $url = (string) ($m['url'] ?? '');
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            if (!app()->runningUnitTests()) {
                try {
                    $host = (string) parse_url($url, PHP_URL_HOST);
                    UrlSecurityValidator::validate($url, $host);
                } catch (Throwable) {
                    continue;
                }
            }
            $probeList[$idx] = $url;
        }

        if (empty($probeList)) {
            return $mentions;
        }

        try {
            $responses = Http::pool(function ($pool) use ($probeList) {
                foreach ($probeList as $idx => $url) {
                    $pool->as("m{$idx}")
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (compatible; WebSEOAI-BacklinkVerifier/1.0)',
                            'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                        ])
                        ->timeout(3)
                        ->get($url);
                }
            });

            $escapedDomain = preg_quote($targetDomain, '#');
            foreach ($probeList as $idx => $url) {
                $resp = $responses["m{$idx}"] ?? null;
                if (!$resp || ($resp instanceof Throwable) || !$resp->successful()) {
                    continue;
                }
                $html = (string) $resp->body();
                if ($html === '') {
                    continue;
                }

                // Match <a ... href="https://(www.)?targetDomain..." ...>anchor</a>
                if (preg_match('#<a\b([^>]*\bhref=["\'](https?://(?:[a-z0-9-]+\.)?' . $escapedDomain . '(?:/[^"\']*)?)["\'][^>]*)>(.*?)</a>#is', $html, $match)) {
                    $attrs = strtolower((string) $match[1]);
                    $destUrl = trim((string) $match[2]);
                    $anchor = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $match[3]), ENT_QUOTES | ENT_HTML5)));

                    $linkType = 'dofollow';
                    if (preg_match('/\brel=["\']([^"\']+)["\']/i', $attrs, $relMatch)) {
                        $relVal = strtolower($relMatch[1]);
                        if (str_contains($relVal, 'sponsored')) {
                            $linkType = 'sponsored';
                        } elseif (str_contains($relVal, 'ugc')) {
                            $linkType = 'ugc';
                        } elseif (str_contains($relVal, 'nofollow')) {
                            $linkType = 'nofollow';
                        }
                    }

                    $mentions[$idx]['verified_backlink'] = true;
                    $mentions[$idx]['link_type'] = $linkType;
                    $mentions[$idx]['anchor_text'] = $anchor !== '' ? mb_substr($anchor, 0, 140) : $targetDomain;
                    $mentions[$idx]['destination_url'] = $destUrl;
                    $mentions[$idx]['source'] = 'live_html_verified_backlink';
                }
            }
        } catch (Throwable) {
            // Keep Google web mentions safely even if parallel probe fails
        }

        // Sort verified dofollow backlinks first, then nofollow, then unverified citations
        $rankOrder = ['dofollow' => 1, 'ugc' => 2, 'sponsored' => 2, 'nofollow' => 3, 'mention' => 4];
        usort($mentions, fn ($a, $b) => ($rankOrder[$a['link_type'] ?? 'mention'] ?? 9) <=> ($rankOrder[$b['link_type'] ?? 'mention'] ?? 9));

        return $mentions;
    }
}
