<?php

namespace App\Domain\Competitors\Services;

use App\Domain\Competitors\CompetitorProviderManager;
use App\Models\AiRecommendation;
use App\Models\CompetitorDomain;
use App\Models\CompetitorKeyword;
use App\Models\SearchPerformanceRecord;
use App\Models\Website;
use Illuminate\Support\Collection;

class KeywordGapService
{
    public function __construct(private CompetitorProviderManager $providerManager)
    {
    }

    /**
     * Refresh keyword gap records from external keyword provider (DataForSEO/Semrush) + verified target GSC data.
     * Never invents volume, difficulty, or ranking positions.
     */
    public function syncKeywordGap(
        Website $website,
        ?array $competitorIds = null,
        string $country = 'US',
        string $language = 'en',
        bool $forceRefresh = false
    ): array {
        $country = strtoupper($country);
        $language = strtolower($language);
        $targetDomain = CompetitorDomain::normalizeDomain($website->domain);

        $compQuery = CompetitorDomain::where('website_id', $website->id)->where('is_active', true);
        if (!empty($competitorIds)) {
            $compQuery->whereIn('id', $competitorIds);
        }
        /** @var Collection<int, CompetitorDomain> $competitors */
        $competitors = $compQuery->limit(5)->get();

        $provider = $this->providerManager->resolveForCapability('keywords');
        $providerConnected = $provider->getName() !== 'first_party_crawl';

        // Map keyword (lowercased) => merged record
        $merged = [];

        // 1. Seed verified first-party target rankings from Google Search Console (if present)
        $gscQueries = SearchPerformanceRecord::where('website_id', $website->id)
            ->where('date', '>=', now()->subDays(28)->toDateString())
            ->select('query', 'page')
            ->selectRaw('AVG(position) as avg_pos, SUM(impressions) as total_imp')
            ->whereNotNull('query')
            ->where('query', '!=', '')
            ->groupBy('query', 'page')
            ->orderByDesc('total_imp')
            ->limit(100)
            ->get();

        foreach ($gscQueries as $row) {
            $kwKey = strtolower(trim((string) $row->query));
            if ($kwKey === '') {
                continue;
            }
            $pos = max(1, (int) round((float) $row->avg_pos));
            if (!isset($merged[$kwKey]) || ($merged[$kwKey]['target_position'] ?? 999) > $pos) {
                $merged[$kwKey] = [
                    'keyword' => trim((string) $row->query),
                    'search_volume' => null,
                    'keyword_difficulty' => null,
                    'cpc' => null,
                    'search_intent' => $this->inferIntent($kwKey),
                    'target_position' => $pos,
                    'target_url' => $row->page,
                    'competitor_positions' => [],
                    'serp_features' => [],
                    'data_source' => 'first_party_gsc',
                    'data_classification' => 'first_party_verified',
                ];
            }
        }

        // 2. Fetch external third-party keyword rankings for target & competitors if provider is connected
        if ($providerConnected) {
            $targetRes = $this->providerManager->cachedCall(
                $website,
                'keywords',
                "{$provider->getName()}:kw:{$targetDomain}:{$country}:{$language}",
                "ranked_keywords:{$targetDomain}",
                fn () => $provider->fetchDomainKeywords($targetDomain, $country, $language, 60),
                $forceRefresh
            );
            foreach ($targetRes['keywords'] ?? [] as $item) {
                $kwKey = strtolower(trim((string) ($item['keyword'] ?? '')));
                if ($kwKey === '') {
                    continue;
                }
                if (!isset($merged[$kwKey])) {
                    $merged[$kwKey] = [
                        'keyword' => $item['keyword'],
                        'search_volume' => $item['search_volume'] ?? null,
                        'keyword_difficulty' => $item['keyword_difficulty'] ?? null,
                        'cpc' => $item['cpc'] ?? null,
                        'search_intent' => $item['search_intent'] ?? $this->inferIntent($kwKey),
                        'target_position' => $item['position'] ?? null,
                        'target_url' => $item['url'] ?? null,
                        'competitor_positions' => [],
                        'serp_features' => $item['serp_features'] ?? [],
                        'data_source' => $provider->getName(),
                        'data_classification' => 'third_party_estimate',
                    ];
                } else {
                    $merged[$kwKey]['search_volume'] = $item['search_volume'] ?? $merged[$kwKey]['search_volume'];
                    $merged[$kwKey]['keyword_difficulty'] = $item['keyword_difficulty'] ?? $merged[$kwKey]['keyword_difficulty'];
                    $merged[$kwKey]['cpc'] = $item['cpc'] ?? $merged[$kwKey]['cpc'];
                    $merged[$kwKey]['search_intent'] = $item['search_intent'] ?? $merged[$kwKey]['search_intent'];
                    $merged[$kwKey]['serp_features'] = !empty($item['serp_features']) ? $item['serp_features'] : $merged[$kwKey]['serp_features'];
                    $merged[$kwKey]['data_source'] = $provider->getName();
                    $merged[$kwKey]['data_classification'] = 'hybrid';
                }
            }

            foreach ($competitors as $comp) {
                $compRes = $this->providerManager->cachedCall(
                    $website,
                    'keywords',
                    "{$provider->getName()}:kw:{$comp->domain}:{$country}:{$language}",
                    "ranked_keywords:{$comp->domain}",
                    fn () => $provider->fetchDomainKeywords($comp->domain, $country, $language, 60),
                    $forceRefresh
                );
                foreach ($compRes['keywords'] ?? [] as $item) {
                    $kwKey = strtolower(trim((string) ($item['keyword'] ?? '')));
                    if ($kwKey === '') {
                        continue;
                    }
                    if (!isset($merged[$kwKey])) {
                        $merged[$kwKey] = [
                            'keyword' => $item['keyword'],
                            'search_volume' => $item['search_volume'] ?? null,
                            'keyword_difficulty' => $item['keyword_difficulty'] ?? null,
                            'cpc' => $item['cpc'] ?? null,
                            'search_intent' => $item['search_intent'] ?? $this->inferIntent($kwKey),
                            'target_position' => null,
                            'target_url' => null,
                            'competitor_positions' => [],
                            'serp_features' => $item['serp_features'] ?? [],
                            'data_source' => $provider->getName(),
                            'data_classification' => 'third_party_estimate',
                        ];
                    } else {
                        $merged[$kwKey]['search_volume'] = $merged[$kwKey]['search_volume'] ?? ($item['search_volume'] ?? null);
                        $merged[$kwKey]['keyword_difficulty'] = $merged[$kwKey]['keyword_difficulty'] ?? ($item['keyword_difficulty'] ?? null);
                        $merged[$kwKey]['cpc'] = $merged[$kwKey]['cpc'] ?? ($item['cpc'] ?? null);
                    }

                    $merged[$kwKey]['competitor_positions'][$comp->domain] = [
                        'position' => $item['position'] ?? null,
                        'url' => $item['url'] ?? null,
                    ];
                }
            }
        }

        // 3. Classify each keyword into missing / weak / shared / strong / untapped and persist
        $stored = 0;
        foreach ($merged as $row) {
            $gapType = self::classifyGap(
                $row['target_position'],
                $row['competitor_positions'],
                $row['search_volume'],
                $row['keyword_difficulty']
            );

            $oppScore = self::calculateOpportunityScore(
                $gapType,
                $row['search_volume'],
                $row['keyword_difficulty'],
                $row['target_position'],
                $row['competitor_positions']
            );

            CompetitorKeyword::updateOrCreate(
                [
                    'website_id' => $website->id,
                    'keyword' => $row['keyword'],
                    'country' => $country,
                    'language' => $language,
                ],
                [
                    'organization_id' => $website->organization_id,
                    'search_volume' => $row['search_volume'],
                    'keyword_difficulty' => $row['keyword_difficulty'],
                    'cpc' => $row['cpc'],
                    'search_intent' => $row['search_intent'],
                    'target_position' => $row['target_position'],
                    'target_url' => $row['target_url'],
                    'competitor_positions' => $row['competitor_positions'],
                    'gap_type' => $gapType,
                    'serp_features' => $row['serp_features'],
                    'opportunity_score' => $oppScore,
                    'data_source' => $row['data_source'],
                    'data_classification' => $row['data_classification'],
                    'last_updated_at' => now(),
                ]
            );
            $stored++;
        }

        return [
            'provider' => $provider->getName(),
            'provider_connected' => $providerConnected,
            'keywords_synced' => $stored,
            'competitors_compared' => $competitors->count(),
        ];
    }

    /**
     * Deterministic Keyword Gap classification rule.
     */
    public static function classifyGap(
        ?int $targetPosition,
        array $competitorPositions,
        ?int $searchVolume = null,
        ?int $difficulty = null
    ): string {
        $compRanks = array_values(array_filter(
            array_map(fn ($c) => is_array($c) ? ($c['position'] ?? null) : (is_numeric($c) ? (int) $c : null), $competitorPositions),
            fn ($v) => $v !== null && $v > 0
        ));

        $compCount = count($compRanks);
        $bestComp = $compCount > 0 ? min($compRanks) : null;

        // 1. Missing: target has no ranking, at least 1 competitor ranks
        if ($targetPosition === null && $bestComp !== null) {
            // Untapped if low difficulty or multiple competitors only rank weakly (outside top 10)
            if (($difficulty !== null && $difficulty <= 35 && ($searchVolume === null || $searchVolume >= 100)) || ($compCount === 1 && $bestComp > 10)) {
                return CompetitorKeyword::GAP_UNTAPPED;
            }
            return CompetitorKeyword::GAP_MISSING;
        }

        // 2. Target ranks, no competitor ranks => Strong
        if ($targetPosition !== null && $bestComp === null) {
            return $targetPosition <= 10 ? CompetitorKeyword::GAP_STRONG : CompetitorKeyword::GAP_UNTAPPED;
        }

        // 3. Both target and competitor(s) rank
        if ($targetPosition !== null && $bestComp !== null) {
            if ($targetPosition <= $bestComp && $targetPosition <= 10) {
                return CompetitorKeyword::GAP_STRONG;
            }
            if ($targetPosition >= $bestComp + 5 || ($targetPosition > 10 && $bestComp <= 10)) {
                return CompetitorKeyword::GAP_WEAK;
            }
            return CompetitorKeyword::GAP_SHARED;
        }

        return CompetitorKeyword::GAP_UNTAPPED;
    }

    public static function calculateOpportunityScore(
        string $gapType,
        ?int $searchVolume,
        ?int $difficulty,
        ?int $targetPosition,
        array $competitorPositions
    ): float {
        $base = match ($gapType) {
            CompetitorKeyword::GAP_MISSING => 72.0,
            CompetitorKeyword::GAP_UNTAPPED => 78.0,
            CompetitorKeyword::GAP_WEAK => 68.0,
            CompetitorKeyword::GAP_SHARED => 52.0,
            CompetitorKeyword::GAP_STRONG => 40.0,
            default => 50.0,
        };

        if ($searchVolume !== null) {
            $base += min(18.0, log10(max(10, $searchVolume)) * 4.5);
        }
        if ($difficulty !== null) {
            $base -= ($difficulty * 0.25);
        }
        if ($targetPosition !== null && $targetPosition >= 11 && $targetPosition <= 20) {
            $base += 10.0; // Striking distance page 2 boost
        }
        $base += min(10.0, count($competitorPositions) * 2.5);

        return round(min(100.0, max(5.0, $base)), 2);
    }

    /**
     * Save a keyword gap item directly into the website's SEO Action Plan (ai_recommendations).
     */
    public function saveToActionPlan(Website $website, CompetitorKeyword $kw): AiRecommendation
    {
        $bestCompDomain = null;
        $bestCompPos = null;
        foreach ($kw->competitor_positions ?? [] as $domain => $info) {
            $pos = is_array($info) ? ($info['position'] ?? null) : null;
            if ($pos !== null && ($bestCompPos === null || $pos < $bestCompPos)) {
                $bestCompPos = $pos;
                $bestCompDomain = $domain;
            }
        }

        $actionText = match ($kw->gap_type) {
            CompetitorKeyword::GAP_MISSING, CompetitorKeyword::GAP_UNTAPPED => "Create a dedicated, search-intent-aligned page targeting \"{$kw->keyword}\"" . ($bestCompDomain ? " to close the ranking gap against {$bestCompDomain} (#{$bestCompPos})." : '.'),
            CompetitorKeyword::GAP_WEAK => "Optimize existing page (" . ($kw->target_url ?: $website->target_url) . ") for \"{$kw->keyword}\" (currently #{$kw->target_position} vs {$bestCompDomain} #{$bestCompPos}) by strengthening headings, semantic coverage, and internal links.",
            default => "Defend and expand organic coverage for \"{$kw->keyword}\" with updated content and supporting internal links.",
        };

        $impact = $kw->gap_type === CompetitorKeyword::GAP_MISSING || $kw->gap_type === CompetitorKeyword::GAP_WEAK ? 8 : 7;
        $effort = $kw->gap_type === CompetitorKeyword::GAP_WEAK ? 2 : 4;
        $priorityScore = AiRecommendation::calculatePriority($impact, 8, $effort, 1);

        $rec = AiRecommendation::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'title' => ucfirst($kw->gap_type) . " Keyword Opportunity: \"{$kw->keyword}\"",
            'category' => 'content',
            'affected_url' => $kw->target_url ?: $website->target_url,
            'evidence' => [
                'source' => 'competitor_keyword_gap',
                'data_source' => $kw->data_source,
                'keyword' => $kw->keyword,
                'gap_type' => $kw->gap_type,
                'search_volume' => $kw->search_volume,
                'keyword_difficulty' => $kw->keyword_difficulty,
                'target_position' => $kw->target_position,
                'competitor_positions' => $kw->competitor_positions,
            ],
            'recommended_action' => $actionText,
            'reasoning_summary' => "Identified in Competitor Keyword Gap ({$kw->data_source}): gap type is {$kw->gap_type}" . ($bestCompDomain ? ", with {$bestCompDomain} ranking #{$bestCompPos}." : '.'),
            'expected_benefit' => 'Estimated increase in non-branded organic visibility and qualified search clicks (not a guaranteed ranking).',
            'confidence_level' => 'high',
            'effort' => $effort <= 2 ? 'low' : 'medium',
            'risk_level' => 'low',
            'required_permissions' => 'editor',
            'verification_procedure' => 'Track keyword ranking via SERP Analysis and Google Search Console 14–28 days after publishing.',
            'impact_score' => $impact,
            'confidence_score' => 8,
            'effort_score' => $effort,
            'risk_score' => 1,
            'priority_score' => $priorityScore,
            'status' => AiRecommendation::STATUS_PROPOSED,
        ]);

        $kw->update(['saved_recommendation_id' => $rec->id]);

        return $rec;
    }

    private function inferIntent(string $kw): string
    {
        if (preg_match('/\b(buy|price|pricing|cost|quote|hire|book|order|near me|service|agency|company)\b/i', $kw)) {
            return 'transactional';
        }
        if (preg_match('/\b(best|top|vs|compare|comparison|review|alternative)\b/i', $kw)) {
            return 'commercial';
        }
        if (preg_match('/\b(login|sign in|official|contact|support)\b/i', $kw)) {
            return 'navigational';
        }
        return 'informational';
    }
}
