<?php

namespace App\Domain\KeywordIntelligence\Services;

use App\Domain\Competitors\Support\CountryCatalog;
use App\Domain\ContentStudio\ContentStrategist;
use App\Domain\KeywordIntelligence\Providers\KeywordProviderManager;
use App\Domain\RankChecker\RankCheckerService;
use App\Models\AiRecommendation;
use App\Models\CompetitorKeyword;
use App\Models\KeywordProject;
use App\Models\KiKeyword;
use App\Models\KiKeywordHistory;
use App\Models\KiSeedKeyword;
use App\Models\SearchPerformanceRecord;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

/**
 * Advanced Multi-Source Keyword Discovery Engine.
 *
 * Supports 6 seed input types (single, multiple, list, website URL, business name, business category)
 * and 10 discovery sources (related, suggestions, long_tail, questions, autocomplete, search_related,
 * competitor_keywords, gsc_queries, keyword_gap, topic_ideas).
 * Strictly deduplicates by (website_id, normalized_keyword, country, language) and NEVER fabricates metrics.
 */
class KeywordDiscoveryService
{
    public const ALL_SOURCES = [
        'related',
        'suggestions',
        'long_tail',
        'questions',
        'autocomplete',
        'search_related',
        'competitor_keywords',
        'gsc_queries',
        'keyword_gap',
        'topic_ideas',
    ];

    public function __construct(
        private KeywordProviderManager $providerManager,
        private KeywordIntentService $intentService,
        private KeywordOpportunityService $opportunityService,
        private RankCheckerService $rankCheckerService
    ) {
    }

    /**
     * Execute comprehensive keyword discovery for a website and persist deduplicated records.
     */
    public function discover(Website $website, array $input, ?int $userId = null): array
    {
        $country = CountryCatalog::normalizeCodes(
            [$input['country'] ?? ($website->target_country ?: 'US')],
            $website->target_country ?: 'US',
            1
        )[0];
        $language = strtolower(trim((string) ($input['language'] ?? ($website->preferred_language ?: 'en')))) ?: 'en';
        $searchEngine = strtolower(trim((string) ($input['search_engine'] ?? 'google'))) ?: 'google';
        $device = strtolower(trim((string) ($input['device'] ?? 'desktop'))) === 'mobile' ? 'mobile' : 'desktop';
        $location = trim((string) ($input['location'] ?? '')) ?: null;
        $searchNetwork = strtolower(trim((string) ($input['search_network'] ?? 'google_search'))) ?: 'google_search';
        $sources = !empty($input['sources']) && is_array($input['sources'])
            ? array_values(array_intersect($input['sources'], self::ALL_SOURCES))
            : self::ALL_SOURCES;
        if (empty($sources)) {
            $sources = self::ALL_SOURCES;
        }

        // Ensure KeywordProject exists for this website
        $project = KeywordProject::firstOrCreate(
            ['website_id' => $website->id],
            [
                'organization_id' => $website->organization_id,
                'created_by_user_id' => $userId,
                'name' => $website->name . ' Keyword Intelligence',
                'target_country' => $country,
                'target_language' => $language,
                'search_engine' => $searchEngine,
                'search_location' => $location,
                'device' => $device,
                'search_network' => $searchNetwork,
                'business_name' => $input['business_name'] ?? $website->name,
                'business_category' => $input['business_category'] ?? null,
            ]
        );

        // Build normalized seed list from all 6 input modes
        $seedMode = (string) ($input['seed_type'] ?? 'keyword');
        $seeds = $this->extractSeeds($website, $input);
        $directListKeywords = $this->extractDirectKeywordList($input);

        $rawCandidates = [];
        $providersUsed = [];
        $providerErrors = [];

        // 0. If user pasted an explicit keyword list, include those exact terms first
        foreach ($directListKeywords as $lk) {
            $rawCandidates[] = [
                'keyword' => $lk,
                'discovery_source' => 'suggestions',
                'data_source' => 'user_seed_list',
                'metric_classification' => 'calculated',
                'search_volume' => null,
                'keyword_difficulty' => null,
                'cpc' => null,
                'paid_competition' => null,
                'competition_level' => null,
                'search_intent' => null,
                'intent_source' => null,
                'trend_data' => null,
                'trend_direction' => 'unavailable',
                'serp_features' => null,
                'serp_top_urls' => null,
            ];
        }

        // 1. Third-party volume/metric providers (DataForSEO v3 / Semrush) when configured
        $limitedSeeds = array_slice($seeds, 0, 5);
        $needsExternalSuggest = (bool) array_intersect($sources, ['related', 'suggestions', 'long_tail', 'questions', 'autocomplete', 'topic_ideas']);
        $needsSerp = (bool) array_intersect($sources, ['search_related', 'questions']);

        if ($needsExternalSuggest) {
            foreach ($limitedSeeds as $seed) {
                // DataForSEO Labs (volume, KD, CPC, intent, trends)
                if ($this->providerManager->getDataForSeo()->isConfigured($website->organization_id)) {
                    $dfsRes = $this->providerManager->executeDiscoveryCall(
                        $this->providerManager->getDataForSeo(),
                        $website,
                        $seed,
                        $country,
                        $language,
                        ['sources' => $sources, 'limit' => (int) ($input['limit_per_seed'] ?? 40)],
                        $userId
                    );
                    if ($dfsRes['available'] ?? false) {
                        $providersUsed['dataforseo'] = true;
                        $rawCandidates = array_merge($rawCandidates, $dfsRes['keywords'] ?? []);
                    } elseif (!empty($dfsRes['error'])) {
                        $providerErrors['dataforseo'] = $dfsRes['error'];
                    }
                } elseif ($this->providerManager->getSemrush()->isConfigured($website->organization_id)) {
                    $semRes = $this->providerManager->executeDiscoveryCall(
                        $this->providerManager->getSemrush(),
                        $website,
                        $seed,
                        $country,
                        $language,
                        ['sources' => $sources, 'limit' => 40],
                        $userId
                    );
                    if ($semRes['available'] ?? false) {
                        $providersUsed['semrush'] = true;
                        $rawCandidates = array_merge($rawCandidates, $semRes['keywords'] ?? []);
                    } elseif (!empty($semRes['error'])) {
                        $providerErrors['semrush'] = $semRes['error'];
                    }
                }

                // Live Google Autocomplete (free real search suggestions, questions & long-tail modifiers)
                if (empty($input['skip_external_http'])) {
                    $acRes = $this->providerManager->executeDiscoveryCall(
                        $this->providerManager->getAutocomplete(),
                        $website,
                        $seed,
                        $country,
                        $language,
                        ['sources' => $sources, 'max_suggest_queries' => count($limitedSeeds) > 2 ? 6 : 10],
                        $userId
                    );
                    if ($acRes['available'] ?? false) {
                        $providersUsed['google_autocomplete'] = true;
                        $rawCandidates = array_merge($rawCandidates, $acRes['keywords'] ?? []);
                    } elseif (!empty($acRes['error'])) {
                        $providerErrors['google_autocomplete'] = $acRes['error'];
                    }
                }
            }
        }

        // 2. Website URL / Domain Seed via DataForSEO if URL input provided
        if (!empty($input['website_url']) && $this->providerManager->getDataForSeo()->isConfigured($website->organization_id)) {
            $domRes = $this->providerManager->getDataForSeo()->fetchDomainKeywordIdeas(
                (string) $input['website_url'],
                $country,
                $language,
                40
            );
            if ($domRes['available'] ?? false) {
                $providersUsed['dataforseo'] = true;
                $rawCandidates = array_merge($rawCandidates, $domRes['keywords'] ?? []);
            }
        }

        // 3. Serper.dev live Google SERP (People Also Ask, Related Searches, SERP features, Top-10 URLs)
        if ($needsSerp && empty($input['skip_external_http']) && $this->providerManager->getSerper()->isConfigured($website->organization_id)) {
            // Limit live SERP discovery calls to first 2 seeds to keep credit usage respectful
            foreach (array_slice($limitedSeeds, 0, 2) as $seed) {
                $serperRes = $this->providerManager->executeDiscoveryCall(
                    $this->providerManager->getSerper(),
                    $website,
                    $seed,
                    $country,
                    $language,
                    ['device' => $device, 'location' => $location],
                    $userId
                );
                if ($serperRes['available'] ?? false) {
                    $providersUsed['serper'] = true;
                    $rawCandidates = array_merge($rawCandidates, $serperRes['keywords'] ?? []);
                } elseif (!empty($serperRes['error'])) {
                    $providerErrors['serper'] = $serperRes['error'];
                }
            }
        }

        // 4. First-Party Google Search Console Queries
        if (in_array('gsc_queries', $sources, true)) {
            $gscCandidates = $this->collectGscCandidates($website, $limitedSeeds);
            if (!empty($gscCandidates)) {
                $providersUsed['gsc'] = true;
                $rawCandidates = array_merge($rawCandidates, $gscCandidates);
            }
        }

        // 5. Tracked Competitor Keywords & Keyword Gap Opportunities
        if (in_array('competitor_keywords', $sources, true) || in_array('keyword_gap', $sources, true)) {
            $compCandidates = $this->collectCompetitorCandidates($website, $limitedSeeds, $sources);
            if (!empty($compCandidates)) {
                $providersUsed['competitor_gap'] = true;
                $rawCandidates = array_merge($rawCandidates, $compCandidates);
            }
        }

        // 6. Topic-based / Seed base phrases (guarantee user's own seed terms are recorded even if offline/blocked)
        if (in_array('topic_ideas', $sources, true) || empty($rawCandidates)) {
            foreach ($limitedSeeds as $seed) {
                $rawCandidates[] = [
                    'keyword' => $seed,
                    'discovery_source' => 'topic_ideas',
                    'data_source' => 'seed_input',
                    'metric_classification' => 'calculated',
                    'search_volume' => null,
                    'keyword_difficulty' => null,
                    'cpc' => null,
                    'paid_competition' => null,
                    'competition_level' => null,
                    'search_intent' => null,
                    'intent_source' => null,
                    'trend_data' => null,
                    'trend_direction' => 'unavailable',
                    'serp_features' => null,
                    'serp_top_urls' => null,
                ];
            }
        }

        // Merge candidates by normalized keyword (preserving richest provider metrics without duplicating!)
        $mergedByNorm = $this->mergeCandidatesByNormalizedKeyword($rawCandidates);

        // Load first-party GSC map + competitor coverage map to enrich ALL merged keywords accurately
        $gscMap = $this->buildGscLookupMap($website);
        $competitorCoverageMap = $this->buildCompetitorCoverageMap($website);
        $weights = $this->opportunityService->getWeights($website);
        $contextTerms = $this->opportunityService->extractWebsiteContextTerms($website);
        $crawledTitles = $this->opportunityService->extractCrawledTitles($website);

        // Record Seed Keyword audit entry
        $seedRecord = KiSeedKeyword::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'project_id' => $project->id,
            'seed_text' => mb_substr(implode(', ', array_slice($seeds, 0, 5)), 0, 250) ?: $website->domain,
            'seed_type' => $seedMode,
            'country' => $country,
            'language' => $language,
            'sources_used' => array_keys($providersUsed),
            'discovered_count' => count($mergedByNorm),
            'last_researched_at' => now(),
        ]);

        $newCount = 0;
        $updatedCount = 0;
        $persistedIds = [];

        DB::transaction(function () use (
            $mergedByNorm,
            $website,
            $project,
            $seedRecord,
            $country,
            $language,
            $searchEngine,
            $device,
            $location,
            $gscMap,
            $competitorCoverageMap,
            $weights,
            $contextTerms,
            $crawledTitles,
            &$newCount,
            &$updatedCount,
            &$persistedIds
        ) {
            foreach ($mergedByNorm as $norm => $item) {
                $kwText = mb_substr(trim((string) $item['keyword']), 0, 250);
                if ($kwText === '') {
                    continue;
                }

                $qCat = $this->intentService->detectQuestionCategory($kwText);
                $wordCount = $this->intentService->countWords($kwText);
                $isLongTail = $this->intentService->isLongTail($kwText, $qCat);
                $intentInfo = $this->intentService->classifyKeyword($kwText, $item['search_intent'] ?? null, $website->name);

                $gscRow = $gscMap[$norm] ?? null;
                $compCount = (int) ($competitorCoverageMap[$norm] ?? 0);

                $existing = KiKeyword::where('website_id', $website->id)
                    ->where('normalized_keyword', $norm)
                    ->where('country', $country)
                    ->where('language', $language)
                    ->first();

                $currentPosition = $item['target_position'] ?? ($gscRow['avg_position'] ?? ($existing?->current_position));
                $rankingUrl = $item['ranking_url'] ?? ($gscRow['top_url'] ?? ($existing?->ranking_url));
                $gscClicks = $gscRow['clicks'] ?? $existing?->gsc_clicks;
                $gscImpressions = $gscRow['impressions'] ?? $existing?->gsc_impressions;
                $gscCtr = $gscRow['ctr'] ?? $existing?->gsc_ctr;

                $volume = $item['search_volume'] ?? $existing?->search_volume;
                $kd = $item['keyword_difficulty'] ?? $existing?->keyword_difficulty;
                $cpc = $item['cpc'] ?? $existing?->cpc;
                $paidComp = $item['paid_competition'] ?? $existing?->paid_competition;
                $compLevel = $item['competition_level'] ?? $existing?->competition_level;
                $trendData = $item['trend_data'] ?? $existing?->trend_data;
                $trendDirection = ($item['trend_direction'] && $item['trend_direction'] !== 'unavailable')
                    ? $item['trend_direction']
                    : ($existing?->trend_direction ?? 'unavailable');
                $serpFeatures = !empty($item['serp_features']) ? array_values(array_unique($item['serp_features'])) : $existing?->serp_features;
                $serpTopUrls = !empty($item['serp_top_urls']) ? $item['serp_top_urls'] : $existing?->serp_top_urls;

                // Preserve provider-supplied intent if existing had provider intent and new doesn't
                if ($existing && $existing->intent_source === 'provider' && $intentInfo['intent_source'] !== 'provider') {
                    $intentInfo = [
                        'search_intent' => $existing->search_intent,
                        'intent_source' => 'provider',
                        'intent_confidence' => $existing->intent_confidence ?? 0.92,
                    ];
                }

                $metricClass = $item['metric_classification'] ?? 'calculated';
                if ($gscRow && $volume === null && ($item['data_source'] ?? '') === 'gsc') {
                    $metricClass = 'first_party_gsc';
                }

                $attrs = [
                    'organization_id' => $website->organization_id,
                    'website_id' => $website->id,
                    'project_id' => $project->id,
                    'seed_keyword_id' => $existing?->seed_keyword_id ?? $seedRecord->id,
                    'keyword' => $kwText,
                    'normalized_keyword' => $norm,
                    'country' => $country,
                    'language' => $language,
                    'search_engine' => $searchEngine,
                    'device' => $device,
                    'location' => $location,
                    'discovery_source' => $existing?->discovery_source ?? ($item['discovery_source'] ?? 'suggestions'),
                    'question_category' => $qCat,
                    'is_long_tail' => $isLongTail,
                    'word_count' => $wordCount,
                    'search_volume' => $volume,
                    'keyword_difficulty' => $kd,
                    'cpc' => $cpc,
                    'paid_competition' => $paidComp,
                    'competition_level' => $compLevel,
                    'search_intent' => $intentInfo['search_intent'],
                    'intent_source' => $intentInfo['intent_source'],
                    'intent_confidence' => $intentInfo['intent_confidence'],
                    'trend_data' => $trendData,
                    'trend_direction' => $trendDirection,
                    'serp_features' => $serpFeatures,
                    'serp_top_urls' => $serpTopUrls,
                    'previous_position' => ($existing && $existing->current_position !== null && $currentPosition !== null && (float) $existing->current_position !== (float) $currentPosition)
                        ? $existing->current_position
                        : ($existing?->previous_position),
                    'current_position' => $currentPosition,
                    'ranking_url' => $rankingUrl,
                    'gsc_clicks' => $gscClicks,
                    'gsc_impressions' => $gscImpressions,
                    'gsc_ctr' => $gscCtr,
                    'competitor_coverage_count' => max($compCount, (int) ($existing?->competitor_coverage_count ?? 0)),
                    'data_source' => $item['data_source'] ?? ($existing?->data_source ?? 'google_autocomplete'),
                    'metric_classification' => $metricClass,
                    'last_updated_at' => now(),
                ];

                $scored = $this->opportunityService->scoreKeywordAttributes($attrs, $weights, $contextTerms, $crawledTitles);
                $attrs = array_merge($attrs, $scored);

                if ($existing) {
                    $existing->update($attrs);
                    $model = $existing;
                    $updatedCount++;
                } else {
                    $model = KiKeyword::create($attrs);
                    $newCount++;
                }

                $persistedIds[] = $model->id;

                // Snapshot into keyword history
                KiKeywordHistory::create([
                    'organization_id' => $website->organization_id,
                    'website_id' => $website->id,
                    'keyword_id' => $model->id,
                    'search_volume' => $model->search_volume,
                    'keyword_difficulty' => $model->keyword_difficulty,
                    'cpc' => $model->cpc,
                    'position' => $model->current_position,
                    'ranking_url' => $model->ranking_url,
                    'gsc_clicks' => $model->gsc_clicks,
                    'gsc_impressions' => $model->gsc_impressions,
                    'data_source' => $model->data_source,
                    'captured_at' => now(),
                ]);
            }
        });

        $seedRecord->update(['discovered_count' => count($persistedIds)]);

        $keywords = KiKeyword::whereIn('id', $persistedIds)
            ->orderByDesc('opportunity_score')
            ->limit(100)
            ->get();

        return [
            'seed_id' => $seedRecord->id,
            'seeds_used' => $limitedSeeds,
            'country' => $country,
            'language' => $language,
            'total_discovered' => count($persistedIds),
            'new_keywords' => $newCount,
            'updated_keywords' => $updatedCount,
            'providers_used' => array_keys($providersUsed),
            'provider_errors' => $providerErrors,
            'provider_status' => $this->providerManager->getProviderStatus($website),
            'keywords' => $keywords,
        ];
    }

    /**
     * Fetch live SERP results & competitor rankings for a single persisted KiKeyword.
     */
    public function inspectKeywordSerp(Website $website, KiKeyword $keyword, ?int $userId = null, bool $forceRefresh = false): array
    {
        $serp = $this->providerManager->getSerper()->fetchSerpData(
            $keyword->keyword,
            $keyword->country,
            $keyword->language,
            $keyword->device,
            $keyword->location,
            $website->organization_id,
            $website,
            $userId,
            $forceRefresh
        );

        if (!($serp['available'] ?? false)) {
            return [
                'available' => false,
                'keyword' => $keyword->keyword,
                'serp_features' => $keyword->serp_features ?? [],
                'organic_results' => $keyword->serp_top_urls ?? [],
                'error' => $serp['error'] ?? 'Live SERP provider is not available.',
            ];
        }

        $topUrls = array_slice(array_map(fn ($r) => [
            'position' => (int) ($r['position'] ?? 0),
            'domain' => (string) ($r['domain'] ?? ''),
            'url' => (string) ($r['url'] ?? ''),
            'title' => (string) ($r['title'] ?? ''),
            'snippet' => (string) ($r['snippet'] ?? ''),
        ], $serp['organic_results'] ?? []), 0, 10);

        $keyword->update([
            'serp_features' => $serp['serp_features'] ?? [],
            'serp_top_urls' => $topUrls,
            'previous_position' => $keyword->current_position !== null && isset($serp['target_position']) && (float) $keyword->current_position !== (float) $serp['target_position']
                ? $keyword->current_position
                : $keyword->previous_position,
            'current_position' => $serp['target_position'] ?? $keyword->current_position,
            'ranking_url' => $serp['ranking_url'] ?? $keyword->ranking_url,
            'last_updated_at' => now(),
        ]);

        return [
            'available' => true,
            'keyword' => $keyword->keyword,
            'provider' => $serp['provider'] ?? 'serper',
            'from_cache' => (bool) ($serp['from_cache'] ?? false),
            'target_position' => $serp['target_position'] ?? null,
            'ranking_url' => $serp['ranking_url'] ?? null,
            'serp_features' => $serp['serp_features'] ?? [],
            'organic_results' => $topUrls,
            'people_also_ask' => $serp['people_also_ask'] ?? [],
            'related_searches' => $serp['related_searches'] ?? [],
        ];
    }

    /**
     * Save a KiKeyword opportunity directly into the existing SEO Action Plan (`ai_recommendations`).
     */
    public function sendToActionPlan(Website $website, KiKeyword $keyword, ?int $userId = null): AiRecommendation
    {
        if ($keyword->saved_recommendation_id) {
            $existing = AiRecommendation::find($keyword->saved_recommendation_id);
            if ($existing) {
                return $existing;
            }
        }

        $impact = match ($keyword->recommended_priority) {
            'P1' => 9,
            'P2' => 7,
            'P3' => 5,
            default => 4,
        };
        $confidence = match ($keyword->data_confidence) {
            'high' => 9,
            'medium' => 7,
            default => 5,
        };
        $effort = $keyword->keyword_difficulty !== null
            ? max(2, min(9, (int) round($keyword->keyword_difficulty / 11)))
            : 5;
        $risk = 2;

        $rec = AiRecommendation::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'title' => "Target keyword opportunity: \"{$keyword->keyword}\"",
            'category' => 'keyword_opportunity',
            'affected_url' => $keyword->ranking_url ?: "https://{$website->domain}",
            'evidence' => [
                'keyword' => $keyword->keyword,
                'country' => $keyword->country,
                'language' => $keyword->language,
                'search_volume' => $keyword->search_volume,
                'keyword_difficulty' => $keyword->keyword_difficulty,
                'cpc' => $keyword->cpc,
                'search_intent' => $keyword->search_intent,
                'intent_source' => $keyword->intent_source,
                'opportunity_score' => $keyword->opportunity_score,
                'opportunity_category' => $keyword->opportunity_category,
                'current_position' => $keyword->current_position,
                'gsc_impressions' => $keyword->gsc_impressions,
                'data_source' => $keyword->data_source,
                'data_confidence' => $keyword->data_confidence,
            ],
            'recommended_action' => $keyword->ranking_url
                ? "Optimize existing page ({$keyword->ranking_url}) title, H1/H2 structure, internal links, and intent alignment for \"{$keyword->keyword}\"."
                : "Create a dedicated {$keyword->search_intent}-intent page or section targeting \"{$keyword->keyword}\" via Content Studio.",
            'reasoning_summary' => $keyword->opportunity_reason ?: "Identified via Keyword Intelligence ({$keyword->opportunity_category}).",
            'expected_benefit' => "Capture targeted {$keyword->search_intent} organic visibility ({$keyword->ranking_potential}).",
            'confidence_level' => $keyword->data_confidence,
            'effort' => $effort >= 7 ? 'high' : ($effort >= 4 ? 'medium' : 'low'),
            'risk_level' => 'low',
            'required_permissions' => 'editor',
            'verification_procedure' => "Verify target page publishes intent-aligned content for \"{$keyword->keyword}\" and monitor impressions/position via GSC and Rank Checker.",
            'impact_score' => $impact,
            'confidence_score' => $confidence,
            'effort_score' => $effort,
            'risk_score' => $risk,
            'priority_score' => AiRecommendation::calculatePriority($impact, $confidence, $effort, $risk),
            'status' => AiRecommendation::STATUS_PROPOSED,
        ]);

        $keyword->update([
            'is_saved' => true,
            'saved_recommendation_id' => $rec->id,
        ]);

        return $rec;
    }

    /**
     * Send a keyword to the existing Content Studio as a draft ContentBrief (never auto-published).
     */
    public function sendToContentStudio(Website $website, KiKeyword $keyword, ?int $userId = null)
    {
        $strategist = new ContentStrategist($website);
        $brief = $strategist->generateBrief(
            $keyword->keyword,
            $keyword->search_intent ?: 'informational',
            $keyword->is_long_tail ? 1400 : 1800,
            $userId
        );

        $keyword->update([
            'is_saved' => true,
            'saved_brief_id' => $brief->id,
        ]);

        return $brief;
    }

    /**
     * Trigger monitoring via the EXISTING RankCheckerService (zero duplicate rank tracker).
     */
    public function monitorInRankChecker(Website $website, KiKeyword $keyword, ?int $userId = null): array
    {
        $res = $this->rankCheckerService->checkRank(
            $website,
            $keyword->keyword,
            $keyword->country,
            $keyword->language,
            $keyword->device,
            $keyword->location,
            30,
            true,
            false,
            true,
            $userId
        );

        $updates = ['is_monitored' => true];
        if (!empty($res['available'])) {
            $pos = $res['target_ranking']['position'] ?? null;
            $url = $res['target_ranking']['primary_match']['url'] ?? null;
            if ($pos !== null) {
                if ($keyword->current_position !== null && (float) $keyword->current_position !== (float) $pos) {
                    $updates['previous_position'] = $keyword->current_position;
                }
                $updates['current_position'] = $pos;
            }
            if ($url) {
                $updates['ranking_url'] = $url;
            }
            if (!empty($res['serp_features'])) {
                $updates['serp_features'] = $res['serp_features'];
            }
        }
        $keyword->update($updates);

        return $res;
    }

    private function extractSeeds(Website $website, array $input): array
    {
        $seeds = [];
        if (!empty($input['seed_keyword'])) {
            $seeds[] = trim((string) $input['seed_keyword']);
        }
        if (!empty($input['seed_keywords']) && is_array($input['seed_keywords'])) {
            foreach ($input['seed_keywords'] as $s) {
                $clean = trim((string) $s);
                if ($clean !== '') {
                    $seeds[] = $clean;
                }
            }
        }
        if (!empty($input['business_category'])) {
            $seeds[] = trim((string) $input['business_category']);
        }
        if (!empty($input['business_name'])) {
            $seeds[] = trim((string) $input['business_name']);
        }
        if (!empty($input['website_url'])) {
            $host = parse_url(str_contains((string) $input['website_url'], '://') ? (string) $input['website_url'] : 'https://' . $input['website_url'], PHP_URL_HOST) ?: (string) $input['website_url'];
            $slug = trim(str_replace(['www.', '-', '_', '.com', '.org', '.net', '.io', '.co', '.ai'], ' ', strtolower($host)));
            if ($slug !== '') {
                $seeds[] = preg_replace('/\s+/', ' ', $slug);
            }
        }

        if (empty($seeds)) {
            $domainBase = trim(str_replace(['www.', '-', '_', '.com', '.org', '.net', '.io', '.co', '.ai'], ' ', strtolower($website->domain)));
            $seeds[] = $domainBase ?: $website->name;
        }

        return array_values(array_unique(array_filter(array_map(fn ($s) => trim(preg_replace('/\s+/', ' ', (string) $s)), $seeds))));
    }

    private function extractDirectKeywordList(array $input): array
    {
        if (empty($input['keyword_list'])) {
            return [];
        }
        $raw = is_array($input['keyword_list'])
            ? $input['keyword_list']
            : (preg_split('/[\r\n,]+/', (string) $input['keyword_list']) ?: []);

        return array_slice(array_values(array_unique(array_filter(array_map('trim', $raw)))), 0, 100);
    }

    private function collectGscCandidates(Website $website, array $seeds): array
    {
        $query = SearchPerformanceRecord::where('website_id', $website->id)
            ->whereDate('date', '>=', now()->subDays(90)->toDateString())
            ->whereNotNull('query')
            ->where('query', '!=', '')
            ->select('query')
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions * 1.0) / SUM(impressions) ELSE 0 END as avg_pos, MAX(page) as top_url')
            ->groupBy('query')
            ->orderByDesc('impressions')
            ->limit(100)
            ->get();

        $seedTokens = [];
        foreach ($seeds as $s) {
            foreach (preg_split('/\s+/', strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
                if (mb_strlen($t) >= 3) {
                    $seedTokens[] = $t;
                }
            }
        }
        $seedTokens = array_unique($seedTokens);

        $results = [];
        foreach ($query as $row) {
            $qText = trim((string) $row->query);
            if ($qText === '') {
                continue;
            }
            $lowerQ = strtolower($qText);
            // Include matching queries + top 25 overall GSC queries
            $matchesSeed = empty($seedTokens);
            foreach ($seedTokens as $t) {
                if (str_contains($lowerQ, $t)) {
                    $matchesSeed = true;
                    break;
                }
            }
            if (!$matchesSeed && count($results) >= 25) {
                continue;
            }

            $results[] = [
                'keyword' => $qText,
                'discovery_source' => 'gsc_queries',
                'data_source' => 'gsc',
                'metric_classification' => 'first_party_gsc',
                'search_volume' => null, // GSC provides impressions, not global monthly search volume
                'keyword_difficulty' => null,
                'cpc' => null,
                'paid_competition' => null,
                'competition_level' => null,
                'search_intent' => null,
                'intent_source' => null,
                'trend_data' => null,
                'trend_direction' => 'unavailable',
                'serp_features' => null,
                'serp_top_urls' => null,
                'target_position' => round((float) $row->avg_pos, 1),
                'ranking_url' => $row->top_url,
            ];
        }

        return $results;
    }

    private function collectCompetitorCandidates(Website $website, array $seeds, array $sources): array
    {
        $rows = CompetitorKeyword::where('website_id', $website->id)
            ->orderByDesc('search_volume')
            ->limit(100)
            ->get();

        $results = [];
        foreach ($rows as $ck) {
            $isGap = $ck->target_position === null || $ck->gap_type === 'missing';
            if ($isGap && !in_array('keyword_gap', $sources, true) && !in_array('competitor_keywords', $sources, true)) {
                continue;
            }

            $results[] = [
                'keyword' => $ck->keyword,
                'discovery_source' => $isGap ? 'keyword_gap' : 'competitor_keywords',
                'data_source' => $ck->data_source ?: 'competitor_analysis',
                'metric_classification' => 'third_party_estimated',
                'search_volume' => $ck->search_volume,
                'keyword_difficulty' => $ck->keyword_difficulty,
                'cpc' => $ck->cpc,
                'paid_competition' => null,
                'competition_level' => null,
                'search_intent' => $ck->search_intent,
                'intent_source' => $ck->search_intent ? 'provider' : null,
                'trend_data' => null,
                'trend_direction' => 'unavailable',
                'serp_features' => $ck->serp_features,
                'serp_top_urls' => null,
                'target_position' => $ck->target_position,
            ];
        }

        return $results;
    }

    private function mergeCandidatesByNormalizedKeyword(array $candidates): array
    {
        $merged = [];
        foreach ($candidates as $item) {
            $kw = trim((string) ($item['keyword'] ?? ''));
            $norm = KiKeyword::normalize($kw);
            if ($norm === '' || mb_strlen($norm) < 2) {
                continue;
            }

            if (!isset($merged[$norm])) {
                $merged[$norm] = $item;
                continue;
            }

            // Enrich existing entry with any non-null verified fields from additional sources
            $cur = $merged[$norm];
            foreach (['search_volume', 'keyword_difficulty', 'cpc', 'paid_competition', 'competition_level', 'trend_data', 'serp_top_urls', 'target_position', 'ranking_url'] as $field) {
                if (($cur[$field] ?? null) === null && ($item[$field] ?? null) !== null) {
                    $cur[$field] = $item[$field];
                }
            }
            if (($cur['intent_source'] ?? null) !== 'provider' && ($item['intent_source'] ?? null) === 'provider') {
                $cur['search_intent'] = $item['search_intent'];
                $cur['intent_source'] = 'provider';
            }
            if (($cur['trend_direction'] ?? 'unavailable') === 'unavailable' && !empty($item['trend_direction']) && $item['trend_direction'] !== 'unavailable') {
                $cur['trend_direction'] = $item['trend_direction'];
            }
            if (!empty($item['serp_features'])) {
                $cur['serp_features'] = array_values(array_unique(array_merge($cur['serp_features'] ?? [], $item['serp_features'])));
            }
            // Prefer authoritative data source label when volume metrics came from DataForSEO/Semrush
            if (in_array($item['data_source'] ?? '', ['dataforseo', 'semrush'], true)) {
                $cur['data_source'] = $item['data_source'];
            }
            $merged[$norm] = $cur;
        }
        return $merged;
    }

    private function buildGscLookupMap(Website $website): array
    {
        $rows = SearchPerformanceRecord::where('website_id', $website->id)
            ->whereDate('date', '>=', now()->subDays(90)->toDateString())
            ->whereNotNull('query')
            ->where('query', '!=', '')
            ->select('query')
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions * 1.0) / SUM(impressions) ELSE 0 END as avg_pos, MAX(page) as top_url')
            ->groupBy('query')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $norm = KiKeyword::normalize((string) $r->query);
            if ($norm === '') {
                continue;
            }
            $clicks = (int) $r->clicks;
            $impressions = (int) $r->impressions;
            $map[$norm] = [
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                'avg_position' => $impressions > 0 ? round((float) $r->avg_pos, 1) : null,
                'top_url' => $r->top_url,
            ];
        }
        return $map;
    }

    private function buildCompetitorCoverageMap(Website $website): array
    {
        $rows = CompetitorKeyword::where('website_id', $website->id)
            ->get(['keyword', 'competitor_positions']);

        $map = [];
        foreach ($rows as $r) {
            $norm = KiKeyword::normalize((string) $r->keyword);
            if ($norm !== '') {
                $positions = is_array($r->competitor_positions) ? $r->competitor_positions : [];
                $map[$norm] = max($map[$norm] ?? 0, count($positions));
            }
        }
        return $map;
    }
}
