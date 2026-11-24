<?php

namespace Tests\Feature;

use App\Models\CompetitorDomain;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\SearchPerformanceRecord;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RankCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_geo_rank_checker_finds_exact_position_google_page_local_pack_and_compares_gsc(): void
    {
        config([
            'competitors.providers.dataforseo.login' => '',
            'competitors.providers.dataforseo.password' => '',
            'competitors.providers.serpapi.api_key' => '',
            'competitors.providers.serper.api_key' => 'test_serper_rank_key',
        ]);

        $user = User::factory()->create();
        $foreignUser = User::factory()->create();

        $org = Organization::create(['name' => 'Techboloy', 'slug' => 'techboloy-rank', 'owner_id' => $user->id]);
        OrganizationMember::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => OrganizationMember::ROLE_SEO_MANAGER]);

        $foreignOrg = Organization::create(['name' => 'Other Org', 'slug' => 'other-rank', 'owner_id' => $foreignUser->id]);
        OrganizationMember::create(['organization_id' => $foreignOrg->id, 'user_id' => $foreignUser->id, 'role' => OrganizationMember::ROLE_OWNER]);

        $website = Website::create([
            'organization_id' => $org->id,
            'name' => 'Handyman Super Services',
            'domain' => 'handymansuperservices.com',
            'target_url' => 'https://handymansuperservices.com',
            'platform' => Website::PLATFORM_WORDPRESS,
            'target_country' => 'SG',
            'preferred_language' => 'en',
        ]);

        CompetitorDomain::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'domain' => 'mrhandyman.sg',
            'category' => 'direct',
            'tier' => 'primary',
            'country' => 'SG',
            'language' => 'en',
        ]);

        // Seed verified GSC record: "repair hot water system" avg position 9.0
        SearchPerformanceRecord::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'property_id' => 'sc-domain:handymansuperservices.com',
            'date' => now()->subDays(2)->toDateString(),
            'query' => 'repair hot water system',
            'page' => 'https://handymansuperservices.com/water-heater-repair/',
            'country' => 'SGP',
            'device' => 'MOBILE',
            'clicks' => 18,
            'impressions' => 420,
            'ctr' => 4.29,
            'position' => 9.0,
        ]);

        // Mock live Google Singapore SERP returning 15 results (Page 1 & Page 2) + Local Pack + PAA
        $organic = [];
        for ($i = 1; $i <= 15; $i++) {
            if ($i === 3) {
                $organic[] = [
                    'position' => 3,
                    'link' => 'https://mrhandyman.sg/water-heater',
                    'title' => 'Mr Handyman SG Water Heater Repair',
                    'snippet' => 'Fast heater repair in Singapore.',
                ];
            } elseif ($i === 7) {
                $organic[] = [
                    'position' => 7,
                    'link' => 'https://handymansuperservices.com/water-heater-repair/',
                    'title' => '24Hr Water Heater Repair Singapore | Handyman Super Services',
                    'snippet' => 'Trusted instant & storage hot water system repair in Singapore.',
                ];
            } elseif ($i === 14) {
                $organic[] = [
                    'position' => 14,
                    'link' => 'https://handymansuperservices.com/plumbing-services/',
                    'title' => 'Plumbing & Hot Water System Services SG',
                    'snippet' => 'Complete plumbing & water heater services.',
                ];
            } else {
                $organic[] = [
                    'position' => $i,
                    'link' => "https://sgplumber{$i}.com.sg/hot-water",
                    'title' => "SG Plumber #{$i} Hot Water Repair",
                    'snippet' => 'Singapore plumbing services.',
                ];
            }
        }

        Http::fake([
            'https://google.serper.dev/search' => function ($request) use ($organic) {
                $payload = $request->data();
                $this->assertEquals('sg', $payload['gl']);
                $this->assertEquals('Singapore', $payload['location']);
                $this->assertEquals(10, $payload['num']);
                $this->assertEquals(1, $payload['page']);

                return Http::response([
                    'organic' => $organic,
                    'places' => [
                        [
                            'position' => 1,
                            'title' => 'Handyman Super Services Singapore',
                            'address' => '10 Anson Rd, Singapore',
                            'rating' => 4.9,
                            'ratingCount' => 128,
                            'website' => 'https://handymansuperservices.com',
                        ],
                    ],
                    'peopleAlsoAsk' => [
                        ['question' => 'How much does water heater repair cost in Singapore?', 'snippet' => 'Typically $60 to $150 SGD.'],
                    ],
                    'relatedSearches' => [
                        ['query' => 'water heater repair singapore 24 hours'],
                    ],
                ], 200);
            },
        ]);

        // 1. Context endpoint returns GSC quick keywords
        $ctxRes = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->getJson("/api/v1/websites/{$website->id}/rank-checker/context");
        $ctxRes->assertOk()
            ->assertJsonPath('data.gsc_top_keywords.0.query', 'repair hot water system')
            ->assertJsonPath('data.gsc_top_keywords.0.avg_position', 9);

        // 2. Live Rank Check in Singapore verifies exact Page 1 Position #7 + Page 2 #14 + Local Pack #1 + GSC Avg #9 comparison!
        $checkRes = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->postJson("/api/v1/websites/{$website->id}/rank-checker/check", [
                'keyword' => 'repair hot water system',
                'country' => 'SG',
                'location' => 'Singapore',
                'language' => 'en',
                'device' => 'mobile',
                'depth' => 50,
            ]);

        $checkRes->assertStatus(201)
            ->assertJsonPath('data.target_ranking.found', true)
            ->assertJsonPath('data.target_ranking.position', 7)
            ->assertJsonPath('data.target_ranking.google_page', 1)
            ->assertJsonPath('data.target_ranking.rank_on_page', 7)
            ->assertJsonPath('data.target_ranking.in_local_pack', true)
            ->assertJsonPath('data.target_ranking.local_pack_position', 1)
            ->assertJsonPath('data.gsc_comparison.has_gsc_data', true)
            ->assertJsonPath('data.gsc_comparison.gsc_avg_position', 9)
            ->assertJsonPath('data.gsc_comparison.delta_vs_gsc', 2);

        $this->assertCount(2, $checkRes->json('data.target_ranking.all_matches'));
        $this->assertEquals(2, $checkRes->json('data.target_ranking.all_matches.1.google_page'));
        $this->assertEquals(4, $checkRes->json('data.target_ranking.all_matches.1.rank_on_page'));

        // 3. Stored snapshot reloads cleanly with zero extra API calls
        $snapId = $checkRes->json('data.snapshot_id');
        $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->getJson("/api/v1/websites/{$website->id}/rank-checker/history/{$snapId}")
            ->assertOk()
            ->assertJsonPath('data.target_ranking.position', 7);

        // 4. Tenant isolation blocks foreign organization
        $this->actingAs($foreignUser)
            ->withHeaders(['X-Organization-Id' => (string) $foreignOrg->id])
            ->postJson("/api/v1/websites/{$website->id}/rank-checker/check", [
                'keyword' => 'repair hot water system',
                'country' => 'SG',
            ])
            ->assertStatus(403);
    }

    public function test_workspace_encrypted_serper_key_enables_live_rank_checker_without_server_env(): void
    {
        config([
            'competitors.providers.dataforseo.login' => '',
            'competitors.providers.dataforseo.password' => '',
            'competitors.providers.serpapi.api_key' => '',
            'competitors.providers.serper.api_key' => '',
        ]);

        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Agency Workspace', 'slug' => 'agency-serper-key', 'owner_id' => $user->id]);
        OrganizationMember::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => OrganizationMember::ROLE_SEO_MANAGER]);

        $website = Website::create([
            'organization_id' => $org->id,
            'name' => 'Handyman SG',
            'domain' => 'handymansuperservices.com',
            'target_url' => 'https://handymansuperservices.com',
            'platform' => Website::PLATFORM_WORDPRESS,
            'target_country' => 'SG',
            'preferred_language' => 'en',
        ]);

        Http::fake([
            'https://google.serper.dev/search' => Http::sequence()
                ->push(['organic' => [['position' => 1, 'link' => 'https://google.com', 'title' => 'Google']]], 200)
                ->push([
                    'organic' => [
                        ['position' => 1, 'link' => 'https://rival.sg/heater', 'title' => 'Rival SG', 'snippet' => 'SG repair'],
                        ['position' => 2, 'link' => 'https://handymansuperservices.com/heater', 'title' => 'Handyman SG Heater', 'snippet' => 'Fast repair'],
                    ],
                ], 200),
        ]);

        // Before saving key, context reports unconfigured
        $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->getJson("/api/v1/websites/{$website->id}/rank-checker/context")
            ->assertOk()
            ->assertJsonPath('data.provider.configured', false);

        // Save & verify workspace Serper key
        $saveRes = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->postJson("/api/v1/websites/{$website->id}/rank-checker/provider-key", [
                'serper_api_key' => 'serper_live_secret_key_9988',
            ]);

        $saveRes->assertOk()
            ->assertJsonPath('data.saved', true)
            ->assertJsonPath('data.key_hint', '••••9988');

        // Raw key is NEVER exposed in context
        $ctx = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->getJson("/api/v1/websites/{$website->id}/rank-checker/context");
        $ctx->assertOk()
            ->assertJsonPath('data.provider.configured', true)
            ->assertJsonPath('data.provider.source', 'workspace_key')
            ->assertJsonPath('data.provider.key_hint', '••••9988');
        $this->assertStringNotContainsString('serper_live_secret_key_9988', $ctx->getContent());

        // Live rank check now succeeds using encrypted workspace key!
        $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->postJson("/api/v1/websites/{$website->id}/rank-checker/check", [
                'keyword' => 'repair hot water system',
                'country' => 'SG',
                'device' => 'mobile',
                'depth' => 30,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.target_ranking.position', 2)
            ->assertJsonPath('data.target_ranking.google_page', 1);
    }

    public function test_live_rank_checker_paginates_across_google_pages_to_find_deep_position_48(): void
    {
        config([
            'competitors.providers.dataforseo.login' => '',
            'competitors.providers.dataforseo.password' => '',
            'competitors.providers.serpapi.api_key' => '',
            'competitors.providers.serper.api_key' => 'test_serper_pagination_key',
        ]);

        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Deep Rank Org', 'slug' => 'deep-rank-org', 'owner_id' => $user->id]);
        OrganizationMember::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => OrganizationMember::ROLE_SEO_MANAGER]);

        $website = Website::create([
            'organization_id' => $org->id,
            'name' => 'Handyman Super Services',
            'domain' => 'handymansuperservices.com',
            'target_url' => 'https://handymansuperservices.com',
            'platform' => Website::PLATFORM_WORDPRESS,
            'target_country' => 'SG',
            'preferred_language' => 'en',
        ]);

        // Simulate real Serper.dev 10-results-per-page Google pagination where target ranks #8 on Page 5 (global #48)
        Http::fake([
            'https://google.serper.dev/search' => function (\Illuminate\Http\Client\Request $req) {
                $pg = (int) ($req['page'] ?? 1);
                $items = [];
                for ($i = 1; $i <= 10; $i++) {
                    $globalIdx = (($pg - 1) * 10) + $i;
                    if ($pg === 5 && $i === 8) {
                        $items[] = [
                            'position' => $i,
                            'link' => 'https://handymansuperservices.com/emergency-plumber-singapore/',
                            'title' => '24Hr Emergency Plumber Singapore | Handyman Super Services',
                            'snippet' => 'Fast 24/7 emergency plumbing repair across Singapore.',
                        ];
                    } else {
                        $items[] = [
                            'position' => $i,
                            'link' => "https://sg-plumber-{$globalIdx}.sg/service",
                            'title' => "SG Plumber #{$globalIdx}",
                            'snippet' => 'Singapore plumbing service.',
                        ];
                    }
                }
                return Http::response(['organic' => $items], 200);
            },
        ]);

        $res = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->postJson("/api/v1/websites/{$website->id}/rank-checker/check", [
                'keyword' => 'emergency plumber singapore',
                'country' => 'SG',
                'device' => 'desktop',
                'depth' => 100,
                'stop_when_found' => true,
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.target_ranking.found', true)
            ->assertJsonPath('data.target_ranking.position', 48)
            ->assertJsonPath('data.target_ranking.google_page', 5)
            ->assertJsonPath('data.target_ranking.rank_on_page', 8)
            ->assertJsonPath('data.results_returned', 50);

        // Must have all 5 Google Pages (#1..#50), stopping right after Page 5 (zero wasted requests on Pages 6-10)
        $this->assertCount(5, $res->json('data.pages_summary'));
        $this->assertTrue($res->json('data.pages_summary.4.has_target'));
        Http::assertSentCount(5);
    }

    public function test_new_user_workspace_automatically_inherits_platform_shared_serper_engine(): void
    {
        config([
            'competitors.providers.dataforseo.login' => '',
            'competitors.providers.dataforseo.password' => '',
            'competitors.providers.serpapi.api_key' => '',
            'competitors.providers.serper.api_key' => '',
        ]);

        // 1. Platform owner (agency_internal) already has a verified Serper key connected
        $owner = User::factory()->create();
        $platformOrg = Organization::create([
            'name' => 'Techboloy Agency',
            'slug' => 'techboloy-platform',
            'billing_plan' => 'agency_internal',
        ]);
        OrganizationMember::create([
            'organization_id' => $platformOrg->id,
            'user_id' => $owner->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);
        $ownerSite = Website::create([
            'organization_id' => $platformOrg->id,
            'name' => 'Platform Site',
            'domain' => 'techboloy.com',
            'target_url' => 'https://techboloy.com',
            'platform' => Website::PLATFORM_CUSTOM,
            'target_country' => 'US',
            'preferred_language' => 'en',
        ]);

        \App\Models\WebsiteIntegration::create([
            'organization_id' => $platformOrg->id,
            'website_id' => $ownerSite->id,
            'provider' => \App\Models\WebsiteIntegration::PROVIDER_SERPER,
            'status' => \App\Models\WebsiteIntegration::STATUS_CONNECTED,
            'encrypted_credentials' => ['api_key' => 'platform_shared_serper_secret_999'],
            'metadata' => ['key_hint' => '••••_999', 'platform_shared' => true],
        ]);

        // 2. Brand-new public user signs up with their own isolated workspace (ZERO API keys configured!)
        $newUser = User::factory()->create();
        $newOrg = Organization::create([
            'name' => 'CareMate Workspace',
            'slug' => 'caremate-ws',
            'billing_plan' => 'agency_pro',
        ]);
        OrganizationMember::create([
            'organization_id' => $newOrg->id,
            'user_id' => $newUser->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);
        $newSite = Website::create([
            'organization_id' => $newOrg->id,
            'name' => 'CareMate BD',
            'domain' => 'carematebd.com',
            'target_url' => 'https://carematebd.com',
            'platform' => Website::PLATFORM_WORDPRESS,
            'target_country' => 'BD',
            'preferred_language' => 'en',
        ]);

        // 3. New user's Rank Checker context immediately shows built-in engine active with zero key leak!
        $ctxRes = $this->actingAs($newUser)
            ->withHeaders(['X-Organization-Id' => (string) $newOrg->id])
            ->getJson("/api/v1/websites/{$newSite->id}/rank-checker/context");

        $ctxRes->assertOk()
            ->assertJsonPath('data.provider.configured', true)
            ->assertJsonPath('data.provider.source', 'platform_shared')
            ->assertJsonPath('data.provider.key_hint', null)
            ->assertJsonPath('data.provider.has_workspace_key', false);

        // 4. New user immediately runs a live rank check without pasting any API key
        Http::fake([
            'https://google.serper.dev/search' => function ($req) {
                $this->assertEquals('platform_shared_serper_secret_999', $req->header('X-API-KEY')[0] ?? '');
                return Http::response([
                    'organic' => [
                        ['position' => 1, 'link' => 'https://rival.com.bd/', 'title' => 'Rival', 'snippet' => 'Rival'],
                        ['position' => 2, 'link' => 'https://carematebd.com/home-care', 'title' => 'CareMate BD', 'snippet' => 'Home care in Dhaka'],
                    ],
                ], 200);
            },
        ]);

        $checkRes = $this->actingAs($newUser)
            ->withHeaders(['X-Organization-Id' => (string) $newOrg->id])
            ->postJson("/api/v1/websites/{$newSite->id}/rank-checker/check", [
                'keyword' => 'home care services in dhaka',
                'country' => 'BD',
                'device' => 'desktop',
                'depth' => 20,
            ]);

        $checkRes->assertStatus(201)
            ->assertJsonPath('data.target_ranking.found', true)
            ->assertJsonPath('data.target_ranking.position', 2)
            ->assertJsonPath('data.target_ranking.google_page', 1);
    }
}
