<?php

namespace App\Domain\Competitors\Providers;

use App\Domain\Competitors\Contracts\CompetitorProviderInterface;
use App\Domain\Competitors\Support\CountryCatalog;
use App\Domain\Crawler\PageInspector;
use App\Domain\Crawler\UrlSecurityValidator;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Always-available verified first-party public HTML & sitemap signal inspector.
 * NEVER fabricates third-party metrics (traffic estimates, search volume, KD, DR, or backlinks).
 * All unsupported third-party metrics remain strictly null.
 */
class FirstPartyCrawlProvider implements CompetitorProviderInterface
{
    public function getName(): string
    {
        return 'first_party_crawl';
    }

    public function getLabel(): string
    {
        return 'Web SEO AI First-Party Crawler (Verified HTML Signals)';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportedCapabilities(): array
    {
        return ['domain_metrics', 'discovery'];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->supportedCapabilities(), true);
    }

    public function discoverCompetitors(string $domain, string $country = 'US', string $language = 'en', array $context = []): array
    {
        $seedQueries = array_values(array_unique(array_filter(array_map('trim', $context['seed_keywords'] ?? []))));
        if (empty($seedQueries)) {
            $fallback = trim((string) ($context['industry'] ?? ''));
            if ($fallback === '') {
                $fallback = str_replace(['-', '_', '.'], ' ', pathinfo($domain, PATHINFO_FILENAME));
            }
            $seedQueries = [$fallback];
        }

        // Limit to top 3 focused queries so public search remains fast & respectful
        $queriesToRun = array_slice($seedQueries, 0, 3);
        $targetNorm = preg_replace('/^www\./i', '', strtolower($domain));
        $domainStats = [];
        $queriesSucceeded = [];
        $rateLimited = false;

        foreach ($queriesToRun as $idx => $query) {
            if ($idx > 0) {
                usleep(450000); // Gentle 450ms pacing between public search queries
            }

            $parsed = $this->searchPublicDuckDuckGo($query, $country);
            if ($parsed['rate_limited']) {
                $rateLimited = true;
                break; // Respect rate limits immediately — never attempt to bypass challenges
            }

            if (!empty($parsed['results'])) {
                $queriesSucceeded[] = $query;
            }

            foreach ($parsed['results'] as $pos => $item) {
                $candDomain = preg_replace('/^www\./i', '', strtolower((string) ($item['domain'] ?? '')));
                if ($candDomain === '' || $candDomain === $targetNorm || !str_contains($candDomain, '.') || str_ends_with($candDomain, '.' . $targetNorm)) {
                    continue;
                }

                if (!isset($domainStats[$candDomain])) {
                    $domainStats[$candDomain] = [
                        'domain' => $candDomain,
                        'shared_keywords' => 0,
                        'matched_queries' => [],
                        'positions' => [],
                        'sample_urls' => [],
                        'sample_title' => $item['title'] ?? null,
                        'sample_snippet' => $item['snippet'] ?? null,
                        'source' => 'public_web_serp',
                    ];
                }

                if (!in_array($query, $domainStats[$candDomain]['matched_queries'], true)) {
                    $domainStats[$candDomain]['matched_queries'][] = $query;
                    $domainStats[$candDomain]['shared_keywords']++;
                }
                $domainStats[$candDomain]['positions'][] = $pos + 1;
                if (count($domainStats[$candDomain]['sample_urls']) < 3 && !empty($item['url']) && !in_array($item['url'], $domainStats[$candDomain]['sample_urls'], true)) {
                    $domainStats[$candDomain]['sample_urls'][] = $item['url'];
                }
            }
        }

        // Fallback 1: If DuckDuckGo returned 0 results and GEMINI_API_KEY is configured, use official Gemini Google Search Grounding
        if (empty($domainStats) && !empty($queriesToRun) && trim((string) config('ai.gemini.api_key', '')) !== '') {
            $geminiItems = $this->searchViaGeminiGoogleGrounding($domain, $queriesToRun, $country, $language);
            if (!empty($geminiItems)) {
                $queriesSucceeded = $queriesToRun;
            }
            foreach ($geminiItems as $pos => $item) {
                $candDomain = preg_replace('/^www\./i', '', strtolower((string) ($item['domain'] ?? '')));
                if ($candDomain === '' || $candDomain === $targetNorm || !str_contains($candDomain, '.') || str_ends_with($candDomain, '.' . $targetNorm)) {
                    continue;
                }
                $qMatched = trim((string) ($item['matched_query'] ?? $queriesToRun[0]));
                if (!isset($domainStats[$candDomain])) {
                    $domainStats[$candDomain] = [
                        'domain' => $candDomain,
                        'shared_keywords' => 0,
                        'matched_queries' => [],
                        'positions' => [],
                        'sample_urls' => [],
                        'sample_title' => $item['title'] ?? null,
                        'sample_snippet' => $item['snippet'] ?? null,
                        'source' => 'gemini_google_search',
                    ];
                }
                if (!in_array($qMatched, $domainStats[$candDomain]['matched_queries'], true)) {
                    $domainStats[$candDomain]['matched_queries'][] = $qMatched;
                    $domainStats[$candDomain]['shared_keywords']++;
                }
                $domainStats[$candDomain]['positions'][] = $pos + 1;
                if (count($domainStats[$candDomain]['sample_urls']) < 3 && !empty($item['url']) && !in_array($item['url'], $domainStats[$candDomain]['sample_urls'], true)) {
                    $domainStats[$candDomain]['sample_urls'][] = $item['url'];
                }
            }
        }

        // Fallback 2: Public Bing RSS feed if DuckDuckGo and Gemini returned 0 results
        if (empty($domainStats) && !empty($queriesToRun)) {
            foreach (array_slice($queriesToRun, 0, 2) as $query) {
                $bingItems = $this->searchPublicBingRss($query);
                if (!empty($bingItems)) {
                    $queriesSucceeded[] = $query;
                }
                foreach ($bingItems as $pos => $item) {
                    $candDomain = preg_replace('/^www\./i', '', strtolower((string) ($item['domain'] ?? '')));
                    if ($candDomain === '' || $candDomain === $targetNorm || !str_contains($candDomain, '.') || str_contains($candDomain, 'bing.com') || str_contains($candDomain, 'microsoft.com')) {
                        continue;
                    }
                    if (!isset($domainStats[$candDomain])) {
                        $domainStats[$candDomain] = [
                            'domain' => $candDomain,
                            'shared_keywords' => 0,
                            'matched_queries' => [],
                            'positions' => [],
                            'sample_urls' => [],
                            'sample_title' => $item['title'] ?? null,
                            'sample_snippet' => $item['snippet'] ?? null,
                            'source' => 'public_bing_rss',
                        ];
                    }
                    if (!in_array($query, $domainStats[$candDomain]['matched_queries'], true)) {
                        $domainStats[$candDomain]['matched_queries'][] = $query;
                        $domainStats[$candDomain]['shared_keywords']++;
                    }
                    $domainStats[$candDomain]['positions'][] = $pos + 1;
                    if (count($domainStats[$candDomain]['sample_urls']) < 3 && !empty($item['url'])) {
                        $domainStats[$candDomain]['sample_urls'][] = $item['url'];
                    }
                }
            }
        }

        $totalQueries = max(1, count($queriesSucceeded) ?: count($queriesToRun));
        $candidates = [];
        foreach ($domainStats as $stat) {
            $avgPos = round(array_sum($stat['positions']) / max(1, count($stat['positions'])), 1);
            $candidates[] = [
                'domain' => $stat['domain'],
                'shared_keywords' => $stat['shared_keywords'],
                'matched_queries' => $stat['matched_queries'],
                'avg_position' => $avgPos,
                'serp_overlap_pct' => round(min(100, ($stat['shared_keywords'] / $totalQueries) * 100), 1),
                'sample_title' => $stat['sample_title'],
                'sample_snippet' => $stat['sample_snippet'],
                'sample_urls' => $stat['sample_urls'],
                'source' => $stat['source'],
            ];
        }

        usort($candidates, fn ($a, $b) => ($b['shared_keywords'] <=> $a['shared_keywords']) ?: ($a['avg_position'] <=> $b['avg_position']));

        $queryListStr = implode(' | ', $queriesToRun);
        $methodology = !empty($candidates)
            ? 'Built-in Public Web Search Discovery analyzed ' . count($queriesToRun) . " target-derived commercial & service queries ({$country}/{$language}): \"{$queryListStr}\" with automatic portal/directory filtering."
            : ($rateLimited
                ? "Public search endpoint temporarily rate-limited this server IP for queries ({$queryListStr}). Add a free SERPER_API_KEY (2,500 free Google searches) or DATAFORSEO credentials in Vercel Env Vars for guaranteed uninterrupted discovery."
                : "Analyzed public web results for ({$queryListStr}) but no competing domains were returned. Try adding a specific Industry/Niche phrase or connecting SERPER_API_KEY / DATAFORSEO.");

        return [
            'available' => !empty($candidates),
            'provider' => $this->getName(),
            'methodology' => $methodology,
            'candidates' => array_slice($candidates, 0, 20),
            'queries_used' => $queriesToRun,
            'error' => empty($candidates) && $rateLimited ? 'Public search rate-limited on shared cloud IP; configure SERPER_API_KEY (free tier) or DATAFORSEO for unlimited discovery.' : null,
            'cost_usd' => 0.0,
        ];
    }

    /**
     * Respectful public DuckDuckGo HTML search parser (never bypasses challenges/CAPTCHAs).
     */
    private function searchPublicDuckDuckGo(string $query, string $country): array
    {
        $kl = CountryCatalog::ddgKl($country);

        try {
            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->timeout(10)->get('https://html.duckduckgo.com/html/', [
                'q' => $query,
                'kl' => $kl,
            ]);

            // HTTP 202 or challenge markup means rate-limited; stop respectfully
            $body = $res->body();
            if ($res->status() !== 200 || str_contains(strtolower($body), 'anomaly-modal') || str_contains(strtolower($body), 'bots use duckduckgo')) {
                return ['rate_limited' => true, 'results' => []];
            }

            preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', $body, $linkMatches, PREG_SET_ORDER);
            preg_match_all('/class="result__snippet"[^>]*>(.*?)<\/a>/is', $body, $snipMatches);

            $results = [];
            foreach ($linkMatches as $i => $match) {
                $rawHref = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
                $realUrl = $rawHref;
                if (preg_match('/uddg=([^&]+)/', $rawHref, $uMatch)) {
                    $realUrl = urldecode($uMatch[1]);
                }
                if (!preg_match('#^https?://#i', $realUrl)) {
                    continue;
                }
                $host = preg_replace('/^www\./i', '', strtolower((string) parse_url($realUrl, PHP_URL_HOST)));
                if ($host === '' || str_contains($host, 'duckduckgo.com')) {
                    continue;
                }

                $title = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5));
                $snippet = isset($snipMatches[1][$i])
                    ? trim(html_entity_decode(strip_tags($snipMatches[1][$i]), ENT_QUOTES | ENT_HTML5))
                    : '';

                $results[] = [
                    'domain' => $host,
                    'url' => $realUrl,
                    'title' => $title,
                    'snippet' => $snippet,
                ];
            }

            return ['rate_limited' => false, 'results' => $results];
        } catch (Throwable) {
            return ['rate_limited' => false, 'results' => []];
        }
    }

    /**
     * Official Gemini API with Google Search Grounding (tools: [{google_search: {}}]).
     * Returns only real websites discovered through live Google Search grounding.
     */
    private function searchViaGeminiGoogleGrounding(string $targetDomain, array $queries, string $country, string $language): array
    {
        $apiKey = trim((string) config('ai.gemini.api_key', ''));
        if ($apiKey === '') {
            return [];
        }

        $model = trim((string) config('ai.gemini.model', 'gemini-2.5-flash'));
        if ($model === '' || str_contains($model, '3.8')) {
            $model = 'gemini-2.5-flash';
        }

        $queryLines = implode("\n", array_map(fn ($q) => "- {$q}", $queries));
        $prompt = "Use Google Search to find real competing websites (excluding {$targetDomain}) that rank in {$country} ({$language}) for these commercial search queries:\n{$queryLines}\n\n"
            . "Return ONLY a valid JSON array (no markdown fences) of up to 12 real websites found in Google Search results. Each object MUST have: "
            . "{\"domain\": \"example.com\", \"url\": \"https://example.com/...\", \"title\": \"Exact page title\", \"snippet\": \"Brief 1-sentence description of their offering\", \"matched_query\": \"which query matched\"}. "
            . "Only include real websites verified from Google Search results.";

        try {
            $res = Http::timeout(20)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'tools' => [
                        ['google_search' => new \stdClass()],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.1,
                    ],
                ]
            );

            if (!$res->successful()) {
                return [];
            }

            $data = $res->json();
            $results = [];
            $seenDomains = [];

            // Parse grounded JSON response
            $text = (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
            if (preg_match('/\[[\s\S]*\]/', $text, $jsonMatch)) {
                $decoded = json_decode($jsonMatch[0], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $rawDomain = trim((string) ($row['domain'] ?? ''));
                        $rawUrl = trim((string) ($row['url'] ?? ''));
                        if ($rawDomain === '' && $rawUrl !== '') {
                            $rawDomain = (string) parse_url($rawUrl, PHP_URL_HOST);
                        }
                        $cleanDomain = preg_replace('/^www\./i', '', strtolower($rawDomain));
                        if ($cleanDomain === '' || !str_contains($cleanDomain, '.') || isset($seenDomains[$cleanDomain])) {
                            continue;
                        }
                        $seenDomains[$cleanDomain] = true;
                        $results[] = [
                            'domain' => $cleanDomain,
                            'url' => preg_match('#^https?://#i', $rawUrl) ? $rawUrl : "https://{$cleanDomain}",
                            'title' => trim((string) ($row['title'] ?? $cleanDomain)),
                            'snippet' => trim((string) ($row['snippet'] ?? '')),
                            'matched_query' => trim((string) ($row['matched_query'] ?? ($queries[0] ?? ''))),
                        ];
                    }
                }
            }

            // Also include any verified groundingChunks domains not already captured
            $chunks = $data['candidates'][0]['groundingMetadata']['groundingChunks'] ?? [];
            foreach ($chunks as $chunk) {
                $chunkTitle = strtolower(trim((string) ($chunk['web']['title'] ?? '')));
                $chunkUri = trim((string) ($chunk['web']['uri'] ?? ''));
                $candidateDomain = '';
                if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $chunkTitle)) {
                    $candidateDomain = preg_replace('/^www\./i', '', $chunkTitle);
                } elseif (preg_match('#^https?://#i', $chunkUri)) {
                    $host = preg_replace('/^www\./i', '', strtolower((string) parse_url($chunkUri, PHP_URL_HOST)));
                    if ($host !== '' && !str_contains($host, 'vertexaisearch.cloud.google.com')) {
                        $candidateDomain = $host;
                    }
                }
                if ($candidateDomain !== '' && !isset($seenDomains[$candidateDomain])) {
                    $seenDomains[$candidateDomain] = true;
                    $results[] = [
                        'domain' => $candidateDomain,
                        'url' => "https://{$candidateDomain}",
                        'title' => $candidateDomain,
                        'snippet' => 'Verified via Google Search Grounding chunk.',
                        'matched_query' => $queries[0] ?? '',
                    ];
                }
            }

            return $results;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Public Bing RSS search fallback.
     */
    private function searchPublicBingRss(string $query): array
    {
        try {
            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; WebSEOAI-Discovery/1.0)',
                'Accept' => 'application/rss+xml, application/xml, text/xml',
            ])->timeout(8)->get('https://www.bing.com/search', [
                'q' => $query,
                'format' => 'rss',
            ]);

            if (!$res->successful()) {
                return [];
            }

            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($res->body(), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            if (!$xml || !isset($xml->channel->item)) {
                return [];
            }

            $results = [];
            foreach ($xml->channel->item as $item) {
                $link = trim((string) $item->link);
                if (!preg_match('#^https?://#i', $link)) {
                    continue;
                }
                $host = preg_replace('/^www\./i', '', strtolower((string) parse_url($link, PHP_URL_HOST)));
                if ($host === '') {
                    continue;
                }
                $results[] = [
                    'domain' => $host,
                    'url' => $link,
                    'title' => trim((string) $item->title),
                    'snippet' => trim((string) $item->description),
                ];
            }

            return $results;
        } catch (Throwable) {
            return [];
        }
    }

    public function fetchDomainMetrics(string $domain, string $country = 'US', string $language = 'en'): array
    {
        $targetUrl = "https://{$domain}";
        $crawledSignals = [
            'reachable' => false,
            'http_status' => null,
            'page_title' => null,
            'meta_description' => null,
            'h1_tags' => [],
            'word_count' => null,
            'structured_data_types' => [],
            'internal_links_count' => null,
            'load_time_ms' => null,
            'has_https' => true,
            'has_sitemap' => false,
            'sitemap_urls_count' => null,
            'top_discovered_pages' => [],
        ];

        $topPages = [];

        try {
            if (!app()->runningUnitTests()) {
                try {
                    UrlSecurityValidator::validate($targetUrl, $domain);
                } catch (Throwable $valEx) {
                    return [
                        'available' => false,
                        'provider' => $this->getName(),
                        'classification' => 'first_party_verified',
                        'metrics' => $this->emptyMetrics($crawledSignals),
                        'error' => 'Domain blocked by SSRF security policy: ' . $valEx->getMessage(),
                    ];
                }
            }

            $start = microtime(true);
            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->timeout(10)->get($targetUrl);
            $elapsed = (int) round((microtime(true) - $start) * 1000);

            $inspected = PageInspector::inspect($res->body(), $targetUrl, $domain, $res->headers(), $res->status(), $elapsed, $targetUrl);
            $isWaf = (bool) ($inspected['is_blocked_by_waf'] ?? false);

            $headingsTree = is_array($inspected['headings_tree'] ?? null) ? $inspected['headings_tree'] : [];
            $h2Tags = [];
            $h3Count = 0;
            foreach ($headingsTree as $node) {
                $tag = strtolower((string) ($node['tag'] ?? ''));
                $txt = trim((string) ($node['text'] ?? ''));
                if ($tag === 'h2' && $txt !== '') {
                    $h2Tags[] = $txt;
                } elseif ($tag === 'h3') {
                    $h3Count++;
                }
            }

            $imagesCount = (int) ($inspected['images_count'] ?? 0);
            $imagesMissingAlt = (int) ($inspected['images_missing_alt'] ?? 0);
            $altCoveragePct = $imagesCount > 0
                ? (int) round((($imagesCount - $imagesMissingAlt) / $imagesCount) * 100)
                : 100;

            $internalLinks = is_array($inspected['internal_links'] ?? null) ? $inspected['internal_links'] : [];
            $externalLinks = is_array($inspected['external_links'] ?? null) ? $inspected['external_links'] : [];
            $schemaTypes = array_values(array_unique($inspected['structured_data']['types'] ?? []));
            $og = is_array($inspected['open_graph'] ?? null) ? $inspected['open_graph'] : [];
            $tw = is_array($inspected['twitter_card'] ?? null) ? $inspected['twitter_card'] : [];
            $secHeaders = is_array($inspected['security_headers'] ?? null) ? $inspected['security_headers'] : [];
            $secScoreCount = count(array_filter($secHeaders, fn ($v) => $v === true));

            $allLinkBlob = strtolower(implode(' ', array_map(fn ($l) => ($l['url'] ?? '') . ' ' . ($l['text'] ?? ''), $internalLinks)));
            $rawBodyLower = strtolower($res->body());
            $hasBlogSection = (bool) preg_match('#/(blog|articles?|news|guides?|resources|insights)\b#i', $allLinkBlob);
            $hasFaqSignal = in_array('FAQPage', $schemaTypes, true)
                || (bool) preg_match('/\b(faq|frequently asked questions)\b/i', implode(' ', $h2Tags));
            $hasContactCta = str_contains($rawBodyLower, 'tel:')
                || str_contains($rawBodyLower, 'wa.me')
                || str_contains($rawBodyLower, 'whatsapp')
                || str_contains($allLinkBlob, '/contact')
                || str_contains($allLinkBlob, '/book');

            $pageTitle = $isWaf ? null : ($inspected['page_title'] ?? null);
            $metaDesc = $isWaf ? null : ($inspected['meta_description'] ?? null);
            $h1Tags = $isWaf ? [] : ($inspected['h1_tags'] ?? []);
            $wordCount = $isWaf ? null : ($inspected['word_count'] ?? null);

            $crawledSignals['reachable'] = $res->successful() && !$isWaf;
            $crawledSignals['http_status'] = $res->status();
            $crawledSignals['load_time_ms'] = $elapsed;
            $crawledSignals['page_title'] = $pageTitle;
            $crawledSignals['title_length'] = $pageTitle !== null ? mb_strlen($pageTitle) : 0;
            $crawledSignals['meta_description'] = $metaDesc;
            $crawledSignals['meta_description_length'] = $metaDesc !== null ? mb_strlen($metaDesc) : 0;
            $crawledSignals['h1_tags'] = $h1Tags;
            $crawledSignals['h1_count'] = count($h1Tags);
            $crawledSignals['h2_tags'] = array_slice($h2Tags, 0, 10);
            $crawledSignals['h2_count'] = count($h2Tags);
            $crawledSignals['h3_count'] = $h3Count;
            $crawledSignals['word_count'] = $wordCount;
            $crawledSignals['structured_data_types'] = $schemaTypes;
            $crawledSignals['images_count'] = $imagesCount;
            $crawledSignals['images_missing_alt'] = $imagesMissingAlt;
            $crawledSignals['image_alt_coverage_pct'] = $isWaf ? null : $altCoveragePct;
            $crawledSignals['internal_links_count'] = count($internalLinks);
            $crawledSignals['external_links_count'] = count($externalLinks);
            $crawledSignals['has_canonical'] = !empty($inspected['canonical_url']);
            $crawledSignals['canonical_url'] = $inspected['canonical_url'] ?? null;
            $crawledSignals['has_open_graph'] = !empty($og['title']) || !empty($og['description']) || !empty($og['image']);
            $crawledSignals['has_twitter_card'] = !empty($tw['card']) || !empty($tw['title']);
            $crawledSignals['has_viewport'] = !empty($inspected['viewport']);
            $crawledSignals['has_blog_section'] = $hasBlogSection;
            $crawledSignals['has_faq_signal'] = $hasFaqSignal;
            $crawledSignals['has_contact_cta'] = $hasContactCta;
            $crawledSignals['security_headers_count'] = $secScoreCount;
            $crawledSignals['is_blocked_by_waf'] = $isWaf;

            if ($res->successful() && !$isWaf && !empty($pageTitle)) {
                $topPages[] = [
                    'url' => $targetUrl,
                    'title' => $pageTitle,
                    'h1' => $h1Tags[0] ?? null,
                    'word_count' => $wordCount,
                    'estimated_traffic' => null,
                    'source' => 'first_party_crawl',
                ];
            }

            // Collect prominent internal navigation URLs as verified public pages
            if (!$isWaf) {
                foreach (array_slice($internalLinks, 0, 10) as $link) {
                    $linkUrl = (string) ($link['url'] ?? '');
                    $linkText = trim((string) ($link['text'] ?? ''));
                    if ($linkUrl !== '' && $linkUrl !== $targetUrl && !str_contains($linkUrl, '#')) {
                        $topPages[] = [
                            'url' => $linkUrl,
                            'title' => $linkText !== '' ? $linkText : trim((string) parse_url($linkUrl, PHP_URL_PATH), '/'),
                            'estimated_traffic' => null,
                            'source' => 'first_party_crawl',
                        ];
                    }
                }
            }

            // Quick robots/sitemap check to measure public sitemap page count
            try {
                $smRes = Http::withHeaders(['User-Agent' => 'WebSEOAI-CompetitorInspector/1.0'])
                    ->timeout(6)
                    ->get("{$targetUrl}/sitemap.xml");
                if ($smRes->successful() && str_contains(strtolower($smRes->body()), '<loc>')) {
                    $crawledSignals['has_sitemap'] = true;
                    preg_match_all('/<loc>\s*(https?:\/\/[^<\s]+)\s*<\/loc>/i', $smRes->body(), $matches);
                    $locs = array_unique($matches[1] ?? []);
                    $crawledSignals['sitemap_urls_count'] = count($locs);
                    foreach (array_slice($locs, 0, 12) as $loc) {
                        if (!collect($topPages)->contains('url', $loc)) {
                            $topPages[] = [
                                'url' => $loc,
                                'title' => trim((string) parse_url($loc, PHP_URL_PATH), '/') ?: 'Homepage',
                                'estimated_traffic' => null,
                                'source' => 'first_party_sitemap',
                            ];
                        }
                    }
                }
            } catch (Throwable) {
                // Optional sitemap probe
            }

            $crawledSignals['onpage_seo_score'] = self::computeOnPageSeoScore($crawledSignals);
            $crawledSignals['top_discovered_pages'] = array_slice($topPages, 0, 15);

            return [
                'available' => true,
                'provider' => $this->getName(),
                'classification' => 'first_party_verified',
                'http_status' => $res->status(),
                'cost_usd' => 0.0,
                'metrics' => [
                    'organic_traffic_estimate' => null,
                    'organic_keywords_count' => null,
                    'traffic_value_estimate' => null,
                    'referring_domains_count' => null,
                    'backlinks_count' => null,
                    'indexed_pages_count' => $crawledSignals['sitemap_urls_count'],
                    'authority_score' => null,
                    'authority_metric_label' => null,
                    'serp_visibility_score' => null,
                    'ranking_distribution' => null,
                    'top_keywords' => [],
                    'top_pages' => array_slice($topPages, 0, 12),
                    'crawled_signals' => $crawledSignals,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'available' => false,
                'provider' => $this->getName(),
                'classification' => 'first_party_verified',
                'metrics' => $this->emptyMetrics($crawledSignals),
                'error' => 'Could not inspect domain HTML: ' . $e->getMessage(),
                'cost_usd' => 0.0,
            ];
        }
    }

    public function fetchDomainKeywords(string $domain, string $country = 'US', string $language = 'en', int $limit = 50): array
    {
        return [
            'available' => false,
            'provider' => $this->getName(),
            'keywords' => [],
            'error' => 'Third-party keyword provider (DataForSEO or Semrush) is required to retrieve competitor organic keyword rankings and search volume.',
        ];
    }

    public function fetchBacklinks(string $domain, int $limit = 50): array
    {
        return [
            'available' => false,
            'provider' => $this->getName(),
            'summary' => [],
            'backlinks' => [],
            'error' => 'Backlink intelligence provider (DataForSEO Backlinks, Ahrefs API v3, or Semrush) is not configured.',
        ];
    }

    public function fetchSerp(string $keyword, string $country = 'US', string $language = 'en', string $device = 'desktop', ?string $location = null): array
    {
        return [
            'available' => false,
            'provider' => $this->getName(),
            'serp_features' => [],
            'organic_results' => [],
            'error' => 'SERP data provider (DataForSEO SERP API or SerpApi) is not configured.',
        ];
    }

    /**
     * Deterministic First-Party On-Page SEO Score (0-100) computed strictly from verified crawl signals.
     */
    public static function computeOnPageSeoScore(array $signals): ?int
    {
        if (empty($signals['reachable']) && empty($signals['page_title']) && empty($signals['google_indexed_sample_count'])) {
            return null;
        }

        $score = 0;

        // 1. HTTPS (10 pts)
        if (($signals['has_https'] ?? true) === true) {
            $score += 10;
        }

        // 2. Title Optimization (12 pts)
        $titleLen = isset($signals['title_length'])
            ? (int) $signals['title_length']
            : (isset($signals['page_title']) ? mb_strlen((string) $signals['page_title']) : 0);
        if ($titleLen >= 25 && $titleLen <= 68) {
            $score += 12;
        } elseif ($titleLen > 0) {
            $score += 6;
        }

        // 3. Meta Description Optimization (10 pts)
        $descLen = isset($signals['meta_description_length'])
            ? (int) $signals['meta_description_length']
            : (isset($signals['meta_description']) ? mb_strlen((string) $signals['meta_description']) : 0);
        if ($descLen >= 70 && $descLen <= 170) {
            $score += 10;
        } elseif ($descLen > 0) {
            $score += 5;
        }

        // 4. H1 Heading (10 pts)
        $h1Count = isset($signals['h1_count']) ? (int) $signals['h1_count'] : count($signals['h1_tags'] ?? []);
        if ($h1Count === 1) {
            $score += 10;
        } elseif ($h1Count > 1) {
            $score += 6;
        }

        // 5. H2 Content Structure (8 pts)
        $h2Count = (int) ($signals['h2_count'] ?? 0);
        if ($h2Count >= 3) {
            $score += 8;
        } elseif ($h2Count >= 1) {
            $score += 4;
        }

        // 6. Content Depth / Word Count (15 pts)
        $words = (int) ($signals['word_count'] ?? 0);
        if ($words >= 700) {
            $score += 15;
        } elseif ($words >= 350) {
            $score += 10;
        } elseif ($words >= 150) {
            $score += 5;
        }

        // 7. Structured Data / Schema.org (12 pts)
        $schemaCount = count($signals['structured_data_types'] ?? []);
        if ($schemaCount >= 2) {
            $score += 12;
        } elseif ($schemaCount === 1) {
            $score += 7;
        }

        // 8. Sitemap or Indexed Coverage (8 pts)
        if (!empty($signals['has_sitemap']) || (int) ($signals['sitemap_urls_count'] ?? 0) > 0 || (int) ($signals['google_indexed_sample_count'] ?? 0) >= 5) {
            $score += 8;
        }

        // 9. Image Alt Coverage (5 pts)
        $altPct = $signals['image_alt_coverage_pct'] ?? null;
        if ($altPct !== null) {
            if ($altPct >= 85) {
                $score += 5;
            } elseif ($altPct >= 50) {
                $score += 2;
            }
        }

        // 10. Canonical + OpenGraph + Viewport + Fast Load (10 pts)
        if (!empty($signals['has_canonical'])) {
            $score += 3;
        }
        if (!empty($signals['has_open_graph'])) {
            $score += 3;
        }
        if (!empty($signals['has_viewport'])) {
            $score += 2;
        }
        $loadMs = $signals['load_time_ms'] ?? null;
        if ($loadMs !== null && $loadMs > 0 && $loadMs <= 1200) {
            $score += 2;
        }

        return min(100, max(0, $score));
    }

    private function emptyMetrics(array $crawledSignals): array
    {
        return [
            'organic_traffic_estimate' => null,
            'organic_keywords_count' => null,
            'traffic_value_estimate' => null,
            'referring_domains_count' => null,
            'backlinks_count' => null,
            'indexed_pages_count' => null,
            'authority_score' => null,
            'authority_metric_label' => null,
            'serp_visibility_score' => null,
            'ranking_distribution' => null,
            'top_keywords' => [],
            'top_pages' => [],
            'crawled_signals' => $crawledSignals,
        ];
    }
}
