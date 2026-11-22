<?php

namespace App\Domain\Competitors\Services;

use App\Domain\Competitors\CompetitorProviderManager;
use App\Models\CompetitorDiscoveryRun;
use App\Models\CompetitorDomain;
use App\Models\SearchPerformanceRecord;
use App\Models\Website;
use Illuminate\Support\Str;

class CompetitorDiscoveryService
{
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
        $industry = $industryContext ?: ($profile?->business_description ? Str::limit($profile->business_description, 120, '') : null);

        // Gather verified first-party GSC top queries as seed context for SERP overlap
        $seedKeywords = SearchPerformanceRecord::where('website_id', $website->id)
            ->select('query')
            ->selectRaw('SUM(impressions) as total_imp')
            ->whereNotNull('query')
            ->where('query', '!=', '')
            ->groupBy('query')
            ->orderByDesc('total_imp')
            ->limit(5)
            ->pluck('query')
            ->toArray();

        $provider = $this->providerManager->resolveForCapability('discovery');
        $result = $this->providerManager->cachedCall(
            $website,
            'discovery',
            "{$provider->getName()}:{$targetDomain}:{$country}:{$language}:" . md5(implode(',', $seedKeywords)),
            "discover:{$targetDomain}",
            fn () => $provider->discoverCompetitors($targetDomain, $country, $language, [
                'industry' => $industry,
                'seed_keywords' => $seedKeywords,
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
            if ($candDomain === '' || $candDomain === $targetDomain) {
                continue;
            }

            $isPortal = $this->isPortalDomain($candDomain, $portalDomains);
            $sharedKw = isset($cand['shared_keywords']) ? (int) $cand['shared_keywords'] : null;
            $overlapPct = isset($cand['serp_overlap_pct']) ? (float) $cand['serp_overlap_pct'] : null;

            // Intelligent classification: NEVER classify a generic directory/social/single-keyword portal as a direct business competitor
            if ($isPortal) {
                $category = CompetitorDomain::CATEGORY_INDIRECT;
                $confidence = min(55, max(25, (int) round(($overlapPct ?? 30) * 0.6)));
                $reason = "Excluded from Direct Business Competitors because {$candDomain} is a broad portal/directory/media domain rather than a direct business rival.";
            } elseif (($cand['source'] ?? '') === 'brand_profile') {
                $category = CompetitorDomain::CATEGORY_DIRECT;
                $confidence = 90;
                $reason = 'Listed explicitly in your verified Website Brand Context Profile.';
            } elseif (($sharedKw !== null && $sharedKw >= 5) || ($overlapPct !== null && $overlapPct >= 35)) {
                $category = CompetitorDomain::CATEGORY_DIRECT;
                $confidence = min(95, max(65, (int) round(55 + ($overlapPct ?? 20) * 0.4)));
                $reason = "Strong multi-keyword SERP overlap ({$sharedKw} shared organic keywords, {$overlapPct}% overlap) in {$country}/{$language}.";
            } elseif ($sharedKw !== null && $sharedKw >= 2) {
                $category = CompetitorDomain::CATEGORY_ORGANIC;
                $confidence = min(80, max(50, (int) round(45 + ($overlapPct ?? 15) * 0.4)));
                $reason = "Competes across {$sharedKw} overlapping organic search queries without broad site-wide direct overlap.";
            } else {
                $category = CompetitorDomain::CATEGORY_CONTENT;
                $confidence = 48;
                $reason = 'Single-query or editorial overlap detected; classified as Content Competitor rather than Direct Business Competitor.';
            }

            $evaluatedCandidates[] = [
                'domain' => $candDomain,
                'category' => $category,
                'confidence' => $confidence,
                'is_portal_filtered' => $isPortal,
                'shared_keywords' => $sharedKw,
                'serp_overlap_pct' => $overlapPct,
                'organic_traffic_estimate' => $cand['organic_traffic_estimate'] ?? null,
                'sample_urls' => $cand['sample_urls'] ?? [],
                'relevance_reason' => $reason,
                'source' => $cand['source'] ?? $provider->getName(),
                'already_tracked' => in_array($candDomain, $existingDomains, true),
                'confirmed' => false,
            ];
        }

        usort($evaluatedCandidates, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        $methodology = (string) ($result['methodology'] ?? '');
        if ($methodology === '') {
            $methodology = $provider->getName() === 'first_party_crawl'
                ? 'First-party Brand Context discovery (connect DataForSEO, Semrush, or SerpApi to unlock automated SERP overlap discovery).'
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
            'status' => ($result['available'] ?? false) || !empty($evaluatedCandidates) ? 'completed' : 'completed',
            'candidates' => $evaluatedCandidates,
            'error_message' => $result['error'] ?? null,
            'completed_at' => now(),
        ]);
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
                }
            }
            unset($c);
        }

        $run->update(['candidates' => $updatedCandidates]);

        return $added;
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
