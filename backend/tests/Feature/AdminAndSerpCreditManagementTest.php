<?php

namespace Tests\Feature;

use App\Models\GlobalSerpCache;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PlatformApiKey;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAndSerpCreditManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private Organization $adminOrg;
    private User $regularUserA;
    private Organization $orgA;
    private Website $siteA;
    private User $regularUserB;
    private Organization $orgB;
    private Website $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'competitors.providers.dataforseo.login' => '',
            'competitors.providers.dataforseo.password' => '',
            'competitors.providers.serpapi.api_key' => '',
            'competitors.providers.serper.api_key' => '',
        ]);

        // Super Admin (agency_internal owner)
        $this->superAdmin = User::factory()->create([
            'name' => 'Alamin SuperAdmin',
            'email' => 'admin@techboloy.com',
            'is_super_admin' => true,
        ]);
        $this->adminOrg = Organization::create([
            'name' => 'Techboloy Agency',
            'slug' => 'techboloy-internal',
            'billing_plan' => 'agency_internal',
        ]);
        OrganizationMember::create([
            'organization_id' => $this->adminOrg->id,
            'user_id' => $this->superAdmin->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        // Regular User A
        $this->regularUserA = User::factory()->create([
            'name' => 'User A',
            'email' => 'usera@example.com',
            'is_super_admin' => false,
        ]);
        $this->orgA = Organization::create([
            'name' => 'Workspace A',
            'slug' => 'workspace-a',
            'billing_plan' => 'starter',
        ]);
        OrganizationMember::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->regularUserA->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);
        $this->siteA = Website::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Alpha Plumbing',
            'domain' => 'alphaplumbing.sg',
            'target_url' => 'https://alphaplumbing.sg',
            'platform' => Website::PLATFORM_WORDPRESS,
            'target_country' => 'SG',
            'preferred_language' => 'en',
        ]);

        // Regular User B
        $this->regularUserB = User::factory()->create([
            'name' => 'User B',
            'email' => 'userb@example.com',
            'is_super_admin' => false,
        ]);
        $this->orgB = Organization::create([
            'name' => 'Workspace B',
            'slug' => 'workspace-b',
            'billing_plan' => 'agency_pro',
        ]);
        OrganizationMember::create([
            'organization_id' => $this->orgB->id,
            'user_id' => $this->regularUserB->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);
        $this->siteB = Website::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Beta Plumbing',
            'domain' => 'betaplumbing.sg',
            'target_url' => 'https://betaplumbing.sg',
            'platform' => Website::PLATFORM_CUSTOM,
            'target_country' => 'SG',
            'preferred_language' => 'en',
        ]);
    }

    public function test_non_super_admin_is_blocked_from_admin_panel_endpoints(): void
    {
        $this->actingAs($this->regularUserA)
            ->getJson('/api/v1/admin/overview')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'SUPER_ADMIN_REQUIRED');

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.kpis.total_users', 3)
            ->assertJsonPath('data.kpis.total_organizations', 3)
            ->assertJsonPath('data.kpis.total_websites', 2);
    }

    public function test_multi_key_pool_auto_failover_and_smart_cross_user_cache(): void
    {
        // Add 2 Serper keys to the Multi-Key Pool (Priority #1 exhausted, Priority #2 active)
        $key1 = PlatformApiKey::create([
            'provider' => 'serper',
            'label' => 'Primary Key #1',
            'encrypted_key' => PlatformApiKey::encryptKey('serper_exhausted_key_1'),
            'key_hint' => '••••ey_1',
            'priority' => 1,
            'status' => PlatformApiKey::STATUS_ACTIVE,
            'monthly_limit' => 2500,
        ]);

        $key2 = PlatformApiKey::create([
            'provider' => 'serper',
            'label' => 'Backup Key #2',
            'encrypted_key' => PlatformApiKey::encryptKey('serper_healthy_key_2'),
            'key_hint' => '••••ey_2',
            'priority' => 2,
            'status' => PlatformApiKey::STATUS_ACTIVE,
            'monthly_limit' => 2500,
        ]);

        $callCount = 0;
        Http::fake([
            'https://google.serper.dev/search' => function ($req) use (&$callCount) {
                $callCount++;
                $apiKey = $req->header('X-API-KEY')[0] ?? '';

                // Key #1 fails with HTTP 400 Not enough credits
                if ($apiKey === 'serper_exhausted_key_1') {
                    return Http::response(['message' => 'Not enough credits'], 400);
                }

                // Key #2 succeeds seamlessly!
                $this->assertEquals('serper_healthy_key_2', $apiKey);
                return Http::response([
                    'organic' => [
                        ['position' => 1, 'link' => 'https://alphaplumbing.sg/', 'title' => 'Alpha Plumbing SG', 'snippet' => 'Fast plumbing'],
                        ['position' => 2, 'link' => 'https://betaplumbing.sg/services', 'title' => 'Beta Plumbing SG', 'snippet' => '24/7 plumber'],
                    ],
                ], 200);
            },
        ]);

        // 1. User A runs default Top 30 check — Key #1 fails over automatically to Key #2!
        $resA = $this->actingAs($this->regularUserA)
            ->withHeaders(['X-Organization-Id' => (string) $this->orgA->id])
            ->postJson("/api/v1/websites/{$this->siteA->id}/rank-checker/check", [
                'keyword' => 'plumber singapore',
                'country' => 'SG',
                'device' => 'desktop',
            ]);

        $resA->assertStatus(201)
            ->assertJsonPath('data.depth_checked', 30)
            ->assertJsonPath('data.target_ranking.position', 1)
            ->assertJsonPath('data.from_global_cache', false)
            ->assertJsonPath('data.credits_used', 1);

        // Verify Key #1 was automatically marked exhausted & Key #2 recorded usage
        $this->assertEquals(PlatformApiKey::STATUS_EXHAUSTED, $key1->fresh()->status);
        $this->assertEquals(PlatformApiKey::STATUS_ACTIVE, $key2->fresh()->status);
        $this->assertEquals(1, $key2->fresh()->credits_used);
        $this->assertEquals(2, $callCount); // 1 failed call on Key #1 + 1 successful call on Key #2

        // 2. User B (completely different workspace!) checks the SAME keyword -> Smart Cross-User Cache HIT (0 credits!)
        $resB = $this->actingAs($this->regularUserB)
            ->withHeaders(['X-Organization-Id' => (string) $this->orgB->id])
            ->postJson("/api/v1/websites/{$this->siteB->id}/rank-checker/check", [
                'keyword' => 'plumber singapore',
                'country' => 'SG',
                'device' => 'desktop',
                'depth' => 30,
            ]);

        $resB->assertStatus(201)
            ->assertJsonPath('data.from_global_cache', true)
            ->assertJsonPath('data.credits_used', 0)
            // User B gets THEIR OWN domain rank (#2), never User A's!
            ->assertJsonPath('data.target_ranking.position', 2);

        // Zero additional HTTP requests were sent to Serper!
        $this->assertEquals(2, $callCount);
        $this->assertEquals(1, GlobalSerpCache::first()->hit_count);
    }

    public function test_per_workspace_quota_enforcement_and_admin_management_controls(): void
    {
        PlatformApiKey::create([
            'provider' => 'serper',
            'label' => 'Active Pool Key',
            'encrypted_key' => PlatformApiKey::encryptKey('serper_active_quota_key'),
            'key_hint' => '••••_key',
            'priority' => 1,
            'status' => PlatformApiKey::STATUS_ACTIVE,
            'monthly_limit' => 2500,
        ]);

        // 1. Super Admin sets tight custom quota override (daily: 1, monthly: 1) on Workspace A
        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/admin/organizations/{$this->orgA->id}", [
                'serp_quota_override' => ['daily' => 1, 'monthly' => 1],
            ])
            ->assertOk();

        Http::fake([
            'https://google.serper.dev/search' => Http::response([
                'organic' => [
                    ['position' => 1, 'link' => 'https://alphaplumbing.sg/', 'title' => 'Alpha Plumbing', 'snippet' => 'Plumbing'],
                ],
            ], 200),
        ]);

        // 2. First live search consumes 1 credit and succeeds
        $this->actingAs($this->regularUserA)
            ->withHeaders(['X-Organization-Id' => (string) $this->orgA->id])
            ->postJson("/api/v1/websites/{$this->siteA->id}/rank-checker/check", [
                'keyword' => 'drain cleaning sg',
                'country' => 'SG',
                'depth' => 30,
            ])
            ->assertStatus(201);

        // 3. Second uncached live search hits quota -> 429 SERP_QUOTA_EXCEEDED
        $this->actingAs($this->regularUserA)
            ->withHeaders(['X-Organization-Id' => (string) $this->orgA->id])
            ->postJson("/api/v1/websites/{$this->siteA->id}/rank-checker/check", [
                'keyword' => 'pipe leak repair sg',
                'country' => 'SG',
                'depth' => 30,
            ])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'SERP_QUOTA_EXCEEDED');

        // 4. Super Admin suspends User B -> User B cannot log in anymore
        $this->actingAs($this->superAdmin)
            ->patchJson("/api/v1/admin/users/{$this->regularUserB->id}", [
                'status' => 'suspended',
            ])
            ->assertOk();

        $this->assertEquals('suspended', $this->regularUserB->fresh()->status);

        // 5. Super Admin updates global settings & flushes cache
        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/admin/settings', [
                'serp_cache' => ['enabled' => true, 'ttl_hours' => 24, 'default_depth' => 30],
                'registration' => ['allow_public_registration' => true, 'default_signup_plan' => 'starter'],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.serp_cache.ttl_hours', 24)
            ->assertJsonPath('data.settings.registration.default_signup_plan', 'starter');

        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/admin/cache/flush')
            ->assertOk();

        $this->assertEquals(0, GlobalSerpCache::count());
    }

    public function test_admin_can_connect_dataforseo_and_serper_powers_live_keyword_gap(): void
    {
        // 1. Live Serper.dev fallback populates competitor rankings in Keyword Gap even without DataForSEO
        PlatformApiKey::create([
            'provider' => 'serper',
            'label' => 'Pool Key for Keyword Gap',
            'encrypted_key' => PlatformApiKey::encryptKey('serper_kw_gap_key_1'),
            'key_hint' => '••••y_1',
            'priority' => 1,
            'status' => PlatformApiKey::STATUS_ACTIVE,
            'monthly_limit' => 2500,
        ]);

        \App\Models\CompetitorDomain::create([
            'organization_id' => $this->orgA->id,
            'website_id' => $this->siteA->id,
            'domain' => 'rivalplumber.sg',
            'category' => 'direct',
            'tier' => 'primary',
            'country' => 'SG',
            'language' => 'en',
            'is_active' => true,
        ]);

        \App\Models\SearchPerformanceRecord::create([
            'organization_id' => $this->orgA->id,
            'website_id' => $this->siteA->id,
            'property_id' => 'https://alphaplumbing.sg/',
            'date' => now()->subDays(2)->toDateString(),
            'page' => 'https://alphaplumbing.sg/emergency',
            'query' => 'emergency plumber singapore',
            'country' => 'SGP',
            'device' => 'MOBILE',
            'clicks' => 5,
            'impressions' => 300,
            'ctr' => 0.0167,
            'position' => 12.0,
        ]);

        Http::fake([
            'https://google.serper.dev/search' => Http::response([
                'organic' => [
                    ['position' => 2, 'link' => 'https://rivalplumber.sg/24hr', 'title' => 'Rival Plumber SG', 'snippet' => '24hr'],
                    ['position' => 9, 'link' => 'https://alphaplumbing.sg/emergency', 'title' => 'Alpha Plumbing SG', 'snippet' => 'Fast'],
                ],
                'peopleAlsoAsk' => [['question' => 'Cost of plumber?']],
            ], 200),
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    ['result' => [['money' => ['balance' => 14.25, 'total' => 50.0]]]],
                ],
            ], 200),
        ]);

        $syncRes = app(\App\Domain\Competitors\Services\KeywordGapService::class)
            ->syncKeywordGap($this->siteA, null, 'SG', 'en');

        $this->assertEquals(1, $syncRes['keywords_synced']);

        $kwGapRes = $this->actingAs($this->regularUserA)
            ->withHeaders(['X-Organization-Id' => (string) $this->orgA->id])
            ->getJson("/api/v1/websites/{$this->siteA->id}/competitors/keyword-gap");

        $kwGapRes->assertOk()
            ->assertJsonPath('meta.serp_provider_connected', true)
            ->assertJsonPath('data.0.keyword', 'emergency plumber singapore')
            ->assertJsonPath('data.0.data_source', 'gsc+serper_live');

        $compPositions = $kwGapRes->json('data.0.competitor_positions');
        $this->assertEquals(1, $compPositions['rivalplumber.sg']['position'] ?? null);

        // 2. Super Admin connects DataForSEO v3 credentials (live-verified via /appendix/user_data)
        $dfsSave = $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/admin/dataforseo', [
                'login' => 'seo@techboloy.com',
                'password' => 'dfs_secret_password_123',
            ]);

        $dfsSave->assertStatus(201)
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.balance', 14.25);

        $this->assertTrue((new \App\Domain\Competitors\Providers\DataForSeoProvider())->isConfigured());

        // 3. Super Admin disconnects DataForSEO cleanly
        $this->actingAs($this->superAdmin)
            ->deleteJson('/api/v1/admin/dataforseo')
            ->assertOk()
            ->assertJsonPath('data.configured', false);

        $this->assertFalse((new \App\Domain\Competitors\Providers\DataForSeoProvider())->isConfigured());
    }
}
