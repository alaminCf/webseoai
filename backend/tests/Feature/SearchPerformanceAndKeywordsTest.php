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
