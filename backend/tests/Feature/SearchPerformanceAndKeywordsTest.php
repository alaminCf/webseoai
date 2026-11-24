<?php

namespace Tests\Feature;

use App\Domain\SearchConsole\GoogleSearchConsoleService;
use App\Models\AuditIssue;
use App\Models\AuditRun;
use App\Models\CrawlJob;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\SearchOpportunity;
use App\Models\SearchPerformanceRecord;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchPerformanceAndKeywordsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Organization $org;
    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'seo-strategist@techboloy.com']);
        $this->org = Organization::create([
            'name' => 'Techboloy SEO Hub',
            'slug' => 'techboloy-seo-hub',
            'owner_id' => $this->user->id,
        ]);

        OrganizationMember::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        $this->website = Website::create([
            'organization_id' => $this->org->id,
            'name' => 'Agency Main Site',
            'domain' => 'techboloy.com',
            'target_url' => 'https://techboloy.com',
            'platform' => Website::PLATFORM_CUSTOM,
            'timezone' => 'UTC',
            'preferred_language' => 'en',
            'verification_token' => 'wb_test_token_123',
            'verification_status' => Website::VERIFICATION_VERIFIED,
        ]);
    }

    public function test_google_search_console_oauth_flow_and_sandbox_connection(): void
    {
        // 1. Get connect authorization URL
        $response = $this->actingAs($this->user)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->getJson("/api/v1/websites/{$this->website->id}/search-console/connect");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['authorization_url', 'scopes']]);

        $authUrl = $response->json('data.authorization_url');
        $this->assertNotEmpty($authUrl);

        // 2. Handle Callback (sandbox code)
        $statePayload = [
            'website_id' => $this->website->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'timestamp' => now()->timestamp,
        ];
        $state = base64_encode(json_encode($statePayload));

        $callbackRes = $this->getJson("/api/v1/integrations/google-search-console/callback?code=sandbox_gsc_auth_code&state={$state}");

        $callbackRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.is_sandbox', true);

        // 3. Verify Database Integration record
        $integration = WebsiteIntegration::where('website_id', $this->website->id)
            ->where('provider', WebsiteIntegration::PROVIDER_GSC)
            ->first();

        $this->assertNotNull($integration);
        $this->assertEquals(WebsiteIntegration::STATUS_CONNECTED, $integration->status);
        $this->assertNotEmpty($integration->encrypted_credentials);

        // Verify credentials decrypt safely
        $decrypted = $integration->getDecryptedCredentials();
        $this->assertNotNull($decrypted);
        $this->assertArrayHasKey('access_token', $decrypted);
    }

    public function test_live_google_oauth_connect_and_browser_redirect_callback(): void
    {
        config([
            'search_console.client_id' => 'test-client-id.apps.googleusercontent.com',
            'search_console.client_secret' => 'test-client-secret',
            'search_console.redirect_uri' => 'https://webseoai.vercel.app/api/v1/integrations/google-search-console/callback',
            'search_console.frontend_url' => 'https://webseoai.vercel.app',
        ]);

        // 1. Connect endpoint returns real Google OAuth URL with live mode
        $connectRes = $this->actingAs($this->user)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->getJson("/api/v1/websites/{$this->website->id}/search-console/connect");

        $connectRes->assertOk()
            ->assertJsonPath('data.oauth_mode', 'live');

        $authUrl = $connectRes->json('data.authorization_url');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $authUrl);
        $this->assertStringContainsString('test-client-id.apps.googleusercontent.com', $authUrl);

        // 2. Mock Google token exchange and Search Console sites API
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'live_google_access_token_xyz',
                'refresh_token' => 'live_google_refresh_token_abc',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            ], 200),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://www.techboloy.com/', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
        ]);

        $state = base64_encode(json_encode([
            'website_id' => $this->website->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'timestamp' => now()->timestamp,
        ]));

        // Browser redirect (Accept: text/html) should redirect back to dashboard performance tab
        $redirectRes = $this->get('/api/v1/integrations/google-search-console/callback?code=real_google_code_123&state=' . urlencode($state));
        $redirectRes->assertRedirect("https://webseoai.vercel.app/?tab=performance&gsc=connected&website_id={$this->website->id}");

        $integration = WebsiteIntegration::where('website_id', $this->website->id)
            ->where('provider', WebsiteIntegration::PROVIDER_GSC)
            ->first();

        $this->assertNotNull($integration);
        $this->assertEquals(WebsiteIntegration::STATUS_CONNECTED, $integration->status);
        $this->assertFalse((bool) ($integration->metadata['is_sandbox'] ?? true));
        $this->assertEquals('https://www.techboloy.com/', $integration->metadata['selected_property']);

        // 3. Cancellation (error=access_denied) redirects cleanly with gsc=error
        $cancelRes = $this->get('/api/v1/integrations/google-search-console/callback?error=access_denied&state=' . urlencode($state));
        $cancelRes->assertStatus(302);
        $this->assertStringContainsString('gsc=error', $cancelRes->headers->get('Location'));
        $this->assertStringContainsString("website_id={$this->website->id}", $cancelRes->headers->get('Location'));
    }

    public function test_gsc_callback_rejects_token_missing_webmasters_scope_from_granular_consent(): void
    {
        config([
            'search_console.client_id' => 'test-client-id.apps.googleusercontent.com',
            'search_console.client_secret' => 'test-client-secret',
            'search_console.redirect_uri' => 'https://webseoai.vercel.app/api/v1/integrations/google-search-console/callback',
            'search_console.frontend_url' => 'https://webseoai.vercel.app',
        ]);

        // Simulate user clicking Continue on Google's granular consent screen WITHOUT checking Search Console scope
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'scope_missing_token_123',
                'refresh_token' => 'refresh_123',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile',
            ], 200),
        ]);

        $state = base64_encode(json_encode([
            'website_id' => $this->website->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'timestamp' => now()->timestamp,
        ]));

        $res = $this->get('/api/v1/integrations/google-search-console/callback?code=no_scope_code&state=' . urlencode($state));
        $res->assertStatus(302);
        $location = (string) $res->headers->get('Location');
        $this->assertStringContainsString('gsc=error', $location);
        $this->assertStringContainsString('View+Search+Console+data', $location);

        // Must NOT save a broken "connected" integration record
        $this->assertDatabaseMissing('website_integrations', [
            'website_id' => $this->website->id,
            'provider' => WebsiteIntegration::PROVIDER_GSC,
            'status' => WebsiteIntegration::STATUS_CONNECTED,
        ]);
    }

    public function test_search_analytics_synchronization_and_performance_querying(): void
    {
        // Connect sandbox
        $service = app(GoogleSearchConsoleService::class);
        $service->connectSandbox($this->website);

        // Trigger Sync
        $syncRes = $this->actingAs($this->user)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->postJson("/api/v1/websites/{$this->website->id}/search-console/sync");

        $syncRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'success');

        $this->assertGreaterThan(0, $syncRes->json('data.rows_synced'));

        // Query Performance API (Default 28d)
        $perfRes = $this->actingAs($this->user)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->getJson("/api/v1/websites/{$this->website->id}/search-console/performance?range=28d");

        $perfRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'period' => ['start_date', 'end_date', 'previous_start_date', 'previous_end_date'],
                    'summary' => [
                        'clicks',
                        'impressions',
                        'ctr',
                        'position',
                        'comparison' => ['diff_clicks', 'diff_impressions', 'diff_ctr', 'diff_position'],
                    ],
                    'timeseries',
                    'queries',
                    'pages',
                    'devices',
                    'countries',
                ],
            ]);

        $this->assertGreaterThan(0, $perfRes->json('data.summary.clicks'));
        $this->assertGreaterThan(0, $perfRes->json('data.summary.impressions'));
        $this->assertNotEmpty($perfRes->json('data.queries'));
        $this->assertNotEmpty($perfRes->json('data.pages'));
    }

    public function test_live_gsc_performance_matches_exact_untruncated_totals_and_date_window(): void
    {
        // Create a live (non-sandbox) connected GSC integration
        WebsiteIntegration::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'provider' => WebsiteIntegration::PROVIDER_GSC,
            'status' => WebsiteIntegration::STATUS_CONNECTED,
            'encrypted_credentials' => [
                'access_token' => 'live_gsc_token_exact_test',
                'refresh_token' => 'live_gsc_refresh_exact_test',
                'expires_at' => now()->addHour()->timestamp,
                'token_type' => 'Bearer',
                'is_sandbox' => false,
            ],
            'metadata' => [
                'account_connected' => true,
                'is_sandbox' => false,
                'selected_property' => 'https://handymansuperservices.com/',
                'properties' => [
                    ['siteUrl' => 'https://handymansuperservices.com/', 'permissionLevel' => 'siteFullUser'],
                ],
            ],
        ]);

        // Mock Google Search Console searchAnalytics/query API responses matching real GSC UI:
        // Total site clicks = 26, impressions = 7870, position = 41.2 (even though visible query rows only have 6 clicks!)
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => function (\Illuminate\Http\Client\Request $req) {
                $body = $req->data();
                $dims = $body['dimensions'] ?? [];
                $startDate = $body['startDate'] ?? '';

                // Latest-date anchor detection (14d date query)
                if ($dims === ['date'] && ($body['rowLimit'] ?? 0) === 20) {
                    return Http::response([
                        'rows' => [
                            ['keys' => ['2026-11-21'], 'clicks' => 1, 'impressions' => 250, 'ctr' => 0.004, 'position' => 40.5],
                            ['keys' => ['2026-11-22'], 'clicks' => 1, 'impressions' => 280, 'ctr' => 0.0036, 'position' => 41.0],
                        ],
                    ], 200);
                }

                // Exact aggregate summary (dimensions: [])
                if (empty($dims)) {
                    if ($startDate === '2026-10-26') {
                        return Http::response([
                            'rows' => [
                                ['clicks' => 26, 'impressions' => 7870, 'ctr' => 0.0033036, 'position' => 41.2],
                            ],
                        ], 200);
                    }
                    // Previous 28d period (2026-09-28 .. 2026-10-25)
                    return Http::response([
                        'rows' => [
                            ['clicks' => 18, 'impressions' => 6100, 'ctr' => 0.00295, 'position' => 43.8],
                        ],
                    ], 200);
                }

                // Exact daily timeseries (dimensions: ['date'])
                if ($dims === ['date']) {
                    return Http::response([
                        'rows' => [
                            ['keys' => ['2026-10-26'], 'clicks' => 2, 'impressions' => 280, 'ctr' => 0.0071, 'position' => 40.1],
                            ['keys' => ['2026-11-02'], 'clicks' => 3, 'impressions' => 310, 'ctr' => 0.0097, 'position' => 39.4],
                            ['keys' => ['2026-11-22'], 'clicks' => 1, 'impressions' => 280, 'ctr' => 0.0036, 'position' => 41.0],
                        ],
                    ], 200);
                }

                // Query table (only 6 visible clicks due to 20 anonymized long-tail queries!)
                if ($dims === ['query']) {
                    return Http::response([
                        'rows' => [
                            ['keys' => ['repair hot water system'], 'clicks' => 4, 'impressions' => 420, 'ctr' => 0.0095, 'position' => 9.1],
                            ['keys' => ['handyman singapore'], 'clicks' => 2, 'impressions' => 1850, 'ctr' => 0.0011, 'position' => 44.3],
                        ],
                    ], 200);
                }

                // Pages table
                if ($dims === ['page']) {
                    return Http::response([
                        'rows' => [
                            ['keys' => ['https://handymansuperservices.com/'], 'clicks' => 26, 'impressions' => 7870, 'ctr' => 0.0033, 'position' => 41.2],
                        ],
                    ], 200);
                }

                // Devices table
                if ($dims === ['device']) {
                    return Http::response([
                        'rows' => [
                            ['keys' => ['MOBILE'], 'clicks' => 19, 'impressions' => 5400, 'ctr' => 0.0035, 'position' => 40.2],
                            ['keys' => ['DESKTOP'], 'clicks' => 7, 'impressions' => 2470, 'ctr' => 0.0028, 'position' => 43.4],
                        ],
                    ], 200);
                }

                // Countries table
                if ($dims === ['country']) {
                    return Http::response([
                        'rows' => [
                            ['keys' => ['sgp'], 'clicks' => 24, 'impressions' => 7200, 'ctr' => 0.0033, 'position' => 40.8],
                            ['keys' => ['usa'], 'clicks' => 2, 'impressions' => 670, 'ctr' => 0.0030, 'position' => 45.5],
                        ],
                    ], 200);
                }

                return Http::response(['rows' => []], 200);
            },
        ]);

        $perfRes = $this->actingAs($this->user)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->getJson("/api/v1/websites/{$this->website->id}/search-console/performance?range=28d");

        $perfRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.data_source', 'google_search_console_live')
            ->assertJsonPath('data.period.start_date', '2026-10-26')
            ->assertJsonPath('data.period.end_date', '2026-11-22')
            ->assertJsonPath('data.summary.clicks', 26)
            ->assertJsonPath('data.summary.impressions', 7870)
            ->assertJsonPath('data.summary.ctr', 0.33)
            ->assertJsonPath('data.summary.position', 41.2)
            ->assertJsonPath('data.summary.anonymized_query_clicks', 20)
            ->assertJsonPath('data.summary.comparison.diff_clicks', 8)
            ->assertJsonPath('data.summary.comparison.diff_impressions', 1770)
            ->assertJsonPath('data.countries.0.country', 'SGP')
            ->assertJsonPath('data.countries.0.clicks', 24);

        // Continuous 28-day timeseries (Oct 26 .. Nov 22 = 28 points)
        $this->assertCount(28, $perfRes->json('data.timeseries'));
    }

    public function test_db_fallback_uses_impression_weighted_average_position(): void
    {
        $today = now()->subDays(2)->toDateString();

        // Row 1: 990 impressions at position 10.0; Row 2: 10 impressions at position 90.0
        // Unweighted avg would be 50.0 (WRONG); Impression-weighted avg is (990*10 + 10*90)/1000 = 10.8 (EXACT)
        SearchPerformanceRecord::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'property_id' => 'https://techboloy.com/',
            'date' => $today,
            'page' => 'https://techboloy.com/a',
            'query' => 'main keyword',
            'country' => 'SGP',
            'device' => 'MOBILE',
            'clicks' => 25,
            'impressions' => 990,
            'ctr' => 0.0253,
            'position' => 10.0,
        ]);

        SearchPerformanceRecord::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'property_id' => 'https://techboloy.com/',
            'date' => $today,
            'page' => 'https://techboloy.com/b',
            'query' => 'rare keyword',
            'country' => 'SGP',
            'device' => 'DESKTOP',
            'clicks' => 0,
            'impressions' => 10,
            'ctr' => 0.0,
            'position' => 90.0,
        ]);

        $perfRes = $this->actingAs($this->user)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->getJson("/api/v1/websites/{$this->website->id}/search-console/performance?range=28d");

        $perfRes->assertOk()
            ->assertJsonPath('data.summary.clicks', 25)
            ->assertJsonPath('data.summary.impressions', 1000)
            ->assertJsonPath('data.summary.position', 10.8);
    }

    public function test_url_inspection_returns_disclaimer_and_index_state(): void
    {
        $service = app(GoogleSearchConsoleService::class);
        $service->connectSandbox($this->website);

        $inspectRes = $this->actingAs($this->user)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->postJson("/api/v1/websites/{$this->website->id}/search-console/inspect", [
                'url' => 'https://techboloy.com/ecommerce-seo-audit',
            ]);

        $inspectRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.inspection_result.verdict', 'PASS')
            ->assertJsonPath('data.inspection_result.indexing_state', 'INDEXING_ALLOWED');

        // Verify required disclaimer is explicitly present
        $disclaimer = $inspectRes->json('data.disclaimer');
        $this->assertStringContainsString('does not guarantee future indexing or search rankings', $disclaimer);
    }

    public function test_opportunity_detector_identifies_actionable_seo_priorities(): void
    {
        // Connect and sync data
        $service = app(GoogleSearchConsoleService::class);
        $service->connectSandbox($this->website);
        $service->syncSearchAnalytics($this->website);

        // Also add an audit issue on one of the top pages to test coverage gap rule
        $crawlJob = CrawlJob::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'status' => CrawlJob::STATUS_COMPLETED,
            'max_pages' => 20,
            'max_depth' => 2,
            'concurrency' => 2,
            'pages_discovered' => 10,
            'pages_crawled' => 10,
        ]);

        $auditRun = AuditRun::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->website->id,
            'status' => 'completed',
            'overall_score' => 74,
            'crawl_job_id' => $crawlJob->id,
        ]);

        AuditIssue::create([
            'website_id' => $this->website->id,
            'audit_run_id' => $auditRun->id,
            'issue_type' => 'missing_meta_description',
            'severity' => 'critical',
            'category' => 'content',
            'affected_url' => 'https://techboloy.com/blog/seo-automation-guide',
            'explanation' => 'Target page has no meta description snippet.',
            'recommended_fix' => 'Add descriptive meta tag.',
            'is_fully_determined' => true,
        ]);

        // Trigger opportunity detection
        $detectRes = $this->actingAs($this->user)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->postJson("/api/v1/websites/{$this->website->id}/opportunities/detect");

        $detectRes->assertOk()
            ->assertJsonPath('success', true);

        // List opportunities
        $listRes = $this->actingAs($this->user)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->getJson("/api/v1/websites/{$this->website->id}/opportunities");

        $listRes->assertOk()
            ->assertJsonPath('success', true);

        $opportunities = $listRes->json('data');
        $this->assertNotEmpty($opportunities);

        $firstOpp = $opportunities[0];
        $this->assertArrayHasKey('type', $firstOpp);
        $this->assertArrayHasKey('impact_score', $firstOpp);
        $this->assertArrayHasKey('headline', $firstOpp);
        $this->assertArrayHasKey('reasoning', $firstOpp);
        $this->assertArrayHasKey('recommended_action', $firstOpp);

        // Test status update
        $updateStatusRes = $this->actingAs($this->user)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->patchJson("/api/v1/websites/{$this->website->id}/opportunities/{$firstOpp['id']}/status", [
                'status' => 'acknowledged',
            ]);

        $updateStatusRes->assertOk()
            ->assertJsonPath('data.status', 'acknowledged');
    }

    public function test_keyword_research_provider_stores_provenance_without_invented_metrics(): void
    {
        $searchRes = $this->actingAs($this->user)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->getJson('/api/v1/keywords/search?query=technical+seo&country=US&language=en');

        $searchRes->assertOk()
            ->assertJsonPath('success', true);

        $keywords = $searchRes->json('data');
        $this->assertNotEmpty($keywords);

        $first = $keywords[0];
        $this->assertEquals('sandbox', $first['provider']);
        $this->assertArrayHasKey('provenance', $first);
        $this->assertEquals('US', $first['provenance']['country']);
        $this->assertEquals('en', $first['provenance']['language']);
        $this->assertFalse($first['provenance']['is_stale']);

        // Check clusters endpoint
        $clustersRes = $this->actingAs($this->user)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->getJson('/api/v1/keywords/clusters?keyword=technical+seo');

        $clustersRes->assertOk()
            ->assertJsonPath('success', true);
        $this->assertNotEmpty($clustersRes->json('data'));

        // Check keyword history
        $historyRes = $this->actingAs($this->user)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->getJson('/api/v1/keywords/history');

        $historyRes->assertOk()
            ->assertJsonPath('success', true);
        $this->assertGreaterThan(0, count($historyRes->json('data')));
    }

    public function test_tenant_isolation_prevents_foreign_organization_search_console_access(): void
    {
        // Create foreign organization and foreign user
        $foreignUser = User::factory()->create();
        $foreignOrg = Organization::create([
            'name' => 'Foreign Corp',
            'slug' => 'foreign-corp',
            'owner_id' => $foreignUser->id,
        ]);

        OrganizationMember::create([
            'organization_id' => $foreignOrg->id,
            'user_id' => $foreignUser->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        // Foreign user tries to access primary website Search Console performance
        $response = $this->actingAs($foreignUser)
            ->withHeader('X-Organization-Id', $foreignOrg->id)
            ->getJson("/api/v1/websites/{$this->website->id}/search-console/performance");

        $response->assertStatus(403);

        // Foreign user tries to access primary website opportunities
        $oppResponse = $this->actingAs($foreignUser)
            ->withHeader('X-Organization-Id', $foreignOrg->id)
            ->getJson("/api/v1/websites/{$this->website->id}/opportunities");

        $oppResponse->assertStatus(403);
    }
}
