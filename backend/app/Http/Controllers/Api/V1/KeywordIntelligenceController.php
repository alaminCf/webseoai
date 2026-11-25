<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\KeywordIntelligence\Providers\KeywordProviderManager;
use App\Domain\KeywordIntelligence\Services\KeywordAutomationService;
use App\Domain\KeywordIntelligence\Services\KeywordCannibalizationService;
use App\Domain\KeywordIntelligence\Services\KeywordClusteringService;
use App\Domain\KeywordIntelligence\Services\KeywordDiscoveryService;
use App\Domain\KeywordIntelligence\Services\KeywordIntentService;
use App\Domain\KeywordIntelligence\Services\KeywordOpportunityService;
use App\Domain\KeywordIntelligence\Services\KeywordStrategistService;
use App\Http\Controllers\Controller;
use App\Jobs\RunKeywordAutomationJob;
use App\Models\AuditLog;
use App\Models\KiAlert;
use App\Models\KiAutomationRun;
use App\Models\KiAutomationWorkflow;
use App\Models\KiCannibalizationFinding;
use App\Models\KiCluster;
use App\Models\KiKeyword;
use App\Models\KiKeywordHistory;
use App\Models\KiProviderUsageLog;
use App\Models\KiSeedKeyword;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class KeywordIntelligenceController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private KeywordProviderManager $providerManager,
        private KeywordDiscoveryService $discoveryService,
        private KeywordIntentService $intentService,
        private KeywordOpportunityService $opportunityService,
        private KeywordClusteringService $clusteringService,
        private KeywordCannibalizationService $cannibalizationService,
        private KeywordStrategistService $strategistService,
        private KeywordAutomationService $automationService
    ) {
    }

    /**
     * Section 1: Overview Dashboard (100% real persisted data — never fabricated).
     */
    public function overview(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $baseQuery = KiKeyword::where('website_id', $website->id);

        $totalResearched = (clone $baseQuery)->count();
        $totalSaved = (clone $baseQuery)->where('is_saved', true)->count();
        $totalClusters = KiCluster::where('website_id', $website->id)->count();
        $highOpportunity = (clone $baseQuery)
            ->where(function ($q) {
                $q->where('opportunity_category', 'high_opportunity')
                    ->orWhere('opportunity_score', '>=', 74);
            })
            ->count();

        $avgDifficultyRaw = (clone $baseQuery)->whereNotNull('keyword_difficulty')->avg('keyword_difficulty');
        $avgDifficulty = $avgDifficultyRaw !== null ? round((float) $avgDifficultyRaw, 1) : null;

        $hasAnyVolume = (clone $baseQuery)->whereNotNull('search_volume')->exists();
        $totalEstVolume = $hasAnyVolume
            ? (int) (clone $baseQuery)->whereNotNull('search_volume')->sum('search_volume')
            : null;

        $newOpportunities7d = (clone $baseQuery)
            ->where('created_at', '>=', now()->subDays(7))
            ->where('opportunity_score', '>=', 65)
            ->count();

        $questionCount = (clone $baseQuery)->whereNotNull('question_category')->count();
        $longTailCount = (clone $baseQuery)->where('is_long_tail', true)->count();
        $cannibalizationOpen = KiCannibalizationFinding::where('website_id', $website->id)
            ->where('status', 'open')
            ->count();
        $unreadAlerts = KiAlert::where('website_id', $website->id)
            ->where('is_read', false)
            ->count();

        $activeWorkflows = KiAutomationWorkflow::where('website_id', $website->id)
            ->where('status', KiAutomationWorkflow::STATUS_ACTIVE)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        $recentSeeds = KiSeedKeyword::where('website_id', $website->id)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        $topOpportunities = (clone $baseQuery)
            ->orderByDesc('opportunity_score')
            ->limit(8)
            ->get();

        $intentBreakdown = (clone $baseQuery)
            ->selectRaw('COALESCE(search_intent, \'informational\') as intent, COUNT(*) as total')
            ->groupBy('search_intent')
            ->pluck('total', 'intent');

        return $this->success([
            'metrics' => [
                'total_researched_keywords' => $totalResearched,
                'total_saved_keywords' => $totalSaved,
                'total_clusters' => $totalClusters,
                'high_opportunity_keywords' => $highOpportunity,
                'avg_keyword_difficulty' => $avgDifficulty,
                'total_estimated_search_volume' => $totalEstVolume,
                'new_keyword_opportunities_7d' => $newOpportunities7d,
                'question_keywords_count' => $questionCount,
                'long_tail_keywords_count' => $longTailCount,
                'cannibalization_open_count' => $cannibalizationOpen,
                'active_automations_count' => $activeWorkflows->count(),
                'unread_alerts_count' => $unreadAlerts,
            ],
            'intent_breakdown' => [
                'informational' => (int) ($intentBreakdown['informational'] ?? 0),
                'navigational' => (int) ($intentBreakdown['navigational'] ?? 0),
                'commercial' => (int) ($intentBreakdown['commercial'] ?? 0),
                'transactional' => (int) ($intentBreakdown['transactional'] ?? 0),
            ],
            'recent_research_activity' => $recentSeeds,
            'active_automation_workflows' => $activeWorkflows,
            'top_opportunities' => $topOpportunities,
            'provider_status' => $this->providerManager->getProviderStatus($website),
        ]);
    }

    /**
     * Provider configuration status & usage logs.
     */
    public function providers(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $usageLogs = KiProviderUsageLog::where('website_id', $website->id)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return $this->success([
            'status' => $this->providerManager->getProviderStatus($website),
            'recent_usage' => $usageLogs,
        ]);
    }

    /**
     * Section 2: Advanced Multi-Source Keyword Discovery Engine.
     */
    public function discover(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        $validated = $request->validate([
            'seed_type' => ['nullable', 'string', Rule::in(['keyword', 'multiple', 'list', 'url', 'business', 'category'])],
            'seed_keyword' => ['nullable', 'string', 'max:250'],
            'seed_keywords' => ['nullable', 'array', 'max:15'],
            'seed_keywords.*' => ['string', 'max:250'],
            'keyword_list' => ['nullable'],
            'website_url' => ['nullable', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:180'],
            'business_category' => ['nullable', 'string', 'max:180'],
            'country' => ['nullable', 'string', 'max:10'],
            'language' => ['nullable', 'string', 'max:10'],
            'search_engine' => ['nullable', 'string', Rule::in(['google', 'bing', 'youtube'])],
            'location' => ['nullable', 'string', 'max:150'],
            'device' => ['nullable', 'string', Rule::in(['desktop', 'mobile'])],
            'search_network' => ['nullable', 'string', Rule::in(['google_search', 'search_partners'])],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', Rule::in(KeywordDiscoveryService::ALL_SOURCES)],
            'limit_per_seed' => ['nullable', 'integer', 'min:5', 'max:100'],
            'skip_external_http' => ['nullable', 'boolean'],
        ]);

        $result = $this->discoveryService->discover($website, $validated, $request->user()?->id);

        AuditLog::record(
            $website->organization_id,
            $request->user()?->id,
            'keyword_intelligence.discover',
            $website,
            null,
            [
                'seeds' => $result['seeds_used'],
                'total_discovered' => $result['total_discovered'],
                'new_keywords' => $result['new_keywords'],
            ],
            $request
        );

        return $this->success($result);
    }

    /**
     * Section 3 & 10: Keyword Explorer & Saved Keywords (advanced filtering, sorting, pagination, CSV export).
     */
    public function keywords(Request $request, Website $website): JsonResponse|Response
    {
        $this->authorizeWebsite($request, $website);

        $query = KiKeyword::where('website_id', $website->id);

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('normalized_keyword', 'like', '%' . strtolower($search) . '%');
        }
        if ($request->filled('volume_min')) {
            $query->whereNotNull('search_volume')->where('search_volume', '>=', (int) $request->input('volume_min'));
        }
        if ($request->filled('volume_max')) {
            $query->whereNotNull('search_volume')->where('search_volume', '<=', (int) $request->input('volume_max'));
        }
        if ($request->filled('kd_min')) {
            $query->whereNotNull('keyword_difficulty')->where('keyword_difficulty', '>=', (float) $request->input('kd_min'));
        }
        if ($request->filled('kd_max')) {
            $query->whereNotNull('keyword_difficulty')->where('keyword_difficulty', '<=', (float) $request->input('kd_max'));
        }
        if ($request->filled('cpc_min')) {
            $query->whereNotNull('cpc')->where('cpc', '>=', (float) $request->input('cpc_min'));
        }
        if ($request->filled('cpc_max')) {
            $query->whereNotNull('cpc')->where('cpc', '<=', (float) $request->input('cpc_max'));
        }
        if ($intent = trim((string) $request->input('intent', ''))) {
            $query->where('search_intent', strtolower($intent));
        }
        if ($country = trim((string) $request->input('country', ''))) {
            $query->where('country', strtoupper($country));
        }
        if ($language = trim((string) $request->input('language', ''))) {
            $query->where('language', strtolower($language));
        }
        if ($source = trim((string) $request->input('source', ''))) {
            $query->where(function ($q) use ($source) {
                $q->where('discovery_source', $source)->orWhere('data_source', $source);
            });
        }
        if ($request->filled('position_max')) {
            $query->whereNotNull('current_position')->where('current_position', '<=', (float) $request->input('position_max'));
        }
        if ($request->filled('saved')) {
            $query->where('is_saved', $request->boolean('saved'));
        }
        if ($request->filled('long_tail')) {
            $query->where('is_long_tail', $request->boolean('long_tail'));
        }
        if ($qCat = trim((string) $request->input('question_category', ''))) {
            if ($qCat === 'any') {
                $query->whereNotNull('question_category');
            } else {
                $query->where('question_category', strtolower($qCat));
            }
        }
        if ($oppCat = trim((string) $request->input('opportunity_category', ''))) {
            $query->where('opportunity_category', $oppCat);
        }
        if ($trend = trim((string) $request->input('trend_direction', ''))) {
            $query->where('trend_direction', strtolower($trend));
        }
        if ($serpFeature = trim((string) $request->input('serp_feature', ''))) {
            $query->where('serp_features', 'like', '%' . $serpFeature . '%');
        }

        $allowedSorts = [
            'opportunity_score', 'search_volume', 'keyword_difficulty', 'cpc',
            'current_position', 'gsc_impressions', 'gsc_clicks', 'keyword', 'created_at', 'last_updated_at',
        ];
        $sortBy = in_array($request->input('sort_by'), $allowedSorts, true)
            ? $request->input('sort_by')
            : 'opportunity_score';
        $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query->orderByRaw("CASE WHEN {$sortBy} IS NULL THEN 1 ELSE 0 END ASC")
            ->orderBy($sortBy, $sortDir);

        // CSV Export
        if ($request->input('export') === 'csv') {
            $rows = $query->limit(2000)->get();
            return $this->exportKeywordsToCsvResponse($rows, $website->domain);
        }

        $perPage = max(10, min(100, (int) $request->input('per_page', 25)));
        $paginated = $query->paginate($perPage);

        return $this->success([
            'items' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'provider_status' => $this->providerManager->getProviderStatus($website),
        ]);
    }

    /**
     * Bulk actions on keywords (save, unsave, delete, send_action_plan, export_csv).
     */
    public function bulkKeywords(Request $request, Website $website): JsonResponse|Response
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['save', 'unsave', 'delete', 'send_action_plan', 'export_csv'])],
            'keyword_ids' => ['required', 'array', 'min:1', 'max:200'],
            'keyword_ids.*' => ['integer'],
        ]);

        $keywords = KiKeyword::where('website_id', $website->id)
            ->whereIn('id', $validated['keyword_ids'])
            ->get();

        if ($validated['action'] === 'export_csv') {
            return $this->exportKeywordsToCsvResponse($keywords, $website->domain);
        }

        $affected = 0;
        if ($validated['action'] === 'save') {
            $affected = KiKeyword::where('website_id', $website->id)->whereIn('id', $validated['keyword_ids'])->update(['is_saved' => true]);
        } elseif ($validated['action'] === 'unsave') {
            $affected = KiKeyword::where('website_id', $website->id)->whereIn('id', $validated['keyword_ids'])->update(['is_saved' => false]);
        } elseif ($validated['action'] === 'delete') {
            $affected = KiKeyword::where('website_id', $website->id)->whereIn('id', $validated['keyword_ids'])->delete();
        } elseif ($validated['action'] === 'send_action_plan') {
            foreach ($keywords as $kw) {
                $this->discoveryService->sendToActionPlan($website, $kw, $request->user()?->id);
                $affected++;
            }
        }

        return $this->success([
            'action' => $validated['action'],
            'affected' => $affected,
        ]);
    }

    public function showKeyword(Request $request, Website $website, KiKeyword $keyword): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->ensureBelongsToWebsite($keyword->website_id, $website->id);

        $history = KiKeywordHistory::where('keyword_id', $keyword->id)
            ->orderByDesc('captured_at')
            ->limit(30)
            ->get();

        $clusters = $keyword->clusters()->get(['ki_clusters.id', 'ki_clusters.name', 'ki_clusters.primary_keyword', 'ki_clusters.search_intent']);

        // Find semantically related keywords already researched in this workspace
        $firstWord = explode(' ', $keyword->normalized_keyword)[0] ?? '';
        $related = $firstWord !== ''
            ? KiKeyword::where('website_id', $website->id)
                ->where('id', '!=', $keyword->id)
                ->where('normalized_keyword', 'like', '%' . $firstWord . '%')
                ->orderByDesc('opportunity_score')
                ->limit(10)
                ->get()
            : collect();

        return $this->success([
            'keyword' => $keyword,
            'history' => $history,
            'clusters' => $clusters,
            'related_keywords' => $related,
        ]);
    }

    public function inspectSerp(Request $request, Website $website, KiKeyword $keyword): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->ensureBelongsToWebsite($keyword->website_id, $website->id);

        $res = $this->discoveryService->inspectKeywordSerp(
            $website,
            $keyword,
            $request->user()?->id,
            $request->boolean('force_refresh', false)
        );

        return $this->success($res);
    }

    public function updateKeyword(Request $request, Website $website, KiKeyword $keyword): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($keyword->website_id, $website->id);

        $validated = $request->validate([
            'is_saved' => ['nullable', 'boolean'],
            'is_monitored' => ['nullable', 'boolean'],
            'search_intent' => ['nullable', 'string', Rule::in(['informational', 'navigational', 'commercial', 'transactional'])],
        ]);

        if (isset($validated['search_intent'])) {
            $validated['intent_source'] = 'manual';
            $validated['intent_confidence'] = 1.0;
        }

        $keyword->update($validated);
        return $this->success($keyword->fresh());
    }

    public function destroyKeyword(Request $request, Website $website, KiKeyword $keyword): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($keyword->website_id, $website->id);

        $keyword->delete();
        return $this->success(['deleted' => true]);
    }

    public function sendKeywordToActionPlan(Request $request, Website $website, KiKeyword $keyword): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($keyword->website_id, $website->id);

        $rec = $this->discoveryService->sendToActionPlan($website, $keyword, $request->user()?->id);
        return $this->success([
            'recommendation' => $rec,
            'keyword' => $keyword->fresh(),
        ], null, 201);
    }

    public function sendKeywordToContentStudio(Request $request, Website $website, KiKeyword $keyword): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($keyword->website_id, $website->id);

        $brief = $this->discoveryService->sendToContentStudio($website, $keyword, $request->user()?->id);
        return $this->success([
            'brief' => $brief,
            'keyword' => $keyword->fresh(),
        ], null, 201);
    }

    public function monitorKeywordRank(Request $request, Website $website, KiKeyword $keyword): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($keyword->website_id, $website->id);

        $res = $this->discoveryService->monitorInRankChecker($website, $keyword, $request->user()?->id);
        return $this->success([
            'rank_check' => $res,
            'keyword' => $keyword->fresh(),
        ]);
    }

    /**
     * Section 9: Keyword Research History (seed runs + metric history snapshots).
     */
    public function history(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $seeds = KiSeedKeyword::where('website_id', $website->id)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();

        $snapshots = KiKeywordHistory::with('keyword:id,keyword,country,language,search_intent')
            ->where('website_id', $website->id)
            ->orderByDesc('captured_at')
            ->limit(60)
            ->get();

        return $this->success([
            'seed_runs' => $seeds,
            'metric_snapshots' => $snapshots,
        ]);
    }

    /**
     * Section 7: Question Keywords grouped across all 10 question categories.
     */
    public function questions(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $category = strtolower(trim((string) $request->input('category', '')));
        $query = KiKeyword::where('website_id', $website->id)->whereNotNull('question_category');
        if ($category !== '' && in_array($category, KeywordIntentService::QUESTION_CATEGORIES, true)) {
            $query->where('question_category', $category);
        }

        $items = $query->orderByDesc('opportunity_score')->limit(200)->get();

        $countsRaw = KiKeyword::where('website_id', $website->id)
            ->whereNotNull('question_category')
            ->selectRaw('question_category, COUNT(*) as total')
            ->groupBy('question_category')
            ->pluck('total', 'question_category');

        $categoryCounts = [];
        foreach (KeywordIntentService::QUESTION_CATEGORIES as $cat) {
            $categoryCounts[$cat] = (int) ($countsRaw[$cat] ?? 0);
        }

        return $this->success([
            'total_questions' => array_sum($categoryCounts),
            'category_counts' => $categoryCounts,
            'items' => $items,
        ]);
    }

    public function generateFaqIdeas(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        $validated = $request->validate([
            'questions' => ['required', 'array', 'min:1', 'max:15'],
            'questions.*' => ['string', 'max:250'],
        ]);

        $ideas = $this->intentService->generateFaqIdeas($website, $validated['questions'], $request->user()?->id);
        return $this->success([
            'classification' => 'ai_inferred',
            'faq_ideas' => $ideas,
        ]);
    }

    /**
     * Section 8: Long-Tail Keywords grouped by search intent and topic.
     */
    public function longTail(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $query = KiKeyword::where('website_id', $website->id)->where('is_long_tail', true);
        if ($intent = strtolower(trim((string) $request->input('intent', '')))) {
            $query->where('search_intent', $intent);
        }

        $items = $query->orderByDesc('opportunity_score')->limit(200)->get();

        $byIntent = $items->groupBy(fn (KiKeyword $k) => $k->search_intent ?: 'informational')
            ->map(fn ($group) => $group->count());

        return $this->success([
            'total' => $items->count(),
            'intent_distribution' => $byIntent,
            'items' => $items,
        ]);
    }

    /**
     * Section 5: Search Intent Analysis & AI-Inferred Intent Classification.
     */
    public function intentSummary(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $all = KiKeyword::where('website_id', $website->id)->get();
        $intents = ['informational', 'navigational', 'commercial', 'transactional'];
        $breakdown = [];

        foreach ($intents as $intent) {
            $bucket = $all->where('search_intent', $intent);
            $vols = $bucket->pluck('search_volume')->filter(fn ($v) => $v !== null);
            $breakdown[$intent] = [
                'count' => $bucket->count(),
                'share_pct' => $all->count() > 0 ? round(($bucket->count() / $all->count()) * 100, 1) : 0.0,
                'total_volume' => $vols->isNotEmpty() ? (int) $vols->sum() : null,
                'avg_opportunity' => $bucket->isNotEmpty() ? round((float) $bucket->avg('opportunity_score'), 1) : null,
                'provider_verified' => $bucket->where('intent_source', 'provider')->count(),
                'ai_inferred' => $bucket->where('intent_source', 'ai_inferred')->count(),
                'rule_based' => $bucket->where('intent_source', 'rule_based')->count(),
            ];
        }

        return $this->success([
            'total_keywords' => $all->count(),
            'breakdown' => $breakdown,
        ]);
    }

    public function classifyIntentWithAi(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        $validated = $request->validate([
            'keyword_ids' => ['nullable', 'array', 'max:40'],
            'keyword_ids.*' => ['integer'],
        ]);

        $query = KiKeyword::where('website_id', $website->id);
        if (!empty($validated['keyword_ids'])) {
            $query->whereIn('id', $validated['keyword_ids']);
        } else {
            $query->where('intent_source', '!=', 'provider')->orderByDesc('opportunity_score')->limit(30);
        }

        $result = $this->intentService->classifyBatchWithAi($website, $query->get(), $request->user()?->id);
        return $this->success($result);
    }

    /**
     * Section 6: AI Keyword Opportunities & Transparent Configurable Scoring Engine.
     */
    public function opportunities(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $query = KiKeyword::where('website_id', $website->id);
        if ($cat = trim((string) $request->input('category', ''))) {
            $query->where('opportunity_category', $cat);
        }
        if ($priority = strtoupper(trim((string) $request->input('priority', '')))) {
            $query->where('recommended_priority', $priority);
        }
        if ($intent = strtolower(trim((string) $request->input('intent', '')))) {
            $query->where('search_intent', $intent);
        }

        $items = $query->orderByDesc('opportunity_score')->limit(150)->get();

        $catCounts = KiKeyword::where('website_id', $website->id)
            ->selectRaw('opportunity_category, COUNT(*) as total')
            ->groupBy('opportunity_category')
            ->pluck('total', 'opportunity_category');

        return $this->success([
            'methodology' => $this->opportunityService->getMethodology($website),
            'category_counts' => [
                'high_opportunity' => (int) ($catCounts['high_opportunity'] ?? 0),
                'medium_opportunity' => (int) ($catCounts['medium_opportunity'] ?? 0),
                'low_opportunity' => (int) ($catCounts['low_opportunity'] ?? 0),
                'high_competition' => (int) ($catCounts['high_competition'] ?? 0),
                'existing_strength' => (int) ($catCounts['existing_strength'] ?? 0),
                'content_gap' => (int) ($catCounts['content_gap'] ?? 0),
            ],
            'items' => $items,
        ]);
    }

    public function updateScoringWeights(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        $validated = $request->validate([
            'weights' => ['required', 'array'],
            'weights.demand' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'weights.difficulty' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'weights.relevance' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'weights.intent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'weights.position_potential' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'weights.competitor_gap' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'weights.trend' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $res = $this->opportunityService->updateWeightsAndRescore($website, $validated['weights'], $request->user()?->id);
        return $this->success($res);
    }

    /**
     * Section 4: Keyword Clustering Engine (Semantic + SERP-based + full CRUD).
     */
    public function clusters(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $clusters = KiCluster::where('website_id', $website->id)
            ->with(['memberships.keyword'])
            ->orderByDesc('opportunity_score')
            ->get();

        return $this->success($clusters);
    }

    public function generateClusters(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        $validated = $request->validate([
            'method' => ['nullable', 'string', Rule::in(['semantic', 'serp'])],
            'keyword_ids' => ['nullable', 'array'],
            'keyword_ids.*' => ['integer'],
            'replace_unsaved' => ['nullable', 'boolean'],
        ]);

        $res = $this->clusteringService->generateClusters(
            $website,
            $validated['method'] ?? 'semantic',
            $validated['keyword_ids'] ?? [],
            $validated['replace_unsaved'] ?? true
        );

        return $this->success($res);
    }

    public function createCluster(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'keyword_ids' => ['required', 'array', 'min:1'],
            'keyword_ids.*' => ['integer'],
            'suggested_pillar_page' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $cluster = $this->clusteringService->createCluster(
                $website,
                $validated['name'],
                $validated['keyword_ids'],
                $validated['suggested_pillar_page'] ?? null
            );
            return $this->success($cluster, null, 201);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'CLUSTER_ERROR', null, 422);
        }
    }

    public function updateCluster(Request $request, Website $website, KiCluster $cluster): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($cluster->website_id, $website->id);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:180'],
            'is_saved' => ['nullable', 'boolean'],
            'suggested_pillar_page' => ['nullable', 'string', 'max:255'],
        ]);

        $cluster->update($validated);
        return $this->success($cluster->fresh(['memberships.keyword']));
    }

    public function mergeClusters(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        $validated = $request->validate([
            'cluster_ids' => ['required', 'array', 'min:2'],
            'cluster_ids.*' => ['integer'],
            'name' => ['nullable', 'string', 'max:180'],
            'force' => ['nullable', 'boolean'],
        ]);

        try {
            $merged = $this->clusteringService->mergeClusters(
                $website,
                $validated['cluster_ids'],
                $validated['name'] ?? null,
                (bool) ($validated['force'] ?? false)
            );
            return $this->success($merged);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'CLUSTER_MERGE_CONFLICT', null, 422);
        }
    }

    public function splitCluster(Request $request, Website $website, KiCluster $cluster): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($cluster->website_id, $website->id);

        $validated = $request->validate([
            'keyword_ids' => ['required', 'array', 'min:1'],
            'keyword_ids.*' => ['integer'],
            'new_cluster_name' => ['nullable', 'string', 'max:180'],
        ]);

        try {
            $result = $this->clusteringService->splitCluster(
                $website,
                $cluster,
                $validated['keyword_ids'],
                $validated['new_cluster_name'] ?? null
            );
            return $this->success($result);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'CLUSTER_SPLIT_ERROR', null, 422);
        }
    }

    public function addClusterKeywords(Request $request, Website $website, KiCluster $cluster): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($cluster->website_id, $website->id);

        $validated = $request->validate([
            'keyword_ids' => ['required', 'array', 'min:1'],
            'keyword_ids.*' => ['integer'],
            'force' => ['nullable', 'boolean'],
        ]);

        try {
            $updated = $this->clusteringService->addKeywordsToCluster(
                $website,
                $cluster,
                $validated['keyword_ids'],
                (bool) ($validated['force'] ?? false)
            );
            return $this->success($updated);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'CLUSTER_INTENT_CONFLICT', null, 422);
        }
    }

    public function removeClusterKeyword(Request $request, Website $website, KiCluster $cluster, KiKeyword $keyword): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($cluster->website_id, $website->id);
        $this->ensureBelongsToWebsite($keyword->website_id, $website->id);

        $updated = $this->clusteringService->removeKeywordFromCluster($cluster, $keyword->id);
        return $this->success($updated);
    }

    public function destroyCluster(Request $request, Website $website, KiCluster $cluster): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($cluster->website_id, $website->id);

        $cluster->delete();
        return $this->success(['deleted' => true]);
    }

    public function sendClusterToContentStudio(Request $request, Website $website, KiCluster $cluster): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($cluster->website_id, $website->id);

        $brief = $this->clusteringService->sendClusterToContentStudio($website, $cluster, $request->user()?->id);
        return $this->success([
            'brief' => $brief,
            'cluster' => $cluster->fresh(['memberships.keyword']),
        ], null, 201);
    }

    public function exportClustersCsv(Request $request, Website $website): Response
    {
        $this->authorizeWebsite($request, $website);

        $clusters = KiCluster::where('website_id', $website->id)
            ->with('memberships.keyword')
            ->orderByDesc('opportunity_score')
            ->get();

        $lines = [implode(',', [
            'Cluster Name', 'Primary Keyword', 'Method', 'Search Intent', 'Keyword Count',
            'Combined Search Volume', 'Avg Difficulty', 'Opportunity Score', 'Suggested Pillar Page', 'Secondary Keywords',
        ])];

        foreach ($clusters as $c) {
            $secondaries = $c->memberships
                ->map(fn ($m) => $m->keyword?->keyword)
                ->filter(fn ($k) => $k && strtolower($k) !== strtolower($c->primary_keyword))
                ->implode(' | ');

            $lines[] = implode(',', [
                $this->csvEscape($c->name),
                $this->csvEscape($c->primary_keyword),
                $this->csvEscape($c->clustering_method),
                $this->csvEscape($c->search_intent ?? 'informational'),
                $c->keyword_count,
                $this->csvEscape($c->combined_search_volume !== null ? $c->combined_search_volume : 'Data Unavailable'),
                $this->csvEscape($c->avg_difficulty !== null ? $c->avg_difficulty : 'Data Unavailable'),
                $c->opportunity_score,
                $this->csvEscape($c->suggested_pillar_page ?? ''),
                $this->csvEscape($secondaries),
            ]);
        }

        $filename = 'keyword-clusters-' . preg_replace('/[^a-z0-9.-]/i', '-', $website->domain) . '.csv';
        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Cannibalization Detection (GSC + Rank Checker evidence-based).
     */
    public function cannibalization(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $findings = KiCannibalizationFinding::where('website_id', $website->id)
            ->orderByRaw("CASE confidence WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('total_impressions')
            ->get();

        return $this->success($findings);
    }

    public function analyzeCannibalization(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        $days = (int) $request->input('days', 90);
        $result = $this->cannibalizationService->analyze($website, $days);
        return $this->success($result);
    }

    public function updateCannibalizationStatus(Request $request, Website $website, KiCannibalizationFinding $finding): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($finding->website_id, $website->id);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['open', 'acknowledged', 'resolved', 'dismissed'])],
        ]);

        $finding->update(['status' => $validated['status']]);
        return $this->success($finding->fresh());
    }

    /**
     * AI Keyword Strategist (evidence-backed strategic recommendations).
     */
    public function strategistAnalyze(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        $validated = $request->validate([
            'goal' => ['nullable', 'string', 'max:300'],
            'focus_topic' => ['nullable', 'string', 'max:180'],
        ]);

        $result = $this->strategistService->analyze($website, $validated, $request->user()?->id);
        return $this->success($result);
    }

    /**
     * Section 11: Keyword Automation Control Center (CRUD, pause/resume, manual run, logs, alerts).
     */
    public function automations(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $workflows = KiAutomationWorkflow::where('website_id', $website->id)
            ->with(['runs' => fn ($q) => $q->orderByDesc('created_at')->limit(5)])
            ->orderByDesc('created_at')
            ->get();

        return $this->success($workflows);
    }

    public function createAutomation(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'seed_keywords' => ['required', 'array', 'min:1', 'max:15'],
            'seed_keywords.*' => ['string', 'max:250'],
            'business_category' => ['nullable', 'string', 'max:180'],
            'target_country' => ['nullable', 'string', 'max:10'],
            'target_language' => ['nullable', 'string', 'max:10'],
            'discovery_sources' => ['nullable', 'array'],
            'schedule_frequency' => ['nullable', 'string', Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'custom_interval_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'auto_cluster' => ['nullable', 'boolean'],
            'auto_monitor_rank' => ['nullable', 'boolean'],
            'auto_content_recommendations' => ['nullable', 'boolean'],
            'notify_on_opportunities' => ['nullable', 'boolean'],
            'min_opportunity_score' => ['nullable', 'integer', 'min:30', 'max:95'],
            'status' => ['nullable', 'string', Rule::in(['active', 'paused'])],
        ]);

        $workflow = $this->automationService->createWorkflow($website, $validated, $request->user()?->id);
        return $this->success($workflow, null, 201);
    }

    public function updateAutomation(Request $request, Website $website, KiAutomationWorkflow $workflow): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($workflow->website_id, $website->id);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'string', Rule::in(['active', 'paused'])],
            'seed_keywords' => ['nullable', 'array', 'min:1', 'max:15'],
            'seed_keywords.*' => ['string', 'max:250'],
            'business_category' => ['nullable', 'string', 'max:180'],
            'target_country' => ['nullable', 'string', 'max:10'],
            'target_language' => ['nullable', 'string', 'max:10'],
            'discovery_sources' => ['nullable', 'array'],
            'schedule_frequency' => ['nullable', 'string', Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'custom_interval_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'auto_cluster' => ['nullable', 'boolean'],
            'auto_monitor_rank' => ['nullable', 'boolean'],
            'auto_content_recommendations' => ['nullable', 'boolean'],
            'notify_on_opportunities' => ['nullable', 'boolean'],
            'min_opportunity_score' => ['nullable', 'integer', 'min:30', 'max:95'],
        ]);

        $updated = $this->automationService->updateWorkflow($workflow, $validated);
        return $this->success($updated);
    }

    public function runAutomation(Request $request, Website $website, KiAutomationWorkflow $workflow): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($workflow->website_id, $website->id);

        $options = [
            'skip_external_http' => $request->boolean('skip_external_http', false),
        ];

        if ($request->boolean('async', false)) {
            RunKeywordAutomationJob::dispatch($workflow->id, 'manual', $options);
            return $this->success([
                'queued' => true,
                'workflow_id' => $workflow->id,
            ], null, 202);
        }

        $run = $this->automationService->executeWorkflow($workflow, 'manual', $options);
        return $this->success([
            'queued' => false,
            'run' => $run,
            'workflow' => $workflow->fresh(),
        ]);
    }

    public function destroyAutomation(Request $request, Website $website, KiAutomationWorkflow $workflow): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);
        $this->ensureBelongsToWebsite($workflow->website_id, $website->id);

        $workflow->delete();
        return $this->success(['deleted' => true]);
    }

    public function automationRuns(Request $request, Website $website, KiAutomationWorkflow $workflow): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->ensureBelongsToWebsite($workflow->website_id, $website->id);

        $runs = KiAutomationRun::where('workflow_id', $workflow->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return $this->success($runs);
    }

    /**
     * Alerts Inbox.
     */
    public function alerts(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $alerts = KiAlert::where('website_id', $website->id)
            ->orderBy('is_read')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $this->success($alerts);
    }

    public function markAlertRead(Request $request, Website $website, KiAlert $alert): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->ensureBelongsToWebsite($alert->website_id, $website->id);

        $alert->update(['is_read' => true, 'read_at' => now()]);
        return $this->success($alert->fresh());
    }

    public function markAllAlertsRead(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        KiAlert::where('website_id', $website->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return $this->success(['marked_all_read' => true]);
    }

    private function exportKeywordsToCsvResponse($rows, string $domain): Response
    {
        $lines = [implode(',', [
            'Keyword', 'Country', 'Language', 'Monthly Search Volume', 'Keyword Difficulty',
            'CPC', 'Paid Competition', 'Search Intent', 'Intent Source', 'Trend Direction',
            'Current Position', 'Ranking URL', 'GSC Clicks', 'GSC Impressions',
            'Opportunity Score', 'Category', 'Priority', 'Data Confidence', 'Data Source', 'Last Updated',
        ])];

        foreach ($rows as $kw) {
            $lines[] = implode(',', [
                $this->csvEscape($kw->keyword),
                $this->csvEscape($kw->country),
                $this->csvEscape($kw->language),
                $this->csvEscape($kw->search_volume !== null ? $kw->search_volume : 'Data Unavailable'),
                $this->csvEscape($kw->keyword_difficulty !== null ? $kw->keyword_difficulty : 'Data Unavailable'),
                $this->csvEscape($kw->cpc !== null ? $kw->cpc : 'Data Unavailable'),
                $this->csvEscape($kw->paid_competition !== null ? $kw->paid_competition : 'Data Unavailable'),
                $this->csvEscape($kw->search_intent ?? 'Data Unavailable'),
                $this->csvEscape($kw->intent_source ?? ''),
                $this->csvEscape($kw->trend_direction ?? 'unavailable'),
                $this->csvEscape($kw->current_position !== null ? $kw->current_position : 'Data Unavailable'),
                $this->csvEscape($kw->ranking_url ?? ''),
                $this->csvEscape($kw->gsc_clicks !== null ? $kw->gsc_clicks : ''),
                $this->csvEscape($kw->gsc_impressions !== null ? $kw->gsc_impressions : ''),
                $this->csvEscape($kw->opportunity_score ?? ''),
                $this->csvEscape($kw->opportunity_category ?? ''),
                $this->csvEscape($kw->recommended_priority ?? ''),
                $this->csvEscape($kw->data_confidence ?? ''),
                $this->csvEscape($kw->data_source ?? ''),
                $this->csvEscape($kw->last_updated_at?->toIso8601String() ?? ''),
            ]);
        }

        $filename = 'keywords-' . preg_replace('/[^a-z0-9.-]/i', '-', $domain) . '.csv';
        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function authorizeWebsite(Request $request, Website $website): void
    {
        /** @var Organization|null $org */
        $org = $request->attributes->get('organization');
        $orgId = $org?->id ?? $request->header('X-Organization-Id');

        if ($orgId && (int) $website->organization_id !== (int) $orgId) {
            abort(403, 'Unauthorized access to website.');
        }
    }

    private function ensureBelongsToWebsite(int $resourceWebsiteId, int $websiteId): void
    {
        if ($resourceWebsiteId !== $websiteId) {
            abort(404, 'Resource does not belong to this website.');
        }
    }

    private function requireMinimumRole(Request $request, string $minRole): void
    {
        /** @var OrganizationMember|null $membership */
        $membership = $request->attributes->get('membership');
        if (!$membership || !$membership->hasMinimumRole($minRole)) {
            abort(403, "Insufficient role permissions. Requires at least {$minRole}.");
        }
    }

    private function csvEscape(mixed $val): string
    {
        $str = str_replace('"', '""', (string) $val);
        return "\"{$str}\"";
    }
}
