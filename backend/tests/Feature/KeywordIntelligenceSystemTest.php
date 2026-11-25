<?php

namespace Tests\Feature;

use App\Jobs\RunKeywordAutomationJob;
use App\Models\AiRecommendation;
use App\Models\CompetitorDomain;
use App\Models\CompetitorKeyword;
use App\Models\CompetitorSerpSnapshot;
use App\Models\ContentBrief;
use App\Models\KiAlert;
use App\Models\KiAutomationWorkflow;
use App\Models\KiCluster;
use App\Models\KiKeyword;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\SearchPerformanceRecord;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KeywordIntelligenceSystemTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Organization $org;
    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->org = Organization::create([
            'name' => 'Alpha SEO Agency',
            'slug' => 'alpha-seo-' . uniqid(),
            'owner_id' => $this->user->id,
            'billing_plan' => 'agency_internal',
        ]);
        OrganizationMember::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        $this->website = Website::create([
            'organization_id' => $this->org->id,
            'name' => 'CarePlus Dhaka',
            'domain' => 'careplusdhaka.com',
            'target_url' => 'https://careplusdhaka.com',
            'target_country' => 'US',
            'preferred_language' => 'en',
            'status' => 'active',
        ]);
    }

    private function authHeaders(?int $orgId = null): array
    {
        return [
            'X-Organization-Id' => (string) ($orgId ?? $this->org->id),
            'Accept' => 'application/json',
        ];
    }

    public function test_discovery_combines_sources_prevents_duplicates_and_never_invents_metrics(): void
    {
        // Seed first-party GSC data
        SearchPerformanceRecord::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'property_id' => 'sc-domain:careplusdhaka.com',
            'date' => now()->subDays(5)->toDateString(),
            'query' => 'home nursing care dhaka',
            'page' => 'https://careplusdhaka.com/home-nursing',
            'clicks' => 42,
            'impressions' => 680,
            'ctr' => 6.18,
            'position' => 7.4,
        ]);

        // Seed competitor gap keyword
        $comp = CompetitorDomain::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'domain' => 'rivalcare.com',
            'name' => 'Rival Care',
            'tier' => 'primary',
            'is_active' => true,
        ]);
        CompetitorKeyword::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'keyword' => 'elderly care cost per month',
            'country' => 'US',
            'language' => 'en',
            'search_volume' => 1400,
            'keyword_difficulty' => 32,
            'cpc' => 2.40,
            'search_intent' => 'commercial',
            'competitor_positions' => ['rivalcare.com' => 4],
            'target_position' => null,
            'gap_type' => 'missing',
            'data_source' => 'dataforseo',
            'last_updated_at' => now(),
        ]);

        // Fake Google Autocomplete HTTP responses
        Http::fake([
            'suggestqueries.google.com/*' => Http::response([
                'home nursing care',
                [
                    'home nursing care',
                    'how to hire home nursing care',
                    'best home nursing care services near me',
                ],
            ], 200),
            '*' => Http::response([], 404),
        ]);

        $res = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/discover", [
                'seed_type' => 'keyword',
                'seed_keyword' => 'home nursing care',
                'country' => 'US',
                'language' => 'en',
                'sources' => ['autocomplete', 'questions', 'long_tail', 'gsc_queries', 'keyword_gap'],
            ]);

        $res->assertOk()
            ->assertJsonPath('success', true);

        // Verify Autocomplete keywords have NULL volume/KD/CPC (never invented!)
        $acKw = KiKeyword::where('website_id', $this->website->id)
            ->where('normalized_keyword', 'how to hire home nursing care')
            ->first();
        $this->assertNotNull($acKw);
        $this->assertNull($acKw->search_volume);
        $this->assertNull($acKw->keyword_difficulty);
        $this->assertNull($acKw->cpc);
        $this->assertSame('how', $acKw->question_category);
        $this->assertTrue($acKw->is_long_tail);

        // Verify GSC first-party enrichment preserved clicks/impressions/position
        $gscKw = KiKeyword::where('website_id', $this->website->id)
            ->where('normalized_keyword', 'home nursing care dhaka')
            ->first();
        $this->assertNotNull($gscKw);
        $this->assertSame(680, $gscKw->gsc_impressions);
        $this->assertSame(42, $gscKw->gsc_clicks);
        $this->assertNull($gscKw->search_volume); // GSC impressions are NOT invented as monthly volume

        // Verify competitor gap preserved real provider metrics
        $gapKw = KiKeyword::where('website_id', $this->website->id)
            ->where('normalized_keyword', 'elderly care cost per month')
            ->first();
        $this->assertNotNull($gapKw);
        $this->assertSame(1400, $gapKw->search_volume);
        $this->assertSame(32.0, (float) $gapKw->keyword_difficulty);

        $countAfterFirstRun = KiKeyword::where('website_id', $this->website->id)->count();

        // Run identical discovery a second time -> ZERO duplicate keyword rows!
        $res2 = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/discover", [
                'seed_type' => 'keyword',
                'seed_keyword' => 'home nursing care',
                'country' => 'US',
                'language' => 'en',
                'sources' => ['autocomplete', 'questions', 'long_tail', 'gsc_queries', 'keyword_gap'],
            ]);

        $res2->assertOk()
            ->assertJsonPath('data.new_keywords', 0);

        $this->assertSame($countAfterFirstRun, KiKeyword::where('website_id', $this->website->id)->count());

        // Verify Website URL discovery mode for BD market & PostgreSQL VARCHAR length safety
        $urlRes = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/discover", [
                'seed_type' => 'url',
                'website_url' => 'https://www.carematebd.com',
                'country' => 'BD',
                'language' => 'en',
                'search_engine' => 'google',
                'device' => 'desktop',
                'search_network' => 'google_search',
            ]);

        $urlRes->assertOk()->assertJsonPath('success', true);
        foreach (KiKeyword::where('website_id', $this->website->id)->get() as $persisted) {
            $this->assertLessThanOrEqual(100, strlen((string) $persisted->ranking_potential));
            $this->assertLessThanOrEqual(50, strlen((string) $persisted->opportunity_category));
        }
    }

    public function test_provider_failure_is_isolated_gracefully(): void
    {
        Http::fake([
            'suggestqueries.google.com/*' => Http::response('Service Unavailable', 503),
            '*' => Http::response('Error', 500),
        ]);

        $res = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/discover", [
                'seed_type' => 'list',
                'keyword_list' => "home care service\npatient care tips",
                'country' => 'US',
                'language' => 'en',
            ]);

        $res->assertOk()
            ->assertJsonPath('success', true);
        $this->assertGreaterThanOrEqual(2, KiKeyword::where('website_id', $this->website->id)->count());
    }

    public function test_semantic_and_serp_clustering_respects_intent_conflict_guard_and_supports_merge_split(): void
    {
        $k1 = KiKeyword::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'keyword' => 'home nursing care guide',
            'normalized_keyword' => 'home nursing care guide',
            'country' => 'US',
            'language' => 'en',
            'search_intent' => 'informational',
            'intent_source' => 'rule_based',
            'opportunity_score' => 78,
            'serp_top_urls' => [
                ['position' => 1, 'url' => 'https://example.com/nursing-guide', 'domain' => 'example.com', 'title' => 'Guide'],
                ['position' => 2, 'url' => 'https://health.org/home-nursing', 'domain' => 'health.org', 'title' => 'Health'],
                ['position' => 3, 'url' => 'https://care.net/nursing-tips', 'domain' => 'care.net', 'title' => 'Tips'],
            ],
        ]);
        $k2 = KiKeyword::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'keyword' => 'home nursing care checklist',
            'normalized_keyword' => 'home nursing care checklist',
            'country' => 'US',
            'language' => 'en',
            'search_intent' => 'informational',
            'intent_source' => 'rule_based',
            'opportunity_score' => 72,
            'serp_top_urls' => [
                ['position' => 1, 'url' => 'https://example.com/nursing-guide', 'domain' => 'example.com', 'title' => 'Guide'],
                ['position' => 2, 'url' => 'https://health.org/home-nursing', 'domain' => 'health.org', 'title' => 'Health'],
                ['position' => 4, 'url' => 'https://other.org/checklist', 'domain' => 'other.org', 'title' => 'Checklist'],
            ],
        ]);
        $k3 = KiKeyword::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'keyword' => 'hire home nursing care service',
            'normalized_keyword' => 'hire home nursing care service',
            'country' => 'US',
            'language' => 'en',
            'search_intent' => 'transactional',
            'intent_source' => 'rule_based',
            'opportunity_score' => 84,
            'serp_top_urls' => [
                ['position' => 1, 'url' => 'https://example.com/nursing-guide', 'domain' => 'example.com', 'title' => 'Guide'],
                ['position' => 2, 'url' => 'https://health.org/home-nursing', 'domain' => 'health.org', 'title' => 'Health'],
                ['position' => 3, 'url' => 'https://hirecare.com/book', 'domain' => 'hirecare.com', 'title' => 'Book'],
            ],
        ]);

        // SERP clustering: k1 & k2 share >=2 URLs AND informational intent -> grouped together!
        // k3 has transactional intent -> MUST NOT be merged into the informational cluster!
        $genRes = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/clusters/generate", [
                'method' => 'serp',
            ]);

        $genRes->assertOk()
            ->assertJsonPath('data.clusters_created', 2);

        $clusters = KiCluster::where('website_id', $this->website->id)->orderBy('id')->get();
        $infoCluster = $clusters->firstWhere('search_intent', 'informational');
        $transCluster = $clusters->firstWhere('search_intent', 'transactional');
        $this->assertSame(2, $infoCluster->keyword_count);
        $this->assertSame(1, $transCluster->keyword_count);

        // Attempting to merge clusters with conflicting search intents MUST be blocked (422)
        $mergeConflict = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/clusters/merge", [
                'cluster_ids' => [$infoCluster->id, $transCluster->id],
            ]);
        $mergeConflict->assertStatus(422);

        // Split the 2-keyword informational cluster into two separate clusters
        $splitRes = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/clusters/{$infoCluster->id}/split", [
                'keyword_ids' => [$k2->id],
                'new_cluster_name' => 'Nursing Checklists',
            ]);
        $splitRes->assertOk()
            ->assertJsonPath('data.new_cluster.name', 'Nursing Checklists');

        // Send cluster to Content Studio -> creates draft ContentBrief with cluster keywords
        $studioRes = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/clusters/{$transCluster->id}/send-content-studio");
        $studioRes->assertStatus(201);
        $this->assertDatabaseHas('content_briefs', [
            'website_id' => $this->website->id,
            'status' => ContentBrief::STATUS_DRAFT,
        ]);
    }

    public function test_opportunity_scoring_weights_and_ai_intent_classification(): void
    {
        $kw = KiKeyword::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'keyword' => 'best elderly care service dhaka',
            'normalized_keyword' => 'best elderly care service dhaka',
            'country' => 'US',
            'language' => 'en',
            'search_volume' => 1200,
            'keyword_difficulty' => 24,
            'search_intent' => 'commercial',
            'intent_source' => 'rule_based',
            'intent_confidence' => 0.80,
            'opportunity_score' => 50,
            'opportunity_category' => 'medium_opportunity',
        ]);

        // Update scoring weights and rescore
        $weightsRes = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->putJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/scoring-weights", [
                'weights' => [
                    'demand' => 40,
                    'difficulty' => 30,
                    'relevance' => 15,
                    'intent' => 15,
                    'position_potential' => 0,
                    'competitor_gap' => 0,
                    'trend' => 0,
                ],
            ]);

        $weightsRes->assertOk()
            ->assertJsonPath('data.rescored_count', 1);

        $kw->refresh();
        $this->assertGreaterThan(70, (float) $kw->opportunity_score);

        // AI-inferred intent classification explicitly tags `ai_inferred` with confidence
        $aiRes = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/intent/classify", [
                'keyword_ids' => [$kw->id],
            ]);

        $aiRes->assertOk()
            ->assertJsonPath('data.updated', 1);
        $kw->refresh();
        $this->assertSame('ai_inferred', $kw->intent_source);
        $this->assertNotNull($kw->intent_confidence);
    }

    public function test_cannibalization_detection_requires_evidence_and_avoids_false_positives(): void
    {
        // Case 1: Genuine cannibalization (2 URLs splitting 400 impressions, 50/50 split, position spread 7.5)
        SearchPerformanceRecord::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'property_id' => 'sc-domain:careplusdhaka.com',
            'date' => now()->subDays(10)->toDateString(),
            'query' => 'physiotherapy at home',
            'page' => 'https://careplusdhaka.com/physiotherapy',
            'clicks' => 12,
            'impressions' => 220,
            'ctr' => 5.45,
            'position' => 6.2,
        ]);
        SearchPerformanceRecord::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'property_id' => 'sc-domain:careplusdhaka.com',
            'date' => now()->subDays(10)->toDateString(),
            'query' => 'physiotherapy at home',
            'page' => 'https://careplusdhaka.com/blog/home-physio-guide',
            'clicks' => 8,
            'impressions' => 180,
            'ctr' => 4.44,
            'position' => 13.7,
        ]);

        // Case 2: Low-volume noise (only 6 total impressions) -> MUST NOT be flagged as cannibalization!
        SearchPerformanceRecord::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'property_id' => 'sc-domain:careplusdhaka.com',
            'date' => now()->subDays(10)->toDateString(),
            'query' => 'tiny noisy query',
            'page' => 'https://careplusdhaka.com/a',
            'clicks' => 0,
            'impressions' => 4,
            'ctr' => 0,
            'position' => 15.0,
        ]);
        SearchPerformanceRecord::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'property_id' => 'sc-domain:careplusdhaka.com',
            'date' => now()->subDays(10)->toDateString(),
            'query' => 'tiny noisy query',
            'page' => 'https://careplusdhaka.com/b',
            'clicks' => 0,
            'impressions' => 2,
            'ctr' => 0,
            'position' => 20.0,
        ]);

        $res = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/cannibalization/analyze", [
                'days' => 90,
            ]);

        $res->assertOk()
            ->assertJsonPath('data.findings_count', 1)
            ->assertJsonPath('data.high_confidence_count', 1);

        $this->assertDatabaseHas('ki_cannibalization_findings', [
            'website_id' => $this->website->id,
            'keyword' => 'physiotherapy at home',
            'confidence' => 'high',
        ]);
        $this->assertDatabaseMissing('ki_cannibalization_findings', [
            'website_id' => $this->website->id,
            'keyword' => 'tiny noisy query',
        ]);
    }

    public function test_automation_workflow_execution_scheduler_alerts_and_action_plan_handoff(): void
    {
        // Create automation workflow
        $createRes = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/automations", [
                'name' => 'Weekly Care Discovery',
                'seed_keywords' => ['home nursing care', 'patient care dhaka'],
                'target_country' => 'US',
                'target_language' => 'en',
                'schedule_frequency' => 'weekly',
                'auto_cluster' => true,
                'auto_monitor_rank' => false,
                'auto_content_recommendations' => true,
                'notify_on_opportunities' => true,
                'min_opportunity_score' => 50,
            ]);

        $createRes->assertStatus(201);
        $workflowId = $createRes->json('data.id');

        // Run manually (offline/fast mode for deterministic test)
        $runRes = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/automations/{$workflowId}/run", [
                'skip_external_http' => true,
            ]);

        $runRes->assertOk()
            ->assertJsonPath('data.run.status', 'completed');

        $this->assertGreaterThan(0, KiKeyword::where('website_id', $this->website->id)->count());
        $this->assertGreaterThan(0, KiCluster::where('website_id', $this->website->id)->count());
        $this->assertGreaterThan(0, KiAlert::where('website_id', $this->website->id)->count());
        // Draft proposed recommendations created in SEO Action Plan (never auto-published!)
        $this->assertDatabaseHas('ai_recommendations', [
            'website_id' => $this->website->id,
            'category' => 'keyword_opportunity',
            'status' => AiRecommendation::STATUS_PROPOSED,
        ]);

        // Test Scheduler command dispatches RunKeywordAutomationJob for due workflows
        Queue::fake();
        KiAutomationWorkflow::where('id', $workflowId)->update(['next_run_at' => now()->subMinutes(10)]);
        Artisan::call('keywords:run-scheduled-automations');
        Queue::assertPushed(RunKeywordAutomationJob::class, fn ($job) => $job->workflowId === $workflowId);
    }

    public function test_csv_exports_action_plan_strategist_and_rank_checker_integration(): void
    {
        $kw = KiKeyword::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'keyword' => 'home nursing care dhaka',
            'normalized_keyword' => 'home nursing care dhaka',
            'country' => 'US',
            'language' => 'en',
            'search_volume' => null,
            'keyword_difficulty' => null,
            'search_intent' => 'transactional',
            'intent_source' => 'rule_based',
            'opportunity_score' => 81,
            'opportunity_category' => 'high_opportunity',
            'recommended_priority' => 'P1',
            'data_source' => 'google_autocomplete',
        ]);

        // CSV Export prints "Data Unavailable" for null metrics (never invents 0 or fake numbers)
        $csvRes = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->get("/api/v1/websites/{$this->website->id}/keyword-intelligence/keywords?export=csv");
        $csvRes->assertOk();
        $this->assertStringContainsString('Data Unavailable', $csvRes->getContent());
        $this->assertStringContainsString('home nursing care dhaka', $csvRes->getContent());

        // Action Plan integration
        $apRes = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/keywords/{$kw->id}/send-action-plan");
        $apRes->assertStatus(201);

        // AI Keyword Strategist returns grounded strategy with disclaimer
        $stratRes = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/strategist/analyze", [
                'goal' => 'Expand home care visibility',
            ]);
        $stratRes->assertOk()
            ->assertJsonPath('data.classification', 'ai_inferred')
            ->assertJsonStructure(['data' => ['strategy' => ['executive_summary', 'recommended_seed_keywords', 'prioritization_roadmap']]]);

        // Overview reflects real persisted counts
        $ovRes = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->getJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/overview");
        $ovRes->assertOk()
            ->assertJsonPath('data.metrics.total_researched_keywords', 1)
            ->assertJsonPath('data.metrics.total_saved_keywords', 1)
            ->assertJsonPath('data.metrics.avg_keyword_difficulty', null)
            ->assertJsonPath('data.metrics.total_estimated_search_volume', null);

        // Rank Checker integration reuses RankCheckerService (gracefully handles unconfigured SERP without crashing)
        $rankRes = $this->actingAs($this->user)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/keywords/{$kw->id}/monitor-rank");
        $rankRes->assertOk()
            ->assertJsonPath('data.keyword.is_monitored', true);
    }

    public function test_rbac_and_tenant_isolation_protect_keyword_intelligence_endpoints(): void
    {
        // 1. Viewer in same org can READ overview but CANNOT run discovery or create automations (403)
        $viewer = User::factory()->create();
        OrganizationMember::create([
            'organization_id' => $this->org->id,
            'user_id' => $viewer->id,
            'role' => OrganizationMember::ROLE_VIEWER,
        ]);

        $this->actingAs($viewer)
            ->withHeaders($this->authHeaders())
            ->getJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/overview")
            ->assertOk();

        $this->actingAs($viewer)
            ->withHeaders($this->authHeaders())
            ->postJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/discover", [
                'seed_keyword' => 'blocked viewer test',
            ])
            ->assertStatus(403);

        // 2. Owner of a different organization CANNOT access Org A's website (403)
        $otherUser = User::factory()->create();
        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'slug' => 'other-org-' . uniqid(),
            'owner_id' => $otherUser->id,
        ]);
        OrganizationMember::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        $this->actingAs($otherUser)
            ->withHeaders($this->authHeaders($otherOrg->id))
            ->getJson("/api/v1/websites/{$this->website->id}/keyword-intelligence/overview")
            ->assertStatus(403);
    }
}
