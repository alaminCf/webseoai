<?php

namespace App\Domain\Competitors\Services;

use App\Domain\Competitors\CompetitorProviderManager;
use App\Domain\Competitors\Support\CountryCatalog;
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
        'minutes', 'hours', 'days', 'years', 'near',
    ];

    public function __construct(private CompetitorProviderManager $providerManager)
    {
    }

    /**
     * Run intelligent competitor discovery across one or more target countries.
     * Candidates are stored on the discovery run and require manual confirmation before adding.
     *
     * @param string[] $countries Optional list of ISO-2 country codes (up to 5).
     */
    public function discover(
        Website $website,
        ?int $userId = null,
        string $country = 'US',
        string $language = 'en',
        string $searchEngine = 'google',
        ?string $industryContext = null,
        bool $forceRefresh = false,
        array $countries = [],
        bool $strictCountryMatch = true
    ): CompetitorDiscoveryRun {
        $selectedCountries = CountryCatalog::normalizeCodes(
            !empty($countries) ? $countries : [$country],
            $country ?: ($website->target_country ?: 'US'),
            5
        );
        $primaryCountry = $selectedCountries[0];
        $language = strtolower(trim($language)) ?: 'en';
        $targetDomain = CompetitorDomain::normalizeDomain($website->domain);
        $profile = $website->contextProfile;

        $provider = $this->providerManager->resolveForCapability('discovery');

        // Run country-scoped discovery for each selected country and merge candidates cleanly
        $mergedRaw = [];
        $allQueriesUsed = [];
        $allServiceTerms = [];
        $detectedSummary = $industryContext ?: null;
        $errors = [];

        // Keep per-country query budget lean when multiple countries are selected
        $perCountryQueryLimit = count($selectedCountries) > 2 ? 2 : 3;

        foreach ($selectedCountries as $cc) {
            $topicContext = $this->extractTargetTopicContext($website, $targetDomain, $industryContext, $cc);
            $seedKeywords = array_slice($topicContext['queries'], 0, $perCountryQueryLimit);
            $allQueriesUsed = array_merge($allQueriesUsed, $seedKeywords);
            $allServiceTerms = array_values(array_unique(array_merge($allServiceTerms, $topicContext['service_terms'])));
            if (!$detectedSummary && !empty($topicContext['detected_summary'])) {
                $detectedSummary = $topicContext['detected_summary'];
            }

            $cacheSignature = "{$provider->getName()}:{$targetDomain}:{$cc}:{$language}:" . md5(implode('|', $seedKeywords) . '|' . ($industryContext ?? ''));

            $res = $this->providerManager->cachedCall(
                $website,
                'discovery',
                $cacheSignature,
                "discover:{$targetDomain}:{$cc}",
                fn () => $provider->discoverCompetitors($targetDomain, $cc, $language, [
                    'industry' => $industryContext ?: $topicContext['detected_summary'],
                    'seed_keywords' => $seedKeywords,
                    'service_terms' => $topicContext['service_terms'],
                    'search_engine' => $searchEngine,
                ]),
                $forceRefresh
            );

            if (!empty($res['error'])) {
                $errors[] = "{$cc}: {$res['error']}";
            }

            foreach ($res['candidates'] ?? [] as $cand) {
                $candDomain = CompetitorDomain::normalizeDomain((string) ($cand['domain'] ?? ''));
                if ($candDomain === '' || $candDomain === $targetDomain || str_ends_with($candDomain, '.' . $targetDomain)) {
                    continue;
                }

                if (!isset($mergedRaw[$candDomain])) {
                    $cand['domain'] = $candDomain;
                    $cand['searched_countries'] = [$cc];
                    $mergedRaw[$candDomain] = $cand;
                } else {
                    // Merge multi-country sightings of the same domain
                    if (!in_array($cc, $mergedRaw[$candDomain]['searched_countries'], true)) {
                        $mergedRaw[$candDomain]['searched_countries'][] = $cc;
                    }
                    $mergedRaw[$candDomain]['shared_keywords'] = (int) ($mergedRaw[$candDomain]['shared_keywords'] ?? 0) + (int) ($cand['shared_keywords'] ?? 0);
                    $mergedRaw[$candDomain]['matched_queries'] = array_values(array_unique(array_merge(
                        (array) ($mergedRaw[$candDomain]['matched_queries'] ?? []),
                        (array) ($cand['matched_queries'] ?? [])
                    )));
                    $mergedRaw[$candDomain]['sample_urls'] = array_slice(array_values(array_unique(array_merge(
                        (array) ($mergedRaw[$candDomain]['sample_urls'] ?? []),
                        (array) ($cand['sample_urls'] ?? [])
                    ))), 0, 4);
                    if (isset($cand['avg_position'], $mergedRaw[$candDomain]['avg_position'])) {
                        $mergedRaw[$candDomain]['avg_position'] = round(((float) $mergedRaw[$candDomain]['avg_position'] + (float) $cand['avg_position']) / 2, 1);
                    }
                }
            }
        }

        // Also include explicit competitor hints from WebsiteContextProfile (if user previously entered them)
        if ($profile && is_array($profile->competitors)) {
            foreach ($profile->competitors as $hint) {
                $norm = CompetitorDomain::normalizeDomain((string) $hint);
                if ($norm !== '' && $norm !== $targetDomain && !isset($mergedRaw[$norm])) {
                    $mergedRaw[$norm] = [
                        'domain' => $norm,
                        'shared_keywords' => null,
                        'serp_overlap_pct' => null,
                        'sample_urls' => [],
                        'source' => 'brand_profile',
                        'searched_countries' => $selectedCountries,
                    ];
                }
            }
        }

        $existingDomains = CompetitorDomain::where('website_id', $website->id)
            ->pluck('domain')
            ->map(fn ($d) => strtolower($d))
            ->toArray();

        $portalDomains = array_map('strtolower', config('competitors.non_direct_portal_domains', []));
        $evaluatedCandidates = [];
        $filteredForeignCount = 0;
        $filteredUnverifiedCountryCount = 0;

        foreach (array_values($mergedRaw) as $cand) {
            $candDomain = (string) $cand['domain'];
            $isPortal = $this->isPortalDomain($candDomain, $portalDomains);
            $sharedKw = isset($cand['shared_keywords']) ? (int) $cand['shared_keywords'] : null;
            $overlapPct = isset($cand['serp_overlap_pct']) ? (float) $cand['serp_overlap_pct'] : null;
            $avgPos = isset($cand['avg_position']) ? (float) $cand['avg_position'] : null;
            $sampleTitle = isset($cand['sample_title']) ? trim((string) $cand['sample_title']) : null;
            $sampleSnippet = isset($cand['sample_snippet']) ? trim((string) $cand['sample_snippet']) : null;
            $sampleUrls = array_values(array_filter((array) ($cand['sample_urls'] ?? [])));
            $matchedQueries = array_values(array_filter((array) ($cand['matched_queries'] ?? [])));
            $source = (string) ($cand['source'] ?? $provider->getName());
            $isSerpDiscovery = in_array($source, ['public_web_serp', 'public_bing_rss', 'gemini_google_search', 'serper', 'serpapi'], true);

            // Strict exact-country evaluation (prevents US/UK/IN/foreign sites from leaking into Bangladesh or other countries!)
            $countryEval = CountryCatalog::evaluateCandidateCountry(
                $candDomain,
                $sampleTitle,
                $sampleSnippet,
                $sampleUrls,
                $selectedCountries
            );

            $matchedCountries = $countryEval['matched_countries'];
            $countryVerified = $countryEval['country_verified'];
            $foreignConflict = $countryEval['foreign_conflict'];
            $countryEvidence = $countryEval['evidence'];

            // For non-SERP providers (DataForSEO / Semrush domain tables, or explicit brand_profile) that have no snippet text,
            // trust their location-scoped database unless an explicit foreign conflict (e.g. wrong ccTLD) is detected.
            if (!$isSerpDiscovery && !$foreignConflict && empty($matchedCountries)) {
                $matchedCountries = $cand['searched_countries'] ?? [$primaryCountry];
                $countryVerified = true;
                $countryEvidence[] = 'provider_geo_db:' . implode(',', $matchedCountries);
            }

            // Enforce strict country filter on SERP candidates
            if ($strictCountryMatch && $source !== 'brand_profile') {
                if ($foreignConflict) {
                    $filteredForeignCount++;
                    continue;
                }
                // For SERP-based discovery targeting non-US country/countries, require positive local-country verification
                // (domain ccTLD/suffix, country name, local city, or currency) so random global/US pages never pollute results!
                $onlyNonUsSelected = !in_array('US', $selectedCountries, true);
                if ($isSerpDiscovery && $onlyNonUsSelected && !$countryVerified) {
                    $filteredUnverifiedCountryCount++;
                    continue;
                }
            }

            $isEditorial = $this->isEditorialOrMediaSite($candDomain, $sampleUrls, $sampleTitle);
            $matchedTerms = $this->findMatchedServiceTerms($candDomain, $sampleTitle, $sampleSnippet, $allServiceTerms);
            $countryLabel = implode(', ', array_map(fn ($c) => CountryCatalog::name($c), !empty($matchedCountries) ? $matchedCountries : $selectedCountries));

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
                || ($isSerpDiscovery && $countryVerified && $sharedKw !== null && $sharedKw >= 2)
                || ($isSerpDiscovery && $countryVerified && count($matchedTerms) >= 1 && ($avgPos === null || $avgPos <= 15))
                || ($isSerpDiscovery && in_array('US', $selectedCountries, true) && count($matchedTerms) >= 2 && ($avgPos === null || $avgPos <= 12))
                || (!$isSerpDiscovery && $overlapPct !== null && $overlapPct >= 35)
            ) {
                $category = CompetitorDomain::CATEGORY_DIRECT;
                $rankBoost = $avgPos !== null ? max(0, (int) round((12 - min(12, $avgPos)) * 1.5)) : 6;
                $termBoost = min(14, count($matchedTerms) * 4);
                $countryBoost = $countryVerified ? 8 : 0;
                $queryBoost = $isSerpDiscovery ? min(18, ($sharedKw ?? 1) * 8) : min(25, (int) round(($overlapPct ?? 20) * 0.4));
                $confidence = min(96, max(68, 52 + $rankBoost + $termBoost + $countryBoost + $queryBoost));

                $parts = [];
                if ($avgPos !== null && $sharedKw !== null) {
                    $parts[] = "Verified {$countryLabel} competitor — ranked avg #{$avgPos} across {$sharedKw} target " . ($sharedKw === 1 ? 'query' : 'queries');
                } elseif ($sharedKw !== null) {
                    $parts[] = "Strong multi-keyword SERP overlap ({$sharedKw} shared organic keywords, {$overlapPct}% overlap) in {$countryLabel}";
                }
                if (!empty($matchedTerms)) {
                    $parts[] = 'matches core niche terms (' . implode(', ', array_slice($matchedTerms, 0, 5)) . ')';
                }
                $reason = implode('; ', $parts) . '.';
            } elseif (($sharedKw !== null && $sharedKw >= 2) || ($isSerpDiscovery && count($matchedTerms) >= 1)) {
                $category = CompetitorDomain::CATEGORY_ORGANIC;
                $confidence = min(78, max(54, 48 + count($matchedTerms) * 6 + ($countryVerified ? 6 : 0)));
                $termNote = !empty($matchedTerms) ? ' matching ' . implode(', ', array_slice($matchedTerms, 0, 4)) : '';
                $posNote = $avgPos !== null ? " at avg rank #{$avgPos}" : '';
                $reason = "Competes organically in {$countryLabel}{$posNote}{$termNote} with partial niche overlap.";
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
                'matched_countries' => !empty($matchedCountries) ? $matchedCountries : $selectedCountries,
                'country_verified' => $countryVerified,
                'country_evidence' => array_slice($countryEvidence, 0, 4),
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

        // Prioritize Verified Country + Direct rivals first, then Organic, Content, Portals
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
            $verCmp = ((int) ($b['country_verified'] ?? false)) <=> ((int) ($a['country_verified'] ?? false));
            if ($verCmp !== 0) {
                return $verCmp;
            }
            $confCmp = $b['confidence'] <=> $a['confidence'];
            if ($confCmp !== 0) {
                return $confCmp;
            }
            return ($a['avg_position'] ?? 999) <=> ($b['avg_position'] ?? 999);
        });

        $countriesDisplay = implode(', ', array_map(fn ($c) => CountryCatalog::name($c) . " ({$c})", $selectedCountries));
        $uniqueQueriesUsed = array_values(array_unique($allQueriesUsed));
        $filterNote = '';
        $totalFilteredOut = $filteredForeignCount + $filteredUnverifiedCountryCount;
        if ($strictCountryMatch && $totalFilteredOut > 0) {
            $filterNote = " Strict country filter excluded {$totalFilteredOut} out-of-country/foreign domain(s) ({$filteredForeignCount} foreign-region, {$filteredUnverifiedCountryCount} unverified global).";
        }

        $methodology = ($provider->getName() === 'first_party_crawl'
            ? 'Built-in Public Web Search Discovery'
            : $provider->getLabel())
            . " analyzed " . count($uniqueQueriesUsed) . " geo-targeted queries for {$countriesDisplay} ({$language}): \""
            . implode(' | ', array_slice($uniqueQueriesUsed, 0, 6)) . "\"." . $filterNote;

        return CompetitorDiscoveryRun::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'initiated_by' => $userId,
            'country' => $primaryCountry,
            'countries' => $selectedCountries,
            'language' => $language,
            'search_engine' => $searchEngine,
            'industry_context' => $detectedSummary,
            'provider' => $provider->getName(),
            'methodology' => $methodology,
            'status' => 'completed',
            'candidates' => $evaluatedCandidates,
            'error_message' => !empty($errors) && empty($evaluatedCandidates) ? implode(' | ', $errors) : null,
            'completed_at' => now(),
        ]);
    }

    /**
     * Extract target business niche, service terms, and crisp 3-5 word country-anchored queries
     * from user input, crawled/live homepage metadata, Brand Context Profile, and GSC queries.
     */
    public function extractTargetTopicContext(Website $website, string $targetDomain, ?string $industryContext, string $country): array
    {
        $profile = $website->contextProfile;
        $countryCode = strtoupper(trim($country)) ?: 'US';
        $countryName = CountryCatalog::name($countryCode);
        $countryMajorCity = CountryCatalog::majorCity($countryCode);

        // Brand tokens that MUST NOT pollute non-branded competitor queries
        $domainStem = strtolower((string) pathinfo($targetDomain, PATHINFO_FILENAME));
        $brandTokens = array_values(array_unique(array_filter(array_merge(
            [$domainStem],
            preg_split('/[^a-z0-9]+/i', strtolower((string) $website->name)) ?: [],
            preg_split('/[-_.]+/', $domainStem) ?: []
        ), fn ($t) => strlen($t) >= 2)));

        $geoPreserve = array_merge(
            array_map('strtolower', array_values(CountryCatalog::COUNTRIES)),
            CountryCatalog::allMajorCities()
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

        // 2b. If homepage is behind a WAF interstitial and no explicit industry hint was given, read Google's indexed snippet via configured SERP provider
        if ($homepageTitle === '' && empty($industryContext) && $this->providerManager->hasExternalProviderFor('serp')) {
            $serpProv = $this->providerManager->resolveForCapability('serp');
            $indexed = $serpProv->fetchSerp("site:{$targetDomain}", $countryCode, $website->preferred_language ?: 'en');
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

        // 6. Strip ANY other country/city names from extracted service terms so multi-country searches stay pure to THIS country!
        $allCountryLower = array_map('strtolower', array_values(CountryCatalog::COUNTRIES));
        $allCitiesLower = CountryCatalog::allMajorCities();
        $geoNoiseToStrip = array_merge($allCountryLower, $allCitiesLower);

        $serviceTerms = $this->extractKeywords(
            implode(' ', [$industryContext ?? '', $cleanTitlePhrase, $cleanH1Phrase, $profileProducts, $slugPhrase, $homepageDesc]),
            array_merge($brandTokens, $geoNoiseToStrip)
        );

        // 7. Build up to 3 CRISP (3-5 word) country-anchored commercial queries!
        $queries = [];

        // Query A: Explicit user industry context re-anchored cleanly to THIS target country
        if ($industryContext && trim($industryContext) !== '') {
            $hintClean = trim($industryContext);
            // If user typed another country in the hint (e.g. "caregiver in bangladesh") while searching UAE, adapt cleanly for UAE!
            $hintTerms = $this->extractKeywords($hintClean, array_merge($brandTokens, $geoNoiseToStrip));
            if (str_contains(strtolower($hintClean), strtolower($countryName))) {
                $queries[] = $hintClean;
            } elseif (!empty($hintTerms)) {
                $queries[] = trim(implode(' ', array_slice($hintTerms, 0, 4)) . " in {$countryName}");
            } else {
                $queries[] = trim($hintClean . " {$countryName}");
            }
        }

        // Query B: Crisp top 2-3 service terms + "service" + country name (and major city if applicable)
        $top2 = array_slice($serviceTerms, 0, 2);
        $top3 = array_slice($serviceTerms, 0, 3);

        if (!empty($top2)) {
            $cityPart = ($countryMajorCity !== '' && strtolower($countryMajorCity) !== strtolower($countryName))
                ? "{$countryMajorCity} "
                : '';
            $queries[] = trim(implode(' ', $top2) . " service {$cityPart}{$countryName}");
        }

        // Query C: Crisp commercial agency/provider query anchored to country
        if (!empty($top3)) {
            $queries[] = trim('best ' . implode(' ', array_slice($top3, 0, 2)) . " agency {$countryName}");
        }

        // Query D: Top verified non-branded GSC query anchored to country if fewer than 3
        if (count($queries) < 3 && !empty($gscQueries[0])) {
            $gq = trim((string) $gscQueries[0]);
            if (!str_contains(strtolower($gq), strtolower($countryName))) {
                $gq .= " {$countryName}";
            }
            $queries[] = $gq;
        }

        // Deduplicate case-insensitively and limit to 3 crisp queries
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
            $fallback = trim(str_replace(['-', '_', '.'], ' ', $domainStem) . " {$countryName}");
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
            $candCountry = !empty($meta['matched_countries'][0]) ? strtoupper((string) $meta['matched_countries'][0]) : $run->country;

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
                    'country' => $candCountry,
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
            $nonBrand = array_diff($segWords, $brandTokens);
            if (count($segWords) <= 3 && empty($nonBrand)) {
                continue;
            }
            $kept[] = $segTrim;
        }

        return implode(' ', $kept);
    }

    /**
     * Extract meaningful keywords while deduplicating singular/plural stems (e.g. caregiver + caregivers -> caregiver).
     */
    private function extractKeywords(string $text, array $excludeTokens): array
    {
        $words = preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [];
        $out = [];
        $seenStems = [];
        $excludeSet = array_flip($excludeTokens);

        foreach ($words as $w) {
            if (strlen($w) < 3 || is_numeric($w)) {
                continue;
            }
            if (in_array($w, self::STOP_WORDS, true) || isset($excludeSet[$w])) {
                continue;
            }
            $stem = strlen($w) > 4 ? rtrim($w, 's') : $w;
            if (isset($excludeSet[$stem])) {
                continue;
            }
            if (!isset($seenStems[$stem])) {
                $seenStems[$stem] = true;
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
            $stem = strlen($term) > 4 ? rtrim($term, 's') : $term;
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
