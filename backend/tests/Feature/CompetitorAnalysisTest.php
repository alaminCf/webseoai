<?php

namespace Tests\Feature;

use App\Domain\Competitors\Services\KeywordGapService;
use App\Jobs\RunCompetitorAnalysisJob;
use App\Models\AiRecommendation;
use App\Models\CompetitorAnalysisJob;
use App\Models\CompetitorBacklink;
use App\Models\CompetitorContentGap;
use App\Models\CompetitorDomain;
use App\Models\CompetitorKeyword;
use App\Models\ContentBrief;
use App\Models\CrawledPage;
use App\Models\CrawlJob;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompetitorAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private User $seoManager;
    private User $viewer;
    private User $foreignUser;
    private Organization $org;
    private Organization $foreignOrg;
    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seoManager = User::factory()->create(['email' => 'seo@techboloy.com']);
        $this->viewer = User::factory()->create(['email' => 'viewer@techboloy.com']);
        $this->foreignUser = User::factory()->create(['email' => 'rival@otheragency.com']);

        $this->org = Organization::create([
            'name' => 'Techboloy Agency',
            'slug' => 'techboloy-agency',
            'owner_id' => $this->seoManager->id,
        ]);

        OrganizationMember::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->seoManager->id,
            'role' => OrganizationMember::ROLE_SEO_MANAGER,
        ]);

        OrganizationMember::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->viewer->id,
            'role' => OrganizationMember::ROLE_VIEWER,
        ]);

        $this->foreignOrg = Organization::create([
            'name' => 'Other Agency',
            'slug' => 'other-agency',
            'owner_id' => $this->foreignUser->id,
        ]);

        OrganizationMember::create([
            'organization_id' => $this->foreignOrg->id,
            'user_id' => $this->foreignUser->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        $this->website = Website::create([
            'organization_id' => $this->org->id,
            'name' => 'CareMate BD',
            'domain' => 'carematebd.com',
            'target_url' => 'https://carematebd.com',
            'platform' => Website::PLATFORM_CUSTOM,
            'target_country' => 'BD',
            'preferred_language' => 'en',
            'verification_status' => Website::VERIFICATION_VERIFIED,
        ]);
    }

    private function authHeaders(User $user, Organization $org): array
    {
        return ['X-Organization-Id' => (string) $org->id];
    }

    public function test_manual_competitor_crud_and_self_domain_rejection(): void
    {
        // Reject adding target website as its own competitor
        $selfRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors", [
                'domain' => 'https://www.carematebd.com/',
            ]);
        $selfRes->assertStatus(422)->assertJsonPath('error.code', 'SELF_DOMAIN_REJECTED');

        // Add primary direct competitor
        $addRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors", [
                'domain' => 'https://www.homecarebd.com/services',
                'category' => 'direct',
                'tier' => 'primary',
                'country' => 'BD',
                'language' => 'en',
            ]);

        $addRes->assertStatus(201)
            ->assertJsonPath('data.domain', 'homecarebd.com')
            ->assertJsonPath('data.tier', 'primary')
            ->assertJsonPath('data.category', 'direct');

        $compId = $addRes->json('data.id');

        // Update category & tier
        $patchRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->patchJson("/api/v1/websites/{$this->website->id}/competitors/{$compId}", [
                'category' => 'organic_search',
                'tier' => 'secondary',
            ]);
        $patchRes->assertOk()
            ->assertJsonPath('data.category', 'organic_search')
            ->assertJsonPath('data.tier', 'secondary');

        // Delete competitor
        $delRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->deleteJson("/api/v1/websites/{$this->website->id}/competitors/{$compId}");
        $delRes->assertOk();
        $this->assertDatabaseMissing('competitor_domains', ['id' => $compId]);
    }

    public function test_competitor_discovery_filters_portals_and_requires_manual_confirmation(): void
    {
        config([
            'competitors.providers.dataforseo.login' => 'test_login',
            'competitors.providers.dataforseo.password' => 'test_pass',
        ]);

        Http::fake([
            'https://api.dataforseo.com/v3/dataforseo_labs/google/competitors_domain/live' => Http::response([
                'tasks' => [[
                    'cost' => 0.01,
                    'result' => [[
                        'items' => [
                            ['domain' => 'wikipedia.org', 'intersections' => 25, 'avg_position' => 2.1],
                            ['domain' => 'homecarebd.com', 'intersections' => 14, 'avg_position' => 4.8, 'full_domain_metrics' => ['organic' => ['etv' => 4200]]],
                            ['domain' => 'elderlyblog.org', 'intersections' => 1, 'avg_position' => 12.0],
                        ],
                    ]],
                ]],
            ], 200),
        ]);

        $discRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors/discover", [
                'country' => 'BD',
                'language' => 'en',
            ]);

        $discRes->assertStatus(201);
        $candidates = collect($discRes->json('data.candidates'));

        // Portal wikipedia.org must NOT be classified as direct business competitor
        $wiki = $candidates->firstWhere('domain', 'wikipedia.org');
        $this->assertEquals('indirect', $wiki['category']);
        $this->assertTrue($wiki['is_portal_filtered']);

        // Real rival homecarebd.com is classified as direct
        $rival = $candidates->firstWhere('domain', 'homecarebd.com');
        $this->assertEquals('direct', $rival['category']);

        // Single-keyword site must NOT be classified as direct
        $blog = $candidates->firstWhere('domain', 'elderlyblog.org');
        $this->assertEquals('content', $blog['category']);

        // Nothing added automatically before manual confirmation
        $this->assertEquals(0, CompetitorDomain::where('website_id', $this->website->id)->count());

        // Confirm selected competitor
        $runId = $discRes->json('data.id');
        $confirmRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors/discoveries/{$runId}/confirm", [
                'selected' => [
                    ['domain' => 'homecarebd.com', 'category' => 'direct', 'tier' => 'primary'],
                ],
            ]);

        $confirmRes->assertOk()->assertJsonPath('data.added_count', 1);
        $this->assertDatabaseHas('competitor_domains', [
            'website_id' => $this->website->id,
            'domain' => 'homecarebd.com',
        ]);
    }

    public function test_domain_comparison_keyword_gap_content_gap_backlink_gap_serp_and_ai_strategy(): void
    {
        config([
            'competitors.providers.dataforseo.login' => 'test_login',
            'competitors.providers.dataforseo.password' => 'test_pass',
        ]);

        // Seed crawled target page so Content Gap can verify missing vs covered topics
        $crawlJob = CrawlJob::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'initiated_by' => $this->seoManager->id,
            'status' => CrawlJob::STATUS_COMPLETED,
        ]);
        CrawledPage::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'crawl_job_id' => $crawlJob->id,
            'url' => 'https://carematebd.com',
            'status_code' => 200,
            'page_title' => 'CareMate BD | Home Nursing Services',
            'h1_tags' => ['Trusted Home Nursing in Dhaka'],
            'word_count' => 620,
            'is_indexable' => true,
        ]);

        $comp = CompetitorDomain::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'domain' => 'homecarebd.com',
            'display_name' => 'HomeCare BD',
            'category' => 'direct',
            'tier' => 'primary',
            'country' => 'BD',
            'language' => 'en',
        ]);

        Http::fake([
            'https://homecarebd.com*' => Http::response('<html><head><title>HomeCare BD Senior Care</title></head><body><h1>Senior Care Dhaka</h1><a href="https://homecarebd.com/dementia-care">Dementia Care</a></body></html>', 200),
            'https://api.dataforseo.com/v3/dataforseo_labs/google/domain_rank_overview/live' => Http::response([
                'tasks' => [[
                    'cost' => 0.01,
                    'result' => [[
                        'items' => [[
                            'metrics' => [
                                'organic' => [
                                    'etv' => 3500,
                                    'count' => 240,
                                    'estimated_paid_traffic_cost' => 1850.50,
                                    'pos_1' => 12,
                                    'pos_2_3' => 18,
                                    'pos_4_10' => 45,
                                    'pos_11_20' => 60,
                                ],
                            ],
                        ]],
                    ]],
                ]],
            ], 200),
            'https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live' => Http::sequence()
                // Target domain keywords
                ->push([
                    'tasks' => [[
                        'cost' => 0.01,
                        'result' => [[
                            'items' => [[
                                'keyword_data' => [
                                    'keyword' => 'home nursing dhaka',
                                    'keyword_info' => ['search_volume' => 1200, 'cpc' => 1.50],
                                    'keyword_properties' => ['keyword_difficulty' => 28],
                                    'search_intent_info' => ['main_intent' => 'transactional'],
                                ],
                                'ranked_serp_element' => [
                                    'serp_item' => ['rank_absolute' => 14, 'url' => 'https://carematebd.com'],
                                    'serp_item_types' => ['local_pack'],
                                ],
                            ]],
                        ]],
                    ]],
                ], 200)
                // Competitor domain keywords
                ->push([
                    'tasks' => [[
                        'cost' => 0.01,
                        'result' => [[
                            'items' => [
                                [
                                    'keyword_data' => [
                                        'keyword' => 'home nursing dhaka',
                                        'keyword_info' => ['search_volume' => 1200, 'cpc' => 1.50],
                                        'keyword_properties' => ['keyword_difficulty' => 28],
                                        'search_intent_info' => ['main_intent' => 'transactional'],
                                    ],
                                    'ranked_serp_element' => [
                                        'serp_item' => ['rank_absolute' => 3, 'url' => 'https://homecarebd.com/nursing'],
                                        'serp_item_types' => ['local_pack'],
                                    ],
                                ],
                                [
                                    'keyword_data' => [
                                        'keyword' => 'dementia caregiver service dhaka',
                                        'keyword_info' => ['search_volume' => 880, 'cpc' => 2.10],
                                        'keyword_properties' => ['keyword_difficulty' => 45],
                                        'search_intent_info' => ['main_intent' => 'commercial'],
                                    ],
                                    'ranked_serp_element' => [
                                        'serp_item' => ['rank_absolute' => 2, 'url' => 'https://homecarebd.com/dementia-care'],
                                        'serp_item_types' => ['featured_snippet'],
                                    ],
                                ],
                            ],
                        ]],
                    ]],
                ], 200),
            'https://api.dataforseo.com/v3/backlinks/summary/live' => Http::response([
                'tasks' => [[
                    'cost' => 0.01,
                    'result' => [['referring_domains' => 64, 'backlinks' => 310, 'rank' => 420]],
                ]],
            ], 200),
            'https://api.dataforseo.com/v3/backlinks/backlinks/live' => Http::sequence()
                ->push(['tasks' => [['cost' => 0.01, 'result' => [['items' => [
                    ['domain_from' => 'sharedhealth.org', 'url_from' => 'https://sharedhealth.org/dir', 'url_to' => 'https://carematebd.com', 'anchor' => 'CareMate', 'dofollow' => true, 'domain_from_rank' => 500],
                ]]]]]], 200)
                ->push(['tasks' => [['cost' => 0.01, 'result' => [['items' => [
                    ['domain_from' => 'sharedhealth.org', 'url_from' => 'https://sharedhealth.org/dir', 'url_to' => 'https://homecarebd.com', 'anchor' => 'HomeCare', 'dofollow' => true, 'domain_from_rank' => 500],
                    ['domain_from' => 'bdmedicalnews.com', 'url_from' => 'https://bdmedicalnews.com/top-care', 'url_to' => 'https://homecarebd.com', 'anchor' => 'senior home care', 'dofollow' => true, 'domain_from_rank' => 650, 'is_new' => true],
                ]]]]]], 200),
            'https://api.dataforseo.com/v3/serp/google/organic/live/advanced' => Http::response([
                'tasks' => [[
                    'cost' => 0.003,
                    'result' => [[
                        'item_types' => ['organic', 'people_also_ask', 'local_pack'],
                        'items' => [
                            ['type' => 'organic', 'rank_group' => 1, 'domain' => 'homecarebd.com', 'url' => 'https://homecarebd.com/dementia-care', 'title' => 'Best Dementia Care Guide in Dhaka', 'description' => 'Trusted dementia care.'],
                            ['type' => 'organic', 'rank_group' => 2, 'domain' => 'carematebd.com', 'url' => 'https://carematebd.com', 'title' => 'CareMate BD Home Care', 'description' => 'Home nursing.'],
                        ],
                    ]],
                ]],
            ], 200),
        ]);

        // 1. Launch Full Analysis Job
        $analyzeRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors/analyze", [
                'job_type' => 'full_analysis',
                'country' => 'BD',
                'language' => 'en',
            ]);
        $analyzeRes->assertStatus(202)->assertJsonPath('data.status', 'completed');

        // 2. Domain Comparison returns target + competitor with real provider data
        $compRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->getJson("/api/v1/websites/{$this->website->id}/competitors/comparison");
        $compRes->assertOk();
        $this->assertCount(2, $compRes->json('data.domains'));

        // 3. Keyword Gap correctly classifies weak (#14 vs #3) and missing (null vs #2)
        $kwRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->getJson("/api/v1/websites/{$this->website->id}/competitors/keyword-gap");
        $kwRes->assertOk();
        $kws = collect($kwRes->json('data'));
        $this->assertEquals('weak', $kws->firstWhere('keyword', 'home nursing dhaka')['gap_type']);
        $this->assertEquals('missing', $kws->firstWhere('keyword', 'dementia caregiver service dhaka')['gap_type']);

        // Keyword Gap CSV Export
        $kwCsv = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->get("/api/v1/websites/{$this->website->id}/competitors/keyword-gap?export=csv");
        $kwCsv->assertOk();
        $this->assertStringContainsString('dementia caregiver service dhaka', $kwCsv->getContent());

        // Save Keyword Gap to SEO Action Plan
        $missingKwId = $kws->firstWhere('keyword', 'dementia caregiver service dhaka')['id'];
        $saveKwRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors/keyword-gap/{$missingKwId}/save-action");
        $saveKwRes->assertStatus(201);
        $this->assertDatabaseHas('ai_recommendations', ['website_id' => $this->website->id]);

        // 4. Content Gap verifies missing topic & sends to Content Studio
        $cgRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->getJson("/api/v1/websites/{$this->website->id}/competitors/content-gap");
        $cgRes->assertOk();
        $gapId = $cgRes->json('data.0.id');
        $this->assertNotNull($gapId);

        $studioRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors/content-gap/{$gapId}/send-to-studio");
        $studioRes->assertStatus(201);
        $this->assertDatabaseHas('content_briefs', ['website_id' => $this->website->id]);

        // 5. Backlink Gap identifies bdmedicalnews.com as gap and sharedhealth.org as shared + CSV export
        $blRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->getJson("/api/v1/websites/{$this->website->id}/competitors/backlink-gap?mode=gap");
        $blRes->assertOk()
            ->assertJsonPath('data.summary.gap_domains_count', 1)
            ->assertJsonPath('data.summary.shared_domains_count', 1)
            ->assertJsonPath('data.items.0.referring_domain', 'bdmedicalnews.com');

        $blCsv = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->get("/api/v1/websites/{$this->website->id}/competitors/backlink-gap?export=csv");
        $blCsv->assertOk();
        $this->assertStringContainsString('bdmedicalnews.com', $blCsv->getContent());

        // 6. SERP Analysis captures snapshot with competitor presence & features
        $serpRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors/serp/analyze", [
                'keyword' => 'dementia caregiver service dhaka',
                'country' => 'BD',
                'device' => 'desktop',
                'monitor' => true,
            ]);
        $serpRes->assertStatus(201)
            ->assertJsonPath('data.snapshot.target_position', 2);
        $this->assertEquals(1, $serpRes->json('data.snapshot.competitor_presence')['homecarebd.com'] ?? null);

        // 7. AI Strategy Report is grounded in real evidence & can save recommendation to Action Plan
        $stratRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors/strategy/generate");
        $stratRes->assertStatus(201);
        $reportId = $stratRes->json('data.id');
        $this->assertNotEmpty($stratRes->json('data.strategic_recommendations'));

        $saveStratRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors/strategy/{$reportId}/save-action", [
                'recommendation_index' => 0,
            ]);
        $saveStratRes->assertStatus(201);
    }

    public function test_unconfigured_providers_never_fabricate_metrics_and_return_honest_status(): void
    {
        config([
            'competitors.providers.dataforseo.login' => '',
            'competitors.providers.dataforseo.password' => '',
            'competitors.providers.semrush.api_key' => '',
            'competitors.providers.ahrefs.api_key' => '',
            'competitors.providers.serpapi.api_key' => '',
        ]);

        // SERP returns honest 422 unconfigured error instead of fake rankings
        $serpRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors/serp/analyze", [
                'keyword' => 'home nursing dhaka',
            ]);
        $serpRes->assertStatus(422)->assertJsonPath('error.code', 'SERP_PROVIDER_UNAVAILABLE');

        // Provider status never exposes keys and accurately marks external providers false
        $provRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->getJson("/api/v1/websites/{$this->website->id}/competitors/providers");
        $provRes->assertOk()->assertJsonPath('data.any_external_connected', false);

        // Provider HTTP 500 failure is handled gracefully without throwing 500 to user
        config([
            'competitors.providers.dataforseo.login' => 'bad_login',
            'competitors.providers.dataforseo.password' => 'bad_pass',
        ]);
        Http::fake([
            'https://api.dataforseo.com/v3/serp/google/organic/live/advanced' => Http::response(['error' => 'Unauthorized'], 401),
        ]);
        $failSerp = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors/serp/analyze", [
                'keyword' => 'home nursing dhaka',
                'force_refresh' => true,
            ]);
        $failSerp->assertStatus(422);
        $this->assertDatabaseHas('competitor_provider_logs', [
            'website_id' => $this->website->id,
            'provider' => 'dataforseo',
            'status' => 'failed',
        ]);
    }

    public function test_tenant_isolation_and_rbac_enforcement(): void
    {
        // Foreign organization cannot access target website competitors
        $foreignRes = $this->actingAs($this->foreignUser)
            ->withHeaders($this->authHeaders($this->foreignUser, $this->foreignOrg))
            ->getJson("/api/v1/websites/{$this->website->id}/competitors/overview");
        $foreignRes->assertStatus(403);

        // Viewer role can read overview, but cannot add competitor or launch analysis
        $viewerRead = $this->actingAs($this->viewer)
            ->withHeaders($this->authHeaders($this->viewer, $this->org))
            ->getJson("/api/v1/websites/{$this->website->id}/competitors/overview");
        $viewerRead->assertOk();

        $viewerWrite = $this->actingAs($this->viewer)
            ->withHeaders($this->authHeaders($this->viewer, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors", [
                'domain' => 'blocked-viewer.com',
            ]);
        $viewerWrite->assertStatus(403);
    }

    public function test_keyword_gap_classification_math_and_monitoring_config(): void
    {
        $this->assertEquals(CompetitorKeyword::GAP_MISSING, KeywordGapService::classifyGap(null, ['a.com' => ['position' => 4]], 500, 50));
        $this->assertEquals(CompetitorKeyword::GAP_UNTAPPED, KeywordGapService::classifyGap(null, ['a.com' => ['position' => 6]], 300, 22));
        $this->assertEquals(CompetitorKeyword::GAP_WEAK, KeywordGapService::classifyGap(18, ['a.com' => ['position' => 4]]));
        $this->assertEquals(CompetitorKeyword::GAP_SHARED, KeywordGapService::classifyGap(6, ['a.com' => ['position' => 4]]));
        $this->assertEquals(CompetitorKeyword::GAP_STRONG, KeywordGapService::classifyGap(2, ['a.com' => ['position' => 5]]));

        // Update monitoring schedule
        $monRes = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->putJson("/api/v1/websites/{$this->website->id}/competitors/monitoring", [
                'frequency' => 'daily',
                'country' => 'BD',
                'min_position_delta' => 5,
            ]);
        $monRes->assertOk()
            ->assertJsonPath('data.frequency', 'daily')
            ->assertJsonPath('data.min_position_delta', 5);
    }

    public function test_zero_api_key_public_web_discovery_extracts_target_niche_and_finds_detailed_competitors(): void
    {
        config([
            'competitors.providers.dataforseo.login' => '',
            'competitors.providers.dataforseo.password' => '',
            'competitors.providers.semrush.api_key' => '',
            'competitors.providers.ahrefs.api_key' => '',
            'competitors.providers.serpapi.api_key' => '',
            'competitors.providers.serper.api_key' => '',
            'ai.gemini.api_key' => '',
        ]);

        $ddgHtml = <<<'HTML'
        <html><body>
          <a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fcaregiveragencybd.com%2Fservices">Caregiver Agency BD | Elderly &amp; Nursing Care in Bangladesh</a>
          <a class="result__snippet">Trusted home caregivers for elderly patients and child nursing care in Dhaka and across Bangladesh.</a>
          <a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fclaracareservices.com%2Fhome-nursing">Clara Care Services | Home Nursing Care Bangladesh</a>
          <a class="result__snippet">24/7 home nursing and elderly caregiver service in Dhaka Bangladesh.</a>
          <a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fwww.facebook.com%2Fcaregiversbd">Caregivers BD - Facebook</a>
          <a class="result__snippet">Facebook community page for caregivers in Bangladesh.</a>
        </body></html>
        HTML;

        Http::fake([
            'https://carematebd.com*' => Http::response(
                '<html><head><title>CareMate BD — Trusted Caregivers for Elderly, Child, &amp; Nursing Care in Bangladesh</title>'
                . '<meta name="description" content="Find background-verified caregivers in Dhaka and across Bangladesh."></head>'
                . '<body><h1>Trusted Home Caregivers in Dhaka</h1></body></html>',
                200
            ),
            'https://html.duckduckgo.com/html/*' => Http::response($ddgHtml, 200),
        ]);

        $res = $this->actingAs($this->seoManager)
            ->withHeaders($this->authHeaders($this->seoManager, $this->org))
            ->postJson("/api/v1/websites/{$this->website->id}/competitors/discover", [
                'country' => 'BD',
                'language' => 'en',
                'force_refresh' => true,
            ]);

        $res->assertStatus(201);
        $candidates = collect($res->json('data.candidates'));

        // Must discover real direct business competitors with rich metadata
        $rival1 = $candidates->firstWhere('domain', 'caregiveragencybd.com');
        $this->assertNotNull($rival1);
        $this->assertEquals('direct', $rival1['category']);
        $this->assertFalse($rival1['is_portal_filtered']);
        $this->assertNotEmpty($rival1['matched_queries']);
        $this->assertNotEmpty($rival1['sample_title']);
        $this->assertNotEmpty($rival1['sample_snippet']);
        $this->assertNotEmpty($rival1['sample_urls']);
        $this->assertNotNull($rival1['avg_position']);

        $rival2 = $candidates->firstWhere('domain', 'claracareservices.com');
        $this->assertNotNull($rival2);
        $this->assertEquals('direct', $rival2['category']);

        // Facebook must be filtered out as portal/indirect
        $fb = $candidates->firstWhere('domain', 'facebook.com');
        $this->assertNotNull($fb);
        $this->assertEquals('indirect', $fb['category']);
        $this->assertTrue($fb['is_portal_filtered']);

        // Direct rivals must be sorted ahead of filtered portals
        $this->assertEquals('direct', $candidates->first()['category']);
    }
}
