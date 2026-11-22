<?php

namespace App\Domain\Competitors\Services;

use App\Domain\Competitors\CompetitorProviderManager;
use App\Domain\Crawler\UrlSecurityValidator;
use App\Models\CompetitorDiscoveryRun;
use App\Models\CompetitorDomain;
use App\Models\CrawledPage;
use App\Models\SearchPerformanceRecord;
use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class CompetitorDiscoveryService
{
    private const COUNTRY_NAMES = [
        'US' => 'USA',
        'GB' => 'UK',
        'UK' => 'UK',
        'BD' => 'Bangladesh',
        'IN' => 'India',
        'CA' => 'Canada',
        'AU' => 'Australia',
        'PK' => 'Pakistan',
        'AE' => 'UAE',
        'SG' => 'Singapore',
        'MY' => 'Malaysia',
        'DE' => 'Germany',
        'FR' => 'France',
        'NL' => 'Netherlands',
        'ES' => 'Spain',
        'IT' => 'Italy',
        'BR' => 'Brazil',
        'JP' => 'Japan',
        'ID' => 'Indonesia',
        'PH' => 'Philippines',
        'SA' => 'Saudi Arabia',
        'NZ' => 'New Zealand',
        'ZA' => 'South Africa',
        'NG' => 'Nigeria',
    ];

    private const STOP_WORDS = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'your', 'our', 'their', 'into', 'over', 'about',
        'more', 'most', 'very', 'just', 'only', 'also', 'here', 'there', 'when', 'where', 'what', 'which',
        'who', 'how', 'why', 'all', 'any', 'both', 'each', 'some', 'such', 'than', 'then', 'them', 'they',
        'will', 'can', 'may', 'should', 'could', 'would', 'have', 'has', 'had', 'are', 'was', 'were', 'been',
        'being', 'not', 'but', 'you', 'out', 'off', 'now', 'get', 'got', 'new', 'one', 'two', 'top', 'best',
        'trusted', 'welcome', 'official', 'website', 'site', 'page', 'online', 'leading', 'premier', 'quality',
        'professional', 'reliable', 'verified', 'background', 'find', 'across', 'total', 'safety', 'privacy',
        'peace', 'mind', 'loved', 'ones', 'coordinated', 'admin', 'affordable', 'easy', 'fast', 'free',
        'home', 'homepage', 'index', 'com', 'org', 'net', 'www', 'http', 'https', 'inc', 'ltd', 'llc',
        'services', 'service', 'solutions', 'solution', 'company', 'platform', 'agency', 'providers', 'provider',
    ];

    private const KNOWN_CITIES = [
        'dhaka', 'chittagong', 'chattogram', 'sylhet', 'khulna', 'rajshahi', 'gulshan', 'banani', 'uttara', 'dhanmondi',
        'london', 'manchester', 'birmingham', 'new york', 'los angeles', 'chicago', 'houston', 'miami', 'austin', 'seattle',
        'toronto', 'vancouver', 'montreal', 'sydney', 'melbourne', 'brisbane', 'mumbai', 'delhi', 'bangalore', 'bengaluru',
        'hyderabad', 'chennai', 'kolkata', 'dubai', 'abu dhabi', 'singapore', 'kuala lumpur', 'karachi', 'lahore', 'islamabad',
        'berlin', 'munich', 'paris', 'amsterdam', 'tokyo', 'jakarta', 'manila', 'riyadh', 'jeddah',
    ];

    public function __construct(private CompetitorProviderManager $providerManager)
    {
    }

    /**
     * Run intelligent competitor discovery.
     * Candidates are stored on the discovery run and require manual confirmation before adding.
     */
    public function discover(
        Website $website,
        ?int $userId = null,
        string $country = 'US',
        string $language = 'en',
        string $searchEngine = 'google',
        ?string $industryContext = null,
        bool $forceRefresh = false
    ): CompetitorDiscoveryRun {
        $country = strtoupper(trim($country)) ?: 'US';
        $language = strtolower(trim($language)) ?: 'en';
        $targetDomain = CompetitorDomain::normalizeDomain($website->domain);
        $profile = $website->contextProfile;

        // Extract rich niche/service queries from user hint, crawled/live homepage, brand profile, and GSC queries
        $topicContext = $this->extractTargetTopicContext($website, $targetDomain, $industryContext, $country);
        $seedKeywords = $topicContext['queries'];
        $serviceTerms = $topicContext['service_terms'];
        $industry = $industryContext ?: $topicContext['detected_summary'];

        $provider = $this->providerManager->resolveForCapability('discovery');
        $cacheSignature = "{$provider->getName()}:{$targetDomain}:{$country}:{$language}:" . md5(implode('|', $seedKeywords) . '|' . ($industry ?? ''));

        $result = $this->providerManager->cachedCall(
            $website,
            'discovery',
            $cacheSignature,
            "discover:{$targetDomain}",
            fn () => $provider->discoverCompetitors($targetDomain, $country, $language, [
                'industry' => $industry,
                'seed_keywords' => $seedKeywords,
                'service_terms' => $serviceTerms,
                'search_engine' => $searchEngine,
            ]),
            $forceRefresh
        );

        $existingDomains = CompetitorDomain::where('website_id', $website->id)
            ->pluck('domain')
            ->map(fn ($d) => strtolower($d))
            ->toArray();

        $portalDomains = array_map('strtolower', config('competitors.non_direct_portal_domains', []));
        $rawCandidates = $result['candidates'] ?? [];

        // Also include explicit competitor hints from WebsiteContextProfile (if user previously entered them)
        if ($profile && is_array($profile->competitors)) {
            foreach ($profile->competitors as $hint) {
                $norm = CompetitorDomain::normalizeDomain((string) $hint);
                if ($norm !== '' && $norm !== $targetDomain && !collect($rawCandidates)->contains('domain', $norm)) {
                    $rawCandidates[] = [
                        'domain' => $norm,
                        'shared_keywords' => null,
                        'serp_overlap_pct' => null,
                        'sample_urls' => [],
                        'source' => 'brand_profile',
                    ];
                }
            }
        }

        $evaluatedCandidates = [];
        foreach ($rawCandidates as $cand) {
            $candDomain = CompetitorDomain::normalizeDomain((string) ($cand['domain'] ?? ''));
            if ($candDomain === '' || $candDomain === $targetDomain || str_ends_with($candDomain, '.' . $targetDomain)) {
                continue;
            }

            $isPortal = $this->isPortalDomain($candDomain, $portalDomains);
            $sharedKw = isset($cand['shared_keywords']) ? (int) $cand['shared_keywords'] : null;
            $overlapPct = isset($cand['serp_overlap_pct']) ? (float) $cand['serp_overlap_pct'] : null;
            $avgPos = isset($cand['avg_position']) ? (float) $cand['avg_position'] : null;
            $sampleTitle = isset($cand['sample_title']) ? trim((string) $cand['sample_title']) : null;
            $sampleSnippet = isset($cand['sample_snippet']) ? trim((string) $cand['sample_snippet']) : null;
            $sampleUrls = array_values(array_filter((array) ($cand['sample_urls'] ?? [])));
            $matchedQueries = array_values(array_filter((array) ($cand['matched_queries'] ?? [])));
            $source = (string) ($cand['source'] ?? $provider->getName());

            $isEditorial = $this->isEditorialOrMediaSite($candDomain, $sampleUrls, $sampleTitle);
            $matchedTerms = $this->findMatchedServiceTerms($candDomain, $sampleTitle, $sampleSnippet, $serviceTerms);
            $isSerpDiscovery = in_array($source, ['public_web_serp', 'public_bing_rss', 'gemini_google_search', 'serper', 'serpapi'], true);

            // Intelligent classification: NEVER classify a generic directory/social/portal as a direct business competitor
            if ($isPortal) {
                $category = CompetitorDomain::CATEGORY_INDIRECT;
                $confidence = min(52, max(25, (int) round(($overlapPct ?? 30) * 0.55)));
                $reason = "Filtered from Direct Competitors because {$candDomain} is a broad portal, social network, classifieds, or directory rather than a direct business rival.";
            } elseif ($source === 'brand_profile') {
                $category = CompetitorDomain::CATEGORY_DIRECT;
                $confidence = 90;
                $reason = 'Listed explicitly in your verified Website Brand Context Profile.';
            } elseif ($isEditorial) {
                $category = CompetitorDomain::CATEGORY_CONTENT;
                $confidence = min(74, max(45, 45 + count($matchedTerms) * 6));
                $reason = 'Editorial, news, or informational publication ranking on overlapping topic queries; classified as Content Competitor rather than Direct Business Rival.';
            } elseif (
                ($sharedKw !== null && $sharedKw >= 5)
                || ($isSerpDiscovery && $sharedKw !== null && $sharedKw >= 2)
                || ($isSerpDiscovery && count($matchedTerms) >= 2 && ($avgPos === null || $avgPos <= 12))
                || (!$isSerpDiscovery && $overlapPct !== null && $overlapPct >= 35)
            ) {
                $category = CompetitorDomain::CATEGORY_DIRECT;
                $rankBoost = $avgPos !== null ? max(0, (int) round((11 - min(11, $avgPos)) * 1.8)) : 6;
                $termBoost = min(16, count($matchedTerms) * 4);
                $queryBoost = $isSerpDiscovery ? min(20, ($sharedKw ?? 1) * 9) : min(25, (int) round(($overlapPct ?? 20) * 0.4));
                $confidence = min(96, max(68, 56 + $rankBoost + $termBoost + $queryBoost));

                $parts = [];
                if ($avgPos !== null && $sharedKw !== null) {
                    $parts[] = "Ranked avg #{$avgPos} across {$sharedKw} target commercial " . ($sharedKw === 1 ? 'query' : 'queries') . " in {$country}/{$language}";
                } elseif ($sharedKw !== null) {
                    $parts[] = "Strong multi-keyword SERP overlap ({$sharedKw} shared organic keywords, {$overlapPct}% overlap) in {$country}/{$language}";
                }
                if (!empty($matchedTerms)) {
                    $parts[] = 'matches core niche terms (' . implode(', ', array_slice($matchedTerms, 0, 5)) . ')';
                }
                $reason = implode('; ', $parts) . '.';
            } elseif (($sharedKw !== null && $sharedKw >= 2) || ($isSerpDiscovery && count($matchedTerms) >= 1)) {
                $category = CompetitorDomain::CATEGORY_ORGANIC;
                $confidence = min(78, max(54, 48 + count($matchedTerms) * 6 + ($avgPos !== null && $avgPos <= 8 ? 8 : 0)));
                $termNote = !empty($matchedTerms) ? ' matching ' . implode(', ', array_slice($matchedTerms, 0, 4)) : '';
                $posNote = $avgPos !== null ? " at avg rank #{$avgPos}" : '';
                $reason = "Competes organically in {$country}/{$language}{$posNote}{$termNote} with partial niche overlap.";
            } else {
                $category = CompetitorDomain::CATEGORY_CONTENT;
                $confidence = 48;
                $reason = 'Single-query or tangential topical overlap detected; classified as Content Competitor rather than Direct Business Competitor.';
            }

            $evaluatedCandidates[] = [
                'domain' => $candDomain,
                'category' => $category,
                'confidence' => $confidence,
                'is_portal_filtered' => $isPortal,
                'shared_keywords' => $sharedKw,
                'serp_overlap_pct' => $overlapPct,
                'avg_position' => $avgPos,
                'matched_queries' => $matchedQueries,
                'matched_service_terms' => array_slice($matchedTerms, 0, 6),
                'organic_traffic_estimate' => $cand['organic_traffic_estimate'] ?? null,
                'sample_title' => $sampleTitle ?: null,
                'sample_snippet' => $sampleSnippet ?: null,
                'sample_urls' => $sampleUrls,
                'relevance_reason' => $reason,
                'source' => $source,
                'already_tracked' => in_array($candDomain, $existingDomains, true),
                'confirmed' => false,
            ];
        }

        // Prioritize Direct rivals first, then Organic Search, then Content, then Indirect/Portals — each by confidence DESC
        $categoryRank = [
            CompetitorDomain::CATEGORY_DIRECT => 1,
            CompetitorDomain::CATEGORY_ORGANIC => 2,
            CompetitorDomain::CATEGORY_CONTENT => 3,
            CompetitorDomain::CATEGORY_INDIRECT => 4,
        ];
        usort($evaluatedCandidates, function ($a, $b) use ($categoryRank) {
            $catCmp = ($categoryRank[$a['category']] ?? 9) <=> ($categoryRank[$b['category']] ?? 9);
            if ($catCmp !== 0) {
                return $catCmp;
            }
            $confCmp = $b['confidence'] <=> $a['confidence'];
            if ($confCmp !== 0) {
                return $confCmp;
            }
            return ($a['avg_position'] ?? 999) <=> ($b['avg_position'] ?? 999);
        });

        $methodology = (string) ($result['methodology'] ?? '');
        if ($methodology === '') {
            $methodology = $provider->getName() === 'first_party_crawl'
                ? 'Built-in Public Web Search Discovery using target homepage & niche queries with portal/directory exclusion filter.'
                : "Automated SERP & keyword overlap via {$provider->getLabel()} with portal/directory exclusion filter.";
        }

        return CompetitorDiscoveryRun::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'initiated_by' => $userId,
            'country' => $country,
            'language' => $language,
            'search_engine' => $searchEngine,
            'industry_context' => $industry,
            'provider' => $provider->getName(),
            'methodology' => $methodology,
            'status' => 'completed',
            'candidates' => $evaluatedCandidates,
            'error_message' => $result['error'] ?? null,
            'completed_at' => now(),
        ]);
    }

    /**
     * Extract target business niche, service terms, and high-signal commercial queries
     * from user input, crawled/live homepage metadata, Brand Context Profile, and GSC queries.
     */
    public function extractTargetTopicContext(Website $website, string $targetDomain, ?string $industryContext, string $country): array
    {
        $profile = $website->contextProfile;
        $countryName = self::COUNTRY_NAMES[$country] ?? '';

        // Brand tokens that MUST NOT pollute non-branded competitor queries
        $domainStem = strtolower((string) pathinfo($targetDomain, PATHINFO_FILENAME));
        $brandTokens = array_values(array_unique(array_filter(array_merge(
            [$domainStem],
            preg_split('/[^a-z0-9]+/i', strtolower((string) $website->name)) ?: [],
            preg_split('/[-_.]+/', $domainStem) ?: []
        ), fn ($t) => strlen($t) >= 2)));
        // Never treat a real country name or city as a brand token to strip
        $geoPreserve = array_merge(
            array_map('strtolower', array_values(self::COUNTRY_NAMES)),
            self::KNOWN_CITIES
        );
        $brandTokens = array_values(array_diff($brandTokens, $geoPreserve));

        // 1. Gather CrawledPage metadata (homepage preferred, fallback to top crawled pages + clean URL slugs)
        $crawledPages = CrawledPage::where('website_id', $website->id)
            ->where('status_code', '>=', 200)
            ->where('status_code', '<', 400)
            ->orderByRaw('LENGTH(url) ASC')
            ->limit(15)
            ->get(['url', 'page_title', 'meta_description', 'h1_tags']);

        $homepageTitle = '';
        $homepageDesc = '';
        $homepageH1 = '';
        $urlSlugWords = [];

        foreach ($crawledPages as $page) {
            $t = trim((string) ($page->page_title ?? ''));
            if ($homepageTitle === '' && $t !== '' && !$this->isInterstitialTitle($t)) {
                $homepageTitle = $t;
            }
            $d = trim((string) ($page->meta_description ?? ''));
            if ($homepageDesc === '' && $d !== '' && !$this->isInterstitialTitle($d)) {
                $homepageDesc = $d;
            }
            if ($homepageH1 === '' && is_array($page->h1_tags) && !empty($page->h1_tags[0])) {
                $h = trim((string) $page->h1_tags[0]);
                if ($h !== '' && !$this->isInterstitialTitle($h)) {
                    $homepageH1 = $h;
                }
            }
            $path = trim((string) parse_url((string) $page->url, PHP_URL_PATH), '/');
            if ($path !== '' && !str_contains($path, '.')) {
                $firstSeg = explode('/', $path)[0] ?? '';
                if ($firstSeg !== '' && strlen($firstSeg) >= 4 && strlen($firstSeg) <= 32) {
                    $urlSlugWords[] = str_replace(['-', '_'], ' ', $firstSeg);
                }
            }
        }

        // 2. If website hasn't been crawled yet (or has no valid title), fetch live homepage safely
        if ($homepageTitle === '') {
            $liveMeta = $this->fetchLiveHomepageMeta($website->target_url ?: "https://{$targetDomain}", $targetDomain);
            $homepageTitle = $liveMeta['title'];
            if ($homepageDesc === '') {
                $homepageDesc = $liveMeta['description'];
            }
            if ($homepageH1 === '') {
                $homepageH1 = $liveMeta['h1'];
            }
        }

        // 2b. If homepage is behind a WAF interstitial and no explicit industry hint was given, read Google's indexed snippet via configured SERP provider (site:domain)
        if ($homepageTitle === '' && empty($industryContext) && $this->providerManager->hasExternalProviderFor('serp')) {
            $serpProv = $this->providerManager->resolveForCapability('serp');
            $indexed = $serpProv->fetchSerp("site:{$targetDomain}", $country, $website->preferred_language ?: 'en');
            if (($indexed['available'] ?? false) && !empty($indexed['organic_results'])) {
                $firstHit = $indexed['organic_results'][0];
                $homepageTitle = trim((string) ($firstHit['title'] ?? ''));
                if ($homepageDesc === '') {
                    $homepageDesc = trim((string) ($firstHit['snippet'] ?? ''));
                }
            }
        }

        $slugPhrase = implode(' ', array_slice(array_unique($urlSlugWords), 0, 5));

        // 3. Extract clean title segment (strip brand prefix/suffix separated by —, |, -, :)
        $cleanTitlePhrase = $this->stripBrandSegment($homepageTitle, $brandTokens);
        $cleanH1Phrase = $this->stripBrandSegment($homepageH1, $brandTokens);

        // 4. Gather Brand Context Profile signals
        $profileBiz = trim((string) ($profile?->business_description ?? ''));
        $profileProducts = '';
        if ($profile && is_array($profile->products_and_services)) {
            $profileProducts = implode(' ', array_slice(array_filter(array_map('strval', $profile->products_and_services)), 0, 5));
        }

        // 5. Gather top non-branded GSC queries
        $gscQueries = SearchPerformanceRecord::where('website_id', $website->id)
            ->select('query')
            ->selectRaw('SUM(impressions) as total_imp')
            ->whereNotNull('query')
            ->where('query', '!=', '')
            ->groupBy('query')
            ->orderByDesc('total_imp')
            ->limit(10)
            ->pluck('query')
            ->filter(function ($q) use ($domainStem) {
                $ql = strtolower((string) $q);
                return strlen($domainStem) < 4 || !str_contains($ql, $domainStem);
            })
            ->values()
            ->toArray();

        // 6. Detect city mentioned on homepage/profile/input
        $combinedCorpus = strtolower(implode(' ', [$industryContext ?? '', $homepageTitle, $homepageDesc, $homepageH1, $profileBiz, $profileProducts]));
        $detectedCity = '';
        foreach (self::KNOWN_CITIES as $city) {
            if (preg_match('/\b' . preg_quote($city, '/') . '\b/i', $combinedCorpus)) {
                $detectedCity = Str::title($city);
                break;
            }
        }

        // 7. Extract ordered core service terms (meaningful non-brand nouns/modifiers)
        $serviceTerms = $this->extractKeywords(
            implode(' ', [$industryContext ?? '', $cleanTitlePhrase, $cleanH1Phrase, $profileProducts, $slugPhrase, $homepageDesc]),
            $brandTokens
        );

        // 8. Build up to 3 high-intent commercial discovery queries
        $queries = [];

        // Query A: Explicit user industry context (with country if omitted)
        if ($industryContext && trim($industryContext) !== '') {
            $q = trim($industryContext);
            if ($countryName !== '' && !str_contains(strtolower($q), strtolower($countryName)) && strlen($q) < 60) {
                $q .= " {$countryName}";
            }
            $queries[] = $q;
        }

        // Query B: Primary service phrase synthesized from homepage title / products / slugs + country
        $primaryTokens = $this->extractKeywords(
            trim($cleanTitlePhrase !== '' ? $cleanTitlePhrase : ($cleanH1Phrase !== '' ? $cleanH1Phrase : ($profileProducts !== '' ? $profileProducts : $slugPhrase))),
            $brandTokens
        );
        $geoTokens = array_filter([$detectedCity, $countryName]);
        $nonGeoPrimary = array_values(array_filter(
            $primaryTokens,
            fn ($t) => !in_array($t, array_map('strtolower', $geoTokens), true)
        ));

        if (count($nonGeoPrimary) >= 2) {
            $core = implode(' ', array_slice($nonGeoPrimary, 0, 5));
            $q = trim($core . ($countryName !== '' ? " {$countryName}" : ''));
            $queries[] = $q;
        }

        // Query C: Commercial service/agency angle with detected city + country
        $coreService3 = array_slice(
            array_values(array_filter($serviceTerms, fn ($t) => !in_array($t, array_map('strtolower', $geoTokens), true))),
            0,
            3
        );
        if (!empty($coreService3)) {
            $locSuffix = trim(($detectedCity !== '' ? "{$detectedCity} " : '') . $countryName);
            $q = trim(implode(' ', $coreService3) . ' service ' . $locSuffix);
            $queries[] = $q;
        }

        // Query D: Top verified non-branded GSC query (if available)
        if (!empty($gscQueries[0])) {
            $queries[] = trim((string) $gscQueries[0]);
        }

        // Query E: "best <top service terms> <country>" backup commercial query if fewer than 3 queries
        if (count($queries) < 3 && count($coreService3) >= 2) {
            $queries[] = trim('best ' . implode(' ', array_slice($coreService3, 0, 3)) . ($countryName !== '' ? " {$countryName}" : ''));
        }

        // Deduplicate case-insensitively and limit to 3 high-signal queries
        $uniqueQueries = [];
        $seen = [];
        foreach ($queries as $q) {
            $norm = preg_replace('/\s+/', ' ', trim($q));
            $key = strtolower($norm);
            if ($norm !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueQueries[] = $norm;
            }
        }

        if (empty($uniqueQueries)) {
            $fallback = trim(str_replace(['-', '_', '.'], ' ', $domainStem) . ($countryName !== '' ? " {$countryName}" : ''));
            $uniqueQueries[] = $fallback;
        }

        $detectedSummary = $cleanTitlePhrase !== ''
            ? Str::limit($cleanTitlePhrase, 140, '')
            : ($profileBiz !== '' ? Str::limit($profileBiz, 140, '') : $uniqueQueries[0]);

        return [
            'queries' => array_slice($uniqueQueries, 0, 3),
            'service_terms' => array_slice($serviceTerms, 0, 12),
            'detected_summary' => $detectedSummary,
        ];
    }

    /**
     * Confirm selected discovered candidates and persist them into competitor_domains.
     *
     * @param array<int, array{domain: string, category?: string, tier?: string}> $selected
     * @return CompetitorDomain[]
     */
    public function confirmCandidates(Website $website, CompetitorDiscoveryRun $run, array $selected): array
    {
        $candidatesByDomain = collect($run->candidates ?? [])->keyBy('domain');
        $added = [];
        $updatedCandidates = $run->candidates ?? [];

        foreach ($selected as $item) {
            $domain = CompetitorDomain::normalizeDomain((string) ($item['domain'] ?? ''));
            if ($domain === '' || $domain === CompetitorDomain::normalizeDomain($website->domain)) {
                continue;
            }

            $meta = $candidatesByDomain->get($domain, []);
            $category = in_array($item['category'] ?? '', CompetitorDomain::CATEGORIES, true)
                ? $item['category']
                : ($meta['category'] ?? CompetitorDomain::CATEGORY_DIRECT);
            $tier = in_array($item['tier'] ?? '', [CompetitorDomain::TIER_PRIMARY, CompetitorDomain::TIER_SECONDARY], true)
                ? $item['tier']
                : CompetitorDomain::TIER_PRIMARY;

            $comp = CompetitorDomain::updateOrCreate(
                [
                    'website_id' => $website->id,
                    'domain' => $domain,
                ],
                [
                    'organization_id' => $website->organization_id,
                    'display_name' => $item['display_name'] ?? Str::title(str_replace('-', ' ', pathinfo($domain, PATHINFO_FILENAME))),
                    'category' => $category,
                    'tier' => $tier,
                    'country' => $run->country,
                    'language' => $run->language,
                    'discovery_source' => $meta['source'] ?? $run->provider,
                    'discovery_confidence' => $meta['confidence'] ?? null,
                    'discovery_rationale' => $meta['relevance_reason'] ?? null,
                    'is_active' => true,
                ]
            );

            $added[] = $comp;

            foreach ($updatedCandidates as &$c) {
                if (($c['domain'] ?? '') === $domain) {
                    $c['confirmed'] = true;
                    $c['already_tracked'] = true;
                    $c['category'] = $category;
                }
            }
            unset($c);
        }

        $run->update(['candidates' => $updatedCandidates]);

        return $added;
    }

    private function fetchLiveHomepageMeta(string $url, string $domain): array
    {
        $empty = ['title' => '', 'description' => '', 'h1' => ''];

        try {
            if (!app()->runningUnitTests()) {
                UrlSecurityValidator::validate($url, $domain);
            }

            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->timeout(6)->get($url);

            if (!$res->successful()) {
                return $empty;
            }

            $html = $res->body();
            $title = '';
            $desc = '';
            $h1 = '';

            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
                $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
            }
            if ($this->isInterstitialTitle($title)) {
                return $empty;
            }
            if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\']/is', $html, $m)
                || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\']/is', $html, $m)) {
                $desc = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
            }
            if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
                $h1 = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
            }

            return ['title' => $title, 'description' => $desc, 'h1' => $h1];
        } catch (Throwable) {
            return $empty;
        }
    }

    private function isInterstitialTitle(string $text): bool
    {
        $lower = strtolower(trim($text));
        foreach (['one moment, please', 'just a moment', 'attention required', 'access denied', 'checking your browser', 'verify you are human', 'security check', '403 forbidden', '404 not found'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function stripBrandSegment(string $text, array $brandTokens): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return '';
        }

        $segments = preg_split('/\s*(?:—|–|\||::| - )\s*/u', $text) ?: [$text];
        $kept = [];
        foreach ($segments as $seg) {
            $segTrim = trim($seg);
            if ($segTrim === '') {
                continue;
            }
            $segWords = array_values(array_filter(preg_split('/[^a-z0-9]+/i', strtolower($segTrim)) ?: []));
            if (empty($segWords)) {
                continue;
            }
            // Drop short segment if it is purely the brand name (e.g., "CareMate BD")
            $nonBrand = array_diff($segWords, $brandTokens);
            if (count($segWords) <= 3 && empty($nonBrand)) {
                continue;
            }
            $kept[] = $segTrim;
        }

        return implode(' ', $kept);
    }

    private function extractKeywords(string $text, array $brandTokens): array
    {
        $words = preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [];
        $out = [];
        $seen = [];
        foreach ($words as $w) {
            if (strlen($w) < 3 || is_numeric($w)) {
                continue;
            }
            if (in_array($w, self::STOP_WORDS, true) || in_array($w, $brandTokens, true)) {
                continue;
            }
            if (!isset($seen[$w])) {
                $seen[$w] = true;
                $out[] = $w;
            }
        }
        return $out;
    }

    private function findMatchedServiceTerms(string $domain, ?string $title, ?string $snippet, array $serviceTerms): array
    {
        if (empty($serviceTerms)) {
            return [];
        }

        $haystack = strtolower($domain . ' ' . ($title ?? '') . ' ' . ($snippet ?? ''));
        $matched = [];
        foreach ($serviceTerms as $term) {
            $stem = strlen($term) > 5 ? rtrim($term, 's') : $term;
            if (str_contains($haystack, $term) || ($stem !== $term && str_contains($haystack, $stem))) {
                $matched[] = $term;
            }
        }
        return array_values(array_unique($matched));
    }

    private function isEditorialOrMediaSite(string $domain, array $sampleUrls, ?string $title): bool
    {
        if (preg_match('/(news|tribune|times|daily|magazine|journal|blog|wiki|gazette|observer|post\.)/i', $domain)) {
            return true;
        }

        foreach ($sampleUrls as $url) {
            $path = strtolower((string) parse_url($url, PHP_URL_PATH));
            if (preg_match('#/(blog|news|articles?|wiki|editorial|stories|press)/#i', $path)) {
                return true;
            }
        }

        if ($title && preg_match('/\b(top \d+|best \d+|wikipedia|news|review|guide to)\b/i', $title) && preg_match('/(blog|review|guide|news)/i', $domain)) {
            return true;
        }

        return false;
    }

    private function isPortalDomain(string $domain, array $portalDomains): bool
    {
        foreach ($portalDomains as $portal) {
            if ($domain === $portal || str_ends_with($domain, '.' . $portal)) {
                return true;
            }
        }
        return false;
    }
}
