<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Competitors\CompetitorProviderManager;
use App\Domain\Competitors\Services\AiCompetitorStrategistService;
use App\Domain\Competitors\Services\BacklinkGapService;
use App\Domain\Competitors\Services\CompetitorDeepDiveService;
use App\Domain\Competitors\Services\CompetitorDiscoveryService;
use App\Domain\Competitors\Services\CompetitorMonitoringService;
use App\Domain\Competitors\Services\ContentGapService;
use App\Domain\Competitors\Services\DomainComparisonService;
use App\Domain\Competitors\Services\KeywordGapService;
use App\Domain\Competitors\Services\SerpAnalysisService;
use App\Http\Controllers\Controller;
use App\Jobs\RunCompetitorAnalysisJob;
use App\Models\AuditLog;
use App\Models\CompetitorAnalysisJob;
use App\Models\CompetitorContentGap;
use App\Models\CompetitorDiscoveryRun;
use App\Models\CompetitorDomain;
use App\Models\CompetitorDomainSnapshot;
use App\Models\CompetitorKeyword;
use App\Models\CompetitorSerpSnapshot;
use App\Models\CompetitorStrategyReport;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CompetitorAnalysisController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private CompetitorProviderManager $providerManager,
        private CompetitorDiscoveryService $discoveryService,
        private DomainComparisonService $comparisonService,
        private KeywordGapService $keywordGapService,
        private ContentGapService $contentGapService,
        private BacklinkGapService $backlinkGapService,
        private SerpAnalysisService $serpAnalysisService,
        private AiCompetitorStrategistService $strategistService,
        private CompetitorMonitoringService $monitoringService,
        private CompetitorDeepDiveService $deepDiveService
    ) {
    }

    /**
     * Provider configuration status & usage logs (NEVER exposes API keys).
     */
    public function providers(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        return $this->success(
            $this->providerManager->getStatusSummary($website->organization_id, $website->id)
        );
    }

    /**
     * Submodule 1: Executive Overview.
     */
    public function overview(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $competitors = CompetitorDomain::with('latestSnapshot')
            ->where('website_id', $website->id)
            ->where('is_active', true)
            ->orderByRaw("CASE tier WHEN 'primary' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'desc')
            ->get();

        $targetSnapshot = CompetitorDomainSnapshot::where('website_id', $website->id)
            ->where('is_target', true)
            ->orderByDesc('captured_at')
            ->first();

        $keywordCounts = [
            'total' => CompetitorKeyword::where('website_id', $website->id)->count(),
            'missing' => CompetitorKeyword::where('website_id', $website->id)->where('gap_type', CompetitorKeyword::GAP_MISSING)->count(),
            'weak' => CompetitorKeyword::where('website_id', $website->id)->where('gap_type', CompetitorKeyword::GAP_WEAK)->count(),
            'shared' => CompetitorKeyword::where('website_id', $website->id)->where('gap_type', CompetitorKeyword::GAP_SHARED)->count(),
            'strong' => CompetitorKeyword::where('website_id', $website->id)->where('gap_type', CompetitorKeyword::GAP_STRONG)->count(),
            'untapped' => CompetitorKeyword::where('website_id', $website->id)->where('gap_type', CompetitorKeyword::GAP_UNTAPPED)->count(),
        ];

        $contentGapsOpen = CompetitorContentGap::where('website_id', $website->id)->where('status', 'open')->count();
        $serpMonitored = CompetitorSerpSnapshot::where('website_id', $website->id)->where('is_monitored', true)->distinct('keyword')->count('keyword');
        $latestStrategy = CompetitorStrategyReport::where('website_id', $website->id)->orderByDesc('generated_at')->first();
        $latestJob = CompetitorAnalysisJob::where('website_id', $website->id)->orderByDesc('created_at')->first();
        $monitoring = $this->monitoringService->getOrCreateConfig($website);
        $providerStatus = $this->providerManager->getStatusSummary($website->organization_id, $website->id);

        return $this->success([
            'website' => [
                'id' => $website->id,
                'name' => $website->name,
                'domain' => $website->domain,
                'country' => $website->target_country ?: 'US',
                'language' => $website->preferred_language ?: 'en',
            ],
            'target_snapshot' => $targetSnapshot,
            'competitors_count' => $competitors->count(),
            'primary_competitors_count' => $competitors->where('tier', 'primary')->count(),
            'competitors' => $competitors,
            'keyword_gap_summary' => $keywordCounts,
            'content_gaps_open' => $contentGapsOpen,
            'serp_monitored_keywords' => $serpMonitored,
            'latest_strategy_report' => $latestStrategy,
            'latest_job' => $latestJob,
            'monitoring' => $monitoring,
            'providers' => $providerStatus,
        ]);
    }

    /**
     * Submodule 2: List tracked competitors.
     */
    public function index(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $competitors = CompetitorDomain::with('latestSnapshot')
            ->where('website_id', $website->id)
            ->orderByRaw("CASE tier WHEN 'primary' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->get();

        return $this->success($competitors);
    }

    /**
     * Manually add a competitor domain.
     */
    public function store(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', Rule::in(CompetitorDomain::CATEGORIES)],
            'tier' => ['nullable', 'string', Rule::in([CompetitorDomain::TIER_PRIMARY, CompetitorDomain::TIER_SECONDARY])],
            'country' => ['nullable', 'string', 'max:10'],
            'language' => ['nullable', 'string', 'max:10'],
        ]);

        $domain = CompetitorDomain::normalizeDomain($validated['domain']);
        $targetDomain = CompetitorDomain::normalizeDomain($website->domain);

        if ($domain === '' || !str_contains($domain, '.')) {
            return $this->error('Please enter a valid competitor domain (e.g. rival-company.com).', 'INVALID_DOMAIN', null, 422);
        }

        if ($domain === $targetDomain) {
            return $this->error('You cannot add your own target website as a competitor.', 'SELF_DOMAIN_REJECTED', null, 422);
        }

        $existing = CompetitorDomain::where('website_id', $website->id)->where('domain', $domain)->first();
        if ($existing) {
            return $this->error("Competitor domain [{$domain}] is already tracked for this website.", 'COMPETITOR_EXISTS', null, 422);
        }

        $comp = CompetitorDomain::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'domain' => $domain,
            'display_name' => $validated['display_name'] ?? Str::title(str_replace('-', ' ', pathinfo($domain, PATHINFO_FILENAME))),
            'category' => $validated['category'] ?? CompetitorDomain::CATEGORY_DIRECT,
            'tier' => $validated['tier'] ?? CompetitorDomain::TIER_PRIMARY,
            'country' => strtoupper($validated['country'] ?? ($website->target_country ?: 'US')),
            'language' => strtolower($validated['language'] ?? ($website->preferred_language ?: 'en')),
            'discovery_source' => 'manual',
            'discovery_confidence' => 100,
            'discovery_rationale' => 'Added manually by workspace team.',
            'is_active' => true,
        ]);

        AuditLog::record(
            $website->organization_id,
            $request->user()->id,
            'competitor.added',
            $comp,
            null,
            ['domain' => $comp->domain, 'category' => $comp->category, 'tier' => $comp->tier],
            $request
        );

        // Immediately capture verified first-party & Google index snapshot in production so data appears right away
        if (!app()->runningUnitTests()) {
            try {
                $this->comparisonService->captureDomainSnapshot($website, $comp->domain, false, $comp, $comp->country, $comp->language, false);
            } catch (\Throwable) {
                // Non-blocking enrichment
            }
        }

        return $this->success($comp->fresh('latestSnapshot'), null, 201);
    }

    /**
     * Head-to-Head Deep Dive comparison & improvement roadmap against a specific competitor.
     */
    public function deepDive(Request $request, Website $website, CompetitorDomain $competitor): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        if ((int) $competitor->website_id !== (int) $website->id) {
            return $this->error('Competitor not found for this website.', 'COMPETITOR_NOT_FOUND', null, 404);
        }

        $forceRefresh = $request->boolean('refresh', false);
        $report = $this->deepDiveService->buildDeepDive($website, $competitor, $forceRefresh);

        return $this->success($report);
    }

    /**
     * Save a Deep Dive improvement directly into the website's SEO Action Plan.
     */
    public function saveDeepDiveAction(Request $request, Website $website, CompetitorDomain $competitor): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        if ((int) $competitor->website_id !== (int) $website->id) {
            return $this->error('Competitor not found for this website.', 'COMPETITOR_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:40'],
            'priority' => ['nullable', 'string', 'max:20'],
            'effort' => ['nullable', 'string', 'max:20'],
            'your_current' => ['nullable', 'string', 'max:500'],
            'competitor_benchmark' => ['nullable', 'string', 'max:500'],
            'why_it_matters' => ['nullable', 'string', 'max:1000'],
            'expected_impact' => ['nullable', 'string', 'max:500'],
            'action_steps' => ['nullable', 'array'],
            'affected_url' => ['nullable', 'string', 'max:500'],
        ]);

        $rec = $this->deepDiveService->saveImprovementToActionPlan($website, $competitor, $validated);

        return $this->success($rec, null, 201);
    }

    /**
     * Update competitor category, tier, display name, or active state.
     */
    public function update(Request $request, Website $website, CompetitorDomain $competitor): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        if ((int) $competitor->website_id !== (int) $website->id) {
            return $this->error('Competitor not found for this website.', 'COMPETITOR_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', Rule::in(CompetitorDomain::CATEGORIES)],
            'tier' => ['sometimes', 'string', Rule::in([CompetitorDomain::TIER_PRIMARY, CompetitorDomain::TIER_SECONDARY])],
            'country' => ['sometimes', 'string', 'max:10'],
            'language' => ['sometimes', 'string', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $competitor->update($validated);

        return $this->success($competitor->fresh('latestSnapshot'));
    }

    /**
     * Remove a tracked competitor domain.
     */
    public function destroy(Request $request, Website $website, CompetitorDomain $competitor): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        if ((int) $competitor->website_id !== (int) $website->id) {
            return $this->error('Competitor not found for this website.', 'COMPETITOR_NOT_FOUND', null, 404);
        }

        $domain = $competitor->domain;
        $competitor->delete();

        AuditLog::record(
            $website->organization_id,
            $request->user()->id,
            'competitor.removed',
            $website,
            ['domain' => $domain],
            null,
            $request
        );

        return $this->success(['message' => "Removed competitor {$domain}."]);
    }

    /**
     * Phase 3: Run intelligent competitor discovery (returns candidates for manual review).
     */
    public function discover(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_SEO_MANAGER);

        $validated = $request->validate([
            'country' => ['nullable', 'string', 'max:10'],
            'countries' => ['nullable', 'array', 'max:5'],
            'countries.*' => ['string', 'size:2'],
            'language' => ['nullable', 'string', 'max:10'],
            'search_engine' => ['nullable', 'string', Rule::in(['google', 'bing'])],
            'industry_context' => ['nullable', 'string', 'max:255'],
            'strict_country_match' => ['nullable', 'boolean'],
            'force_refresh' => ['nullable', 'boolean'],
        ]);

        $primaryCountry = $validated['country'] ?? ($website->target_country ?: 'US');
        $countries = !empty($validated['countries']) ? $validated['countries'] : [$primaryCountry];

        $run = $this->discoveryService->discover(
            $website,
            $request->user()->id,
            $primaryCountry,
            $validated['language'] ?? ($website->preferred_language ?: 'en'),
            $validated['search_engine'] ?? 'google',
            $validated['industry_context'] ?? null,
            (bool) ($validated['force_refresh'] ?? false),
            $countries,
            array_key_exists('strict_country_match', $validated) ? (bool) $validated['strict_country_match'] : true
        );

        return $this->success($run, null, 201);
    }

    public function listDiscoveries(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $runs = CompetitorDiscoveryRun::where('website_id', $website->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return $this->success($runs);
    }

    /**
     * Confirm manually selected discovered candidates to add to tracked competitors.
     */
    public function confirmDiscovery(Request $request, Website $website, CompetitorDiscoveryRun $run): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        if ((int) $run->website_id !== (int) $website->id) {
            return $this->error('Discovery run not found for this website.', 'DISCOVERY_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'selected' => ['required', 'array', 'min:1'],
            'selected.*.domain' => ['required', 'string', 'max:255'],
            'selected.*.category' => ['nullable', 'string', Rule::in(CompetitorDomain::CATEGORIES)],
            'selected.*.tier' => ['nullable', 'string', Rule::in([CompetitorDomain::TIER_PRIMARY, CompetitorDomain::TIER_SECONDARY])],
        ]);

        $added = $this->discoveryService->confirmCandidates($website, $run, $validated['selected']);

        // Immediately capture verified snapshots for newly confirmed competitors (up to 3) so data is visible right away
        if (!app()->runningUnitTests()) {
            foreach (array_slice($added, 0, 3) as $comp) {
                try {
                    $this->comparisonService->captureDomainSnapshot($website, $comp->domain, false, $comp, $comp->country, $comp->language, false);
                } catch (\Throwable) {
                    // Non-blocking enrichment
                }
            }
        }

        return $this->success([
            'added_count' => count($added),
            'competitors' => collect($added)->map(fn ($c) => $c->fresh('latestSnapshot'))->values(),
            'discovery_run' => $run->fresh(),
        ]);
    }

    /**
     * Launch competitor analysis background job (full or module-specific).
     */
    public function launchAnalysis(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_SEO_MANAGER);

        $validated = $request->validate([
            'job_type' => ['nullable', 'string', Rule::in(['full_analysis', 'domain_refresh', 'keyword_gap', 'content_gap', 'backlink_gap', 'ai_strategy'])],
            'competitor_ids' => ['nullable', 'array', 'max:5'],
            'competitor_ids.*' => ['integer'],
            'country' => ['nullable', 'string', 'max:10'],
            'language' => ['nullable', 'string', 'max:10'],
            'force_refresh' => ['nullable', 'boolean'],
        ]);

        $jobRecord = CompetitorAnalysisJob::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'initiated_by' => $request->user()->id,
            'job_type' => $validated['job_type'] ?? 'full_analysis',
            'status' => CompetitorAnalysisJob::STATUS_QUEUED,
            'progress_percent' => 0,
            'competitor_ids' => $validated['competitor_ids'] ?? [],
            'parameters' => [
                'country' => strtoupper($validated['country'] ?? ($website->target_country ?: 'US')),
                'language' => strtolower($validated['language'] ?? ($website->preferred_language ?: 'en')),
                'force_refresh' => (bool) ($validated['force_refresh'] ?? false),
            ],
        ]);

        RunCompetitorAnalysisJob::dispatch($jobRecord->id);

        return $this->success($jobRecord->fresh(), null, 202);
    }

    public function listJobs(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $jobs = CompetitorAnalysisJob::where('website_id', $website->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return $this->success($jobs);
    }

    /**
     * Submodule 3: Domain Comparison (target vs up to 5 selected competitors).
     */
    public function comparison(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $rawIds = $request->input('competitor_ids', []);
        if (is_string($rawIds)) {
            $rawIds = array_filter(explode(',', $rawIds));
        }
        $ids = array_values(array_map('intval', is_array($rawIds) ? $rawIds : []));

        return $this->success($this->comparisonService->buildComparison($website, $ids));
    }

    /**
     * Submodule 4: Keyword Gap Analysis (filterable, sortable, paginated, CSV exportable).
     */
    public function keywordGap(Request $request, Website $website): JsonResponse|Response
    {
        $this->authorizeWebsite($request, $website);

        $query = CompetitorKeyword::where('website_id', $website->id);

        if ($request->filled('gap_type') && $request->input('gap_type') !== 'all') {
            $query->where('gap_type', $request->input('gap_type'));
        }
        if ($request->filled('country') && $request->input('country') !== 'all') {
            $query->where('country', strtoupper($request->input('country')));
        }
        if ($request->filled('language') && $request->input('language') !== 'all') {
            $query->where('language', strtolower($request->input('language')));
        }
        if ($request->filled('intent') && $request->input('intent') !== 'all') {
            $query->where('search_intent', $request->input('intent'));
        }
        if ($request->filled('min_volume')) {
            $query->where('search_volume', '>=', (int) $request->input('min_volume'));
        }
        if ($request->filled('max_difficulty')) {
            $query->where('keyword_difficulty', '<=', (int) $request->input('max_difficulty'));
        }
        if ($request->filled('max_position')) {
            $query->where('target_position', '<=', (int) $request->input('max_position'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where('keyword', 'LIKE', "%{$search}%");
        }

        $sortBy = in_array($request->input('sort_by'), ['opportunity_score', 'search_volume', 'keyword_difficulty', 'target_position', 'cpc'], true)
            ? $request->input('sort_by')
            : 'opportunity_score';
        $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        // Optional competitor domain filter in PHP (compatible across SQLite & PostgreSQL JSON)
        $compFilter = strtolower(trim((string) $request->input('competitor', '')));

        if ($request->input('export') === 'csv') {
            $rows = $query->limit(1000)->get();
            if ($compFilter !== '' && $compFilter !== 'all') {
                $rows = $rows->filter(fn (CompetitorKeyword $r) => array_key_exists($compFilter, $r->competitor_positions ?? []))->values();
            }

            $csvLines = ["Keyword,Gap Type,Search Volume,Keyword Difficulty,CPC,Search Intent,Target Position,Competitor Positions,Data Source,Last Updated"];
            foreach ($rows as $row) {
                $compSummary = collect($row->competitor_positions ?? [])
                    ->map(fn ($v, $k) => $k . ':#' . (is_array($v) ? ($v['position'] ?? '-') : $v))
                    ->implode(' | ');
                $csvLines[] = implode(',', array_map([$this, 'csvEscape'], [
                    $row->keyword,
                    $row->gap_type,
                    $row->search_volume ?? 'Data unavailable',
                    $row->keyword_difficulty ?? 'Data unavailable',
                    $row->cpc ?? 'Data unavailable',
                    $row->search_intent ?? 'unknown',
                    $row->target_position ?? 'Not ranking',
                    $compSummary,
                    $row->data_source,
                    $row->last_updated_at?->toIso8601String() ?? '',
                ]));
            }

            return response(implode("\n", $csvLines), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="keyword-gap-' . $website->domain . '.csv"',
            ]);
        }

        if ($compFilter !== '' && $compFilter !== 'all') {
            $all = $query->limit(500)->get()
                ->filter(fn (CompetitorKeyword $r) => array_key_exists($compFilter, $r->competitor_positions ?? []))
                ->values();
            $items = $all->take($request->integer('per_page', 50))->values();
            $total = $all->count();
        } else {
            $paginated = $query->paginate($request->integer('per_page', 50));
            $items = $paginated->items();
            $total = $paginated->total();
        }

        $counts = [
            'all' => CompetitorKeyword::where('website_id', $website->id)->count(),
            'missing' => CompetitorKeyword::where('website_id', $website->id)->where('gap_type', CompetitorKeyword::GAP_MISSING)->count(),
            'weak' => CompetitorKeyword::where('website_id', $website->id)->where('gap_type', CompetitorKeyword::GAP_WEAK)->count(),
            'shared' => CompetitorKeyword::where('website_id', $website->id)->where('gap_type', CompetitorKeyword::GAP_SHARED)->count(),
            'strong' => CompetitorKeyword::where('website_id', $website->id)->where('gap_type', CompetitorKeyword::GAP_STRONG)->count(),
            'untapped' => CompetitorKeyword::where('website_id', $website->id)->where('gap_type', CompetitorKeyword::GAP_UNTAPPED)->count(),
        ];

        return $this->success($items, [
            'total' => $total,
            'counts' => $counts,
            'provider_connected' => $this->providerManager->hasExternalProviderFor('keywords'),
            'active_provider' => $this->providerManager->resolveForCapability('keywords')->getName(),
        ]);
    }

    public function saveKeywordToActionPlan(Request $request, Website $website, CompetitorKeyword $keyword): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        if ((int) $keyword->website_id !== (int) $website->id) {
            return $this->error('Keyword not found for this website.', 'KEYWORD_NOT_FOUND', null, 404);
        }

        $rec = $this->keywordGapService->saveToActionPlan($website, $keyword);

        return $this->success([
            'recommendation' => $rec,
            'keyword' => $keyword->fresh(),
            'message' => 'Keyword opportunity saved to SEO Action Plan.',
        ], null, 201);
    }

    /**
     * Submodule 5: Content Gap Analysis.
     */
    public function contentGap(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $query = CompetitorContentGap::where('website_id', $website->id);
        if ($request->filled('priority') && $request->input('priority') !== 'all') {
            $query->where('priority', $request->input('priority'));
        }
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('intent') && $request->input('intent') !== 'all') {
            $query->where('search_intent', $request->input('intent'));
        }

        $items = $query
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $this->success($items);
    }

    public function analyzeContentGap(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_SEO_MANAGER);

        return $this->success($this->contentGapService->analyze($website));
    }

    public function sendContentGapToStudio(Request $request, Website $website, CompetitorContentGap $gap): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        if ((int) $gap->website_id !== (int) $website->id) {
            return $this->error('Content gap opportunity not found for this website.', 'CONTENT_GAP_NOT_FOUND', null, 404);
        }

        $brief = $this->contentGapService->sendToContentStudio($website, $gap, $request->user()->id);

        return $this->success([
            'content_brief' => $brief,
            'content_gap' => $gap->fresh(),
            'message' => 'Content opportunity sent to Content Studio as a new Content Brief.',
        ], null, 201);
    }

    /**
     * Submodule 6: Backlink Gap Analysis (with CSV export).
     */
    public function backlinkGap(Request $request, Website $website): JsonResponse|Response
    {
        $this->authorizeWebsite($request, $website);

        $report = $this->backlinkGapService->getBacklinkGapReport($website, [
            'mode' => $request->input('mode', 'gap'),
            'link_type' => $request->input('link_type', 'all'),
            'search' => $request->input('search', ''),
        ]);

        if ($request->input('export') === 'csv') {
            $lines = ['Referring Domain,Referring Authority,Competitors Linked,Backlinks Count,Link Type,Sample Anchor,Referring URL,Destination URL,Data Source'];
            foreach ($report['items'] as $row) {
                $lines[] = implode(',', array_map([$this, 'csvEscape'], [
                    $row['referring_domain'] ?? '',
                    $row['referring_authority'] ?? 'Data unavailable',
                    implode(' | ', $row['competitors_linked'] ?? []),
                    $row['backlinks_count'] ?? 0,
                    $row['link_type'] ?? 'unknown',
                    $row['sample_anchor'] ?? '',
                    $row['sample_referring_url'] ?? '',
                    $row['sample_destination_url'] ?? '',
                    $row['data_source'] ?? '',
                ]));
            }
            return response(implode("\n", $lines), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="backlink-gap-' . $website->domain . '.csv"',
            ]);
        }

        return $this->success($report);
    }

    /**
     * Submodule 7: SERP Analysis.
     */
    public function analyzeSerp(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_SEO_MANAGER);

        $validated = $request->validate([
            'keyword' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:10'],
            'language' => ['nullable', 'string', 'max:10'],
            'device' => ['nullable', 'string', Rule::in(['desktop', 'mobile'])],
            'location' => ['nullable', 'string', 'max:120'],
            'monitor' => ['nullable', 'boolean'],
            'force_refresh' => ['nullable', 'boolean'],
        ]);

        $res = $this->serpAnalysisService->analyzeKeyword(
            $website,
            $validated['keyword'],
            $validated['country'] ?? ($website->target_country ?: 'US'),
            $validated['language'] ?? ($website->preferred_language ?: 'en'),
            $validated['device'] ?? 'desktop',
            $validated['location'] ?? null,
            (bool) ($validated['monitor'] ?? false),
            (bool) ($validated['force_refresh'] ?? false)
        );

        if (!($res['available'] ?? false)) {
            return $this->error(
                $res['error'] ?? 'SERP provider unavailable.',
                'SERP_PROVIDER_UNAVAILABLE',
                ['provider' => $res['provider'] ?? 'unconfigured'],
                422
            );
        }

        return $this->success($res, null, 201);
    }

    public function listSerpSnapshots(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $query = CompetitorSerpSnapshot::where('website_id', $website->id);
        if ($request->boolean('monitored_only', false)) {
            $query->where('is_monitored', true);
        }
        if ($request->filled('keyword')) {
            $query->where('keyword', 'LIKE', '%' . trim((string) $request->input('keyword')) . '%');
        }

        $snapshots = $query->orderByDesc('captured_at')->limit(40)->get();

        return $this->success($snapshots, [
            'provider_connected' => $this->providerManager->hasExternalProviderFor('serp'),
            'active_provider' => $this->providerManager->resolveForCapability('serp')->getName(),
        ]);
    }

    public function toggleSerpMonitor(Request $request, Website $website, CompetitorSerpSnapshot $snapshot): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        if ((int) $snapshot->website_id !== (int) $website->id) {
            return $this->error('SERP snapshot not found.', 'SERP_SNAPSHOT_NOT_FOUND', null, 404);
        }

        $snapshot->update(['is_monitored' => !$snapshot->is_monitored]);

        return $this->success($snapshot->fresh());
    }

    /**
     * Submodule 8: AI Competitor Strategist.
     */
    public function listStrategyReports(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $reports = CompetitorStrategyReport::where('website_id', $website->id)
            ->orderByDesc('generated_at')
            ->limit(15)
            ->get();

        return $this->success($reports);
    }

    public function generateStrategy(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_SEO_MANAGER);

        $competitorIds = $request->input('competitor_ids', []);
        $report = $this->strategistService->generateReport(
            $website,
            $request->user()->id,
            is_array($competitorIds) ? array_map('intval', $competitorIds) : []
        );

        return $this->success($report, null, 201);
    }

    public function saveStrategyAction(Request $request, Website $website, CompetitorStrategyReport $report): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_EDITOR);

        if ((int) $report->website_id !== (int) $website->id) {
            return $this->error('Strategy report not found for this website.', 'REPORT_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'recommendation_index' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $rec = $this->strategistService->saveRecommendationToActionPlan(
                $website,
                $report,
                (int) $validated['recommendation_index']
            );

            return $this->success([
                'recommendation' => $rec,
                'report' => $report->fresh(),
                'message' => 'Strategic recommendation saved to SEO Action Plan.',
            ], null, 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'INVALID_INDEX', null, 422);
        }
    }

    /**
     * Submodule 9: Competitor Monitoring & History.
     */
    public function monitoring(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        return $this->success([
            'config' => $this->monitoringService->getOrCreateConfig($website),
            'history' => $this->monitoringService->getHistoricalTimeline($website),
            'providers' => $this->providerManager->getStatusSummary($website->organization_id, $website->id),
        ]);
    }

    public function updateMonitoring(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_SEO_MANAGER);

        $validated = $request->validate([
            'frequency' => ['required', 'string', Rule::in(['daily', 'weekly', 'monthly', 'manual'])],
            'country' => ['nullable', 'string', 'max:10'],
            'language' => ['nullable', 'string', 'max:10'],
            'notify_on_rank_change' => ['nullable', 'boolean'],
            'notify_on_new_keywords' => ['nullable', 'boolean'],
            'notify_on_backlink_change' => ['nullable', 'boolean'],
            'min_position_delta' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->success($this->monitoringService->updateConfig($website, $validated));
    }

    public function runMonitoring(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->requireMinimumRole($request, OrganizationMember::ROLE_SEO_MANAGER);

        $result = $this->monitoringService->runMonitoringCheck(
            $website,
            $request->boolean('force_refresh', false)
        );

        return $this->success($result);
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
