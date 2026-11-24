<?php

namespace App\Domain\RankChecker;

use App\Domain\Competitors\CompetitorProviderManager;
use App\Domain\Competitors\Providers\SerperProvider;
use App\Domain\Competitors\Support\CountryCatalog;
use App\Models\CompetitorDomain;
use App\Models\CompetitorSerpSnapshot;
use App\Models\SearchPerformanceRecord;
use App\Models\Website;
use App\Models\WebsiteIntegration;

/**
 * Live Geo-Located Google Rank Checker & SERP Simulator.
 * Checks exact Google organic position, exact Google Page (Pages 1-10),
 * Local Map Pack presence, and cross-checks against verified 28-day GSC Avg Position.
 */
class RankCheckerService
{
    public function __construct(private CompetitorProviderManager $providerManager)
    {
    }

    /**
     * Load GSC quick-check keywords, recent rank check history, and provider status.
     */
    public function getContext(Website $website): array
    {
        $gscKeywords = SearchPerformanceRecord::where('website_id', $website->id)
            ->where('date', '>=', now()->subDays(31)->toDateString())
            ->whereNotNull('query')
            ->where('query', '!=', '')
            ->select('query')
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions * 1.0) / SUM(impressions) ELSE 0 END as avg_position')
            ->groupBy('query')
            ->orderByDesc('impressions')
            ->limit(25)
            ->get()
            ->map(function ($row) {
                $clicks = (int) $row->clicks;
                $impressions = (int) $row->impressions;
                $avgPos = round((float) $row->avg_position, 1);
                return [
                    'query' => (string) $row->query,
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                    'avg_position' => $avgPos,
                    'estimated_page' => max(1, (int) ceil($avgPos / 10)),
                ];
            })
            ->values()
            ->toArray();

        $recentChecks = CompetitorSerpSnapshot::where('website_id', $website->id)
            ->orderByDesc('captured_at')
            ->limit(25)
            ->get([
                'id',
                'keyword',
                'country',
                'language',
                'device',
                'location',
                'target_position',
                'is_monitored',
                'data_source',
                'serp_features',
                'captured_at',
            ])
            ->map(function (CompetitorSerpSnapshot $s) {
                return [
                    'id' => $s->id,
                    'keyword' => $s->keyword,
                    'country' => $s->country,
                    'country_name' => CountryCatalog::name($s->country),
                    'language' => $s->language,
                    'device' => $s->device,
                    'location' => $s->location,
                    'target_position' => $s->target_position,
                    'google_page' => $s->target_position ? (int) ceil($s->target_position / 10) : null,
                    'rank_on_page' => $s->target_position ? ((($s->target_position - 1) % 10) + 1) : null,
                    'is_monitored' => (bool) $s->is_monitored,
                    'data_source' => $s->data_source,
                    'serp_features' => $s->serp_features ?? [],
                    'captured_at' => $s->captured_at?->toIso8601String(),
                ];
            })
            ->values()
            ->toArray();

        $serpProvider = $this->providerManager->resolveForCapability('serp', $website->organization_id);
        $envSerperActive = SerperProvider::resolveEnvApiKey() !== '';
        $workspaceSerper = SerperProvider::resolveWorkspaceIntegration($website->organization_id);

        $providerSource = 'unconfigured';
        $keyHint = null;
        if ($serpProvider->getName() === 'serper') {
            if ($envSerperActive) {
                $providerSource = 'server_env';
            } elseif ($workspaceSerper) {
                $providerSource = 'workspace_key';
                $keyHint = $workspaceSerper->metadata['key_hint'] ?? null;
            }
        } elseif ($serpProvider->getName() !== 'first_party_crawl') {
            $providerSource = 'server_env';
        }

        return [
            'website' => [
                'id' => $website->id,
                'name' => $website->name,
                'domain' => CompetitorDomain::normalizeDomain($website->domain),
                'default_country' => strtoupper($website->target_country ?: 'US'),
                'default_language' => strtolower($website->preferred_language ?: 'en'),
            ],
            'provider' => [
                'name' => $serpProvider->getName(),
                'label' => $serpProvider->getLabel(),
                'configured' => $serpProvider->getName() !== 'first_party_crawl',
                'source' => $providerSource,
                'key_hint' => $keyHint,
                'has_workspace_key' => $workspaceSerper !== null,
            ],
            'gsc_top_keywords' => $gscKeywords,
            'recent_checks' => $recentChecks,
        ];
    }

    /**
     * Validate and store an encrypted workspace-wide Serper.dev API key.
     */
    public function saveSerperKey(Website $website, string $apiKey): array
    {
        $cleanKey = trim($apiKey);
        $serper = new SerperProvider();
        $check = $serper->verifyApiKey($cleanKey);

        if (!($check['valid'] ?? false)) {
            return [
                'saved' => false,
                'error' => $check['error'] ?? 'Could not verify Serper.dev API key.',
            ];
        }

        $hint = strlen($cleanKey) >= 4 ? '••••' . substr($cleanKey, -4) : '••••';

        WebsiteIntegration::updateOrCreate(
            [
                'website_id' => $website->id,
                'provider' => WebsiteIntegration::PROVIDER_SERPER,
            ],
            [
                'organization_id' => $website->organization_id,
                'encrypted_credentials' => ['api_key' => $cleanKey],
                'status' => WebsiteIntegration::STATUS_CONNECTED,
                'last_error' => null,
                'last_synced_at' => now(),
                'metadata' => [
                    'key_hint' => $hint,
                    'provider_label' => 'Serper.dev (Google Search API)',
                    'verified_at' => now()->toIso8601String(),
                ],
            ]
        );

        return [
            'saved' => true,
            'provider' => 'serper',
            'label' => 'Serper.dev (Google Search API)',
            'source' => 'workspace_key',
            'key_hint' => $hint,
        ];
    }

    /**
     * Remove saved workspace Serper.dev API key(s).
     */
    public function removeSerperKey(Website $website): void
    {
        WebsiteIntegration::where('organization_id', $website->organization_id)
            ->where('provider', WebsiteIntegration::PROVIDER_SERPER)
            ->delete();
    }

    /**
     * Execute a live geo-located Google search rank check up to Top 100 (Pages 1-10)
     * and compare against 28-day Google Search Console Avg Position.
     */
    public function checkRank(
        Website $website,
        string $keyword,
        string $country = 'US',
        string $language = 'en',
        string $device = 'desktop',
        ?string $location = null,
        int $depth = 100,
        bool $monitor = false,
        bool $forceRefresh = true,
        bool $stopWhenFound = true
    ): array {
        $keyword = trim($keyword);
        $country = CountryCatalog::normalizeCodes([$country], $website->target_country ?: 'US', 1)[0];
        $countryName = CountryCatalog::name($country);
        $language = strtolower(trim($language)) ?: 'en';
        $device = strtolower(trim($device)) === 'mobile' ? 'mobile' : 'desktop';
        $cleanLocation = trim((string) ($location ?? ''));
        $effectiveLocation = $cleanLocation !== '' ? $cleanLocation : $countryName;
        $depth = in_array($depth, [20, 30, 50, 100], true) ? $depth : 100;
        $targetDomain = CompetitorDomain::normalizeDomain($website->domain);

        $provider = $this->providerManager->resolveForCapability('serp', $website->organization_id);
        if ($provider->getName() === 'first_party_crawl') {
            return [
                'available' => false,
                'provider' => 'unconfigured',
                'error' => 'Live Google SERP provider is not connected yet. Paste your free Serper.dev API key right inside the "Connect Live Google Search Engine" box above (instant, no redeploy needed), or attach SERPER_API_KEY to your Vercel Backend service.',
            ];
        }

        $stopDomainArg = $stopWhenFound ? $targetDomain : null;
        $cacheKey = "{$provider->getName()}:rankcheck:{$keyword}:{$country}:{$language}:{$device}:{$effectiveLocation}:{$depth}:" . ($stopWhenFound ? 'auto' : 'full');
        $res = $this->providerManager->cachedCall(
            $website,
            'serp',
            $cacheKey,
            "rank_check:{$keyword}:{$country}",
            function () use ($provider, $keyword, $country, $language, $device, $effectiveLocation, $depth, $stopDomainArg) {
                if ($provider instanceof SerperProvider) {
                    return $provider->fetchSerp($keyword, $country, $language, $device, $effectiveLocation, $depth, $stopDomainArg);
                }
                return $provider->fetchSerp($keyword, $country, $language, $device, $effectiveLocation);
            },
            $forceRefresh
        );

        if (!($res['available'] ?? false)) {
            return [
                'available' => false,
                'provider' => $provider->getName(),
                'error' => $res['error'] ?? 'Live SERP provider request failed.',
            ];
        }

        $websiteNameLower = strtolower(trim((string) $website->name));
        $competitors = CompetitorDomain::where('website_id', $website->id)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (CompetitorDomain $c) => strtolower($c->domain));

        $targetPosition = null;
        $targetMatches = [];
        $competitorPresence = [];
        $annotatedResults = [];
        $pagesSummary = [];

        foreach ($res['organic_results'] ?? [] as $idx => $row) {
            $domain = CompetitorDomain::normalizeDomain((string) ($row['domain'] ?? ($row['url'] ?? '')));
            $pos = (int) ($row['position'] ?? ($idx + 1));
            $googlePage = (int) ($row['google_page'] ?? ceil($pos / 10));
            $rankOnPage = (int) ($row['rank_on_page'] ?? ((($pos - 1) % 10) + 1));

            $isTarget = $domain !== '' && ($domain === $targetDomain || str_ends_with($domain, '.' . $targetDomain));
            $compModel = $competitors->get($domain);

            $entry = [
                'position' => $pos,
                'google_page' => $googlePage,
                'rank_on_page' => $rankOnPage,
                'domain' => $domain,
                'url' => (string) ($row['url'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'snippet' => (string) ($row['snippet'] ?? ''),
                'date' => $row['date'] ?? null,
                'sitelinks' => $row['sitelinks'] ?? [],
                'is_target' => $isTarget,
                'is_competitor' => $compModel !== null,
                'competitor_category' => $compModel?->category,
            ];
            $annotatedResults[] = $entry;

            if (!isset($pagesSummary[$googlePage])) {
                $pagesSummary[$googlePage] = [
                    'page' => $googlePage,
                    'range_start' => $pos,
                    'range_end' => $pos,
                    'results_count' => 0,
                    'has_target' => false,
                    'target_positions' => [],
                ];
            } else {
                $pagesSummary[$googlePage]['range_end'] = max($pagesSummary[$googlePage]['range_end'], $pos);
            }
            $pagesSummary[$googlePage]['results_count']++;

            if ($isTarget) {
                if ($targetPosition === null) {
                    $targetPosition = $pos;
                }
                $targetMatches[] = $entry;
                $pagesSummary[$googlePage]['has_target'] = true;
                $pagesSummary[$googlePage]['target_positions'][] = $pos;
            }
            if ($compModel && !isset($competitorPresence[$domain])) {
                $competitorPresence[$domain] = $pos;
            }
        }

        // Inspect Google Maps Local Pack for target presence
        $localPack = [];
        $localPackMatch = null;
        foreach ($res['local_pack'] ?? [] as $pl) {
            $plWebsite = CompetitorDomain::normalizeDomain((string) ($pl['website'] ?? ''));
            $plTitleLower = strtolower((string) ($pl['title'] ?? ''));
            $isTargetPlace = ($plWebsite !== '' && ($plWebsite === $targetDomain || str_ends_with($plWebsite, '.' . $targetDomain)))
                || ($websiteNameLower !== '' && strlen($websiteNameLower) >= 5 && str_contains($plTitleLower, $websiteNameLower));
            $pl['is_target'] = $isTargetPlace;
            $localPack[] = $pl;
            if ($isTargetPlace && $localPackMatch === null) {
                $localPackMatch = $pl;
            }
        }

        // Cross-check with verified 28-day Google Search Console Avg Position for this exact keyword
        $checkedCount = max(count($annotatedResults), 10);
        $gscComparison = $this->buildGscComparison($website, $keyword, $targetPosition, $countryName, $effectiveLocation, $checkedCount);

        // Persist snapshot in competitor_serp_snapshots for rank history & delta tracking
        $extras = [
            'depth_checked' => $depth,
            'results_returned' => count($annotatedResults),
            'local_pack' => $localPack,
            'people_also_ask' => $res['people_also_ask'] ?? [],
            'related_searches' => $res['related_searches'] ?? [],
            'answer_box' => $res['answer_box'] ?? null,
            'gsc_comparison' => $gscComparison,
        ];

        $snapshot = CompetitorSerpSnapshot::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'keyword' => $keyword,
            'country' => $country,
            'language' => $language,
            'search_engine' => 'google',
            'device' => $device,
            'location' => $effectiveLocation,
            'is_monitored' => $monitor,
            'search_intent' => $this->inferIntent($keyword),
            'serp_features' => $res['serp_features'] ?? [],
            'organic_results' => $annotatedResults,
            'target_position' => $targetPosition,
            'competitor_presence' => $competitorPresence,
            'content_patterns' => $extras,
            'data_source' => $provider->getName(),
            'captured_at' => now(),
        ]);

        // Compare against previous rank check for same keyword + country + device
        $previous = CompetitorSerpSnapshot::where('website_id', $website->id)
            ->where('keyword', $keyword)
            ->where('country', $country)
            ->where('device', $device)
            ->where('id', '<', $snapshot->id)
            ->orderByDesc('captured_at')
            ->first();

        $delta = null;
        if ($previous && $previous->target_position !== null && $targetPosition !== null) {
            $delta = $previous->target_position - $targetPosition; // positive = moved UP
        }

        $googleVerifyUrl = 'https://www.google.com/search?' . http_build_query([
            'q' => $keyword,
            'gl' => CountryCatalog::gl($country),
            'hl' => $language,
            'pws' => '0',
        ]);

        $primaryMatch = $targetMatches[0] ?? null;
        $targetGooglePage = $primaryMatch ? (int) $primaryMatch['google_page'] : ($targetPosition ? (int) ceil($targetPosition / 10) : null);
        $targetRankOnPage = $primaryMatch ? (int) $primaryMatch['rank_on_page'] : ($targetPosition ? ((($targetPosition - 1) % 10) + 1) : null);

        return [
            'available' => true,
            'snapshot_id' => $snapshot->id,
            'keyword' => $keyword,
            'country' => $country,
            'country_name' => $countryName,
            'language' => $language,
            'device' => $device,
            'location' => $effectiveLocation,
            'depth_checked' => $depth,
            'results_returned' => count($annotatedResults),
            'provider' => $provider->getName(),
            'provider_label' => $provider->getLabel(),
            'from_cache' => (bool) ($res['from_cache'] ?? false),
            'captured_at' => $snapshot->captured_at?->toIso8601String(),
            'google_verify_url' => $googleVerifyUrl,
            'target_domain' => $targetDomain,
            'target_ranking' => [
                'found' => $targetPosition !== null,
                'position' => $targetPosition,
                'google_page' => $targetGooglePage,
                'rank_on_page' => $targetRankOnPage,
                'primary_match' => $primaryMatch,
                'all_matches' => $targetMatches,
                'in_local_pack' => $localPackMatch !== null,
                'local_pack_position' => $localPackMatch['position'] ?? null,
                'previous_position' => $previous?->target_position,
                'position_delta' => $delta,
            ],
            'gsc_comparison' => $gscComparison,
            'serp_features' => $res['serp_features'] ?? [],
            'pages_summary' => array_values($pagesSummary),
            'organic_results' => $annotatedResults,
            'local_pack' => $localPack,
            'people_also_ask' => $res['people_also_ask'] ?? [],
            'related_searches' => $res['related_searches'] ?? [],
            'answer_box' => $res['answer_box'] ?? null,
        ];
    }

    /**
     * Reload a stored snapshot into the exact same response format without spending API credits.
     */
    public function formatStoredSnapshot(Website $website, CompetitorSerpSnapshot $snapshot): array
    {
        $targetDomain = CompetitorDomain::normalizeDomain($website->domain);
        $countryName = CountryCatalog::name($snapshot->country);
        $organic = is_array($snapshot->organic_results) ? $snapshot->organic_results : [];
        $extras = is_array($snapshot->content_patterns) ? $snapshot->content_patterns : [];

        $targetMatches = [];
        $pagesSummary = [];
        foreach ($organic as $idx => &$row) {
            $pos = (int) ($row['position'] ?? ($idx + 1));
            $gp = (int) ($row['google_page'] ?? ceil($pos / 10));
            $rop = (int) ($row['rank_on_page'] ?? ((($pos - 1) % 10) + 1));
            $row['google_page'] = $gp;
            $row['rank_on_page'] = $rop;

            if (!isset($pagesSummary[$gp])) {
                $pagesSummary[$gp] = [
                    'page' => $gp,
                    'range_start' => (($gp - 1) * 10) + 1,
                    'range_end' => $gp * 10,
                    'results_count' => 0,
                    'has_target' => false,
                    'target_positions' => [],
                ];
            }
            $pagesSummary[$gp]['results_count']++;
            if (!empty($row['is_target'])) {
                $targetMatches[] = $row;
                $pagesSummary[$gp]['has_target'] = true;
                $pagesSummary[$gp]['target_positions'][] = $pos;
            }
        }
        unset($row);

        $localPack = is_array($extras['local_pack'] ?? null) ? $extras['local_pack'] : [];
        $localPackMatch = collect($localPack)->firstWhere('is_target', true);

        $gscComparison = $extras['gsc_comparison'] ?? $this->buildGscComparison(
            $website,
            $snapshot->keyword,
            $snapshot->target_position,
            $countryName,
            $snapshot->location ?: $countryName,
            (int) ($extras['depth_checked'] ?? max(20, count($organic)))
        );

        return [
            'available' => true,
            'snapshot_id' => $snapshot->id,
            'keyword' => $snapshot->keyword,
            'country' => $snapshot->country,
            'country_name' => $countryName,
            'language' => $snapshot->language,
            'device' => $snapshot->device,
            'location' => $snapshot->location ?: $countryName,
            'depth_checked' => (int) ($extras['depth_checked'] ?? count($organic)),
            'results_returned' => count($organic),
            'provider' => $snapshot->data_source,
            'provider_label' => $snapshot->data_source,
            'from_cache' => true,
            'captured_at' => $snapshot->captured_at?->toIso8601String(),
            'google_verify_url' => 'https://www.google.com/search?' . http_build_query([
                'q' => $snapshot->keyword,
                'gl' => CountryCatalog::gl($snapshot->country),
                'hl' => $snapshot->language,
                'pws' => '0',
            ]),
            'target_domain' => $targetDomain,
            'target_ranking' => [
                'found' => $snapshot->target_position !== null,
                'position' => $snapshot->target_position,
                'google_page' => $snapshot->target_position ? (int) ceil($snapshot->target_position / 10) : null,
                'rank_on_page' => $snapshot->target_position ? ((($snapshot->target_position - 1) % 10) + 1) : null,
                'primary_match' => $targetMatches[0] ?? null,
                'all_matches' => $targetMatches,
                'in_local_pack' => $localPackMatch !== null,
                'local_pack_position' => $localPackMatch['position'] ?? null,
                'previous_position' => null,
                'position_delta' => null,
            ],
            'gsc_comparison' => $gscComparison,
            'serp_features' => $snapshot->serp_features ?? [],
            'pages_summary' => array_values($pagesSummary),
            'organic_results' => $organic,
            'local_pack' => $localPack,
            'people_also_ask' => $extras['people_also_ask'] ?? [],
            'related_searches' => $extras['related_searches'] ?? [],
            'answer_box' => $extras['answer_box'] ?? null,
        ];
    }

    private function buildGscComparison(
        Website $website,
        string $keyword,
        ?int $livePos,
        string $countryName,
        string $locationLabel,
        int $depth
    ): array {
        $row = SearchPerformanceRecord::where('website_id', $website->id)
            ->where('date', '>=', now()->subDays(31)->toDateString())
            ->whereRaw('LOWER(query) = ?', [strtolower($keyword)])
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions * 1.0) / SUM(impressions) ELSE 0 END as avg_pos, MAX(page) as top_url')
            ->first();

        if (!$row || (int) $row->impressions === 0) {
            return [
                'has_gsc_data' => false,
                'gsc_avg_position' => null,
                'gsc_clicks' => 0,
                'gsc_impressions' => 0,
                'gsc_ctr' => 0.0,
                'gsc_top_url' => null,
                'delta_vs_gsc' => null,
                'verdict' => 'No 28-day Google Search Console impressions recorded for this exact query yet.',
            ];
        }

        $gscAvg = round((float) $row->avg_pos, 1);
        $clicks = (int) $row->clicks;
        $impressions = (int) $row->impressions;
        $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0;

        $delta = null;
        if ($livePos !== null) {
            $delta = round($gscAvg - $livePos, 1); // positive = live position is better (lower number)
            $livePage = (int) ceil($livePos / 10);
            if (abs($delta) <= 0.6) {
                $verdict = "Verified Match! Live Google rank in {$locationLabel} (#{$livePos}, Page {$livePage}) closely matches your 28-day GSC average (#{$gscAvg}).";
            } elseif ($delta > 0) {
                $verdict = "Stronger Live! In {$locationLabel}, you rank #{$livePos} (Page {$livePage}) — {$delta} positions higher than your 28-day GSC average (#{$gscAvg}).";
            } else {
                $behind = abs($delta);
                $verdict = "In {$locationLabel}, you currently rank #{$livePos} (Page {$livePage}) — {$behind} positions below your 28-day GSC average (#{$gscAvg}). GSC averages all regions/devices over 28 days.";
            }
        } else {
            $verdict = "GSC reports a 28-day average position of #{$gscAvg}, but your site was not found in the Top {$depth} Google results for {$locationLabel} right now (may rank deeper or in a different city/device).";
        }

        return [
            'has_gsc_data' => true,
            'gsc_avg_position' => $gscAvg,
            'gsc_clicks' => $clicks,
            'gsc_impressions' => $impressions,
            'gsc_ctr' => $ctr,
            'gsc_top_url' => $row->top_url,
            'delta_vs_gsc' => $delta,
            'verdict' => $verdict,
        ];
    }

    private function inferIntent(string $keyword): string
    {
        if (preg_match('/\b(repair|fix|service|hire|book|buy|price|cost|near me|plumber|handyman|agency)\b/i', $keyword)) {
            return 'transactional';
        }
        if (preg_match('/\b(best|top|review|vs|compare)\b/i', $keyword)) {
            return 'commercial';
        }
        return 'informational';
    }
}
