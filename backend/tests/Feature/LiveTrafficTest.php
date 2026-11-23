<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveTrafficTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_traffic_cors_free_beacon_pixel_bot_filter_test_isolation_and_ga4_realtime(): void
    {
        $user = User::factory()->create();
        $foreignUser = User::factory()->create();

        $org = Organization::create(['name' => 'Techboloy', 'slug' => 'techboloy', 'owner_id' => $user->id]);
        OrganizationMember::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => OrganizationMember::ROLE_OWNER]);

        $foreignOrg = Organization::create(['name' => 'Other Org', 'slug' => 'other-org', 'owner_id' => $foreignUser->id]);
        OrganizationMember::create(['organization_id' => $foreignOrg->id, 'user_id' => $foreignUser->id, 'role' => OrganizationMember::ROLE_OWNER]);

        $website = Website::create([
            'organization_id' => $org->id,
            'name' => 'Handyman Super Services',
            'domain' => 'handymansuperservices.com',
            'target_url' => 'https://handymansuperservices.com',
            'platform' => Website::PLATFORM_WORDPRESS,
        ]);

        $token = $website->ensureTrackingToken();
        $this->assertStringStartsWith('wsa_', $token);

        // 1. Tracker JS script uses zero-preflight text/plain transport + p.gif fallback
        $scriptRes = $this->get("/api/v1/t/{$token}.js");
        $scriptRes->assertOk();
        $this->assertStringContainsString('text/plain', $scriptRes->getContent());
        $this->assertStringContainsString('/api/v1/t/p.gif', $scriptRes->getContent());

        // 2. Public text/plain beacon ingests live human visits (and ignores bots!)
        $this->withHeaders([
            'X-Vercel-IP-Country' => 'SG',
            'X-Vercel-IP-Country-Region' => '01',
            'X-Vercel-IP-City' => 'Singapore',
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile Safari/604.1',
            'X-Forwarded-For' => '118.200.10.1',
        ])->call(
            'POST',
            '/api/v1/t/collect',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain;charset=UTF-8'],
            json_encode([
                'token' => $token,
                'client_id' => 'v_sg_visitor_1',
                'event_type' => 'pageview',
                'path' => '/services/plumbing',
                'title' => 'Plumbing Services Singapore',
                'referrer' => 'https://www.google.com/search?q=handyman+singapore',
            ])
        )->assertStatus(204);

        // Bot visit MUST be ignored!
        $this->withHeaders([
            'X-Vercel-IP-Country' => 'US',
            'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ])->postJson('/api/v1/t/collect', [
            'token' => $token,
            'event_type' => 'pageview',
            'path' => '/',
        ])->assertStatus(204);

        // 3. 1x1 GIF Pixel fallback records real visit cleanly
        $this->withHeaders([
            'X-Vercel-IP-Country' => 'BD',
            'X-Vercel-IP-Country-Region' => 'C',
            'X-Vercel-IP-City' => 'Dhaka',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/126.0.0.0 Safari/537.36',
        ])->get('/api/v1/t/p.gif?' . http_build_query([
            't' => $token,
            'e' => 'pageview',
            'v' => 'v_bd_visitor_2',
            'p' => '/',
            'ti' => 'Homepage',
        ]))->assertOk()->assertHeader('Content-Type', 'image/gif');

        // 4. Test ping is isolated (is_test = true) so default realtime stats ONLY count the 2 real human visitors!
        $this->actingAs($user)
            ->withHeaders([
                'X-Organization-Id' => (string) $org->id,
                'X-Vercel-IP-Country' => 'GB',
                'X-Vercel-IP-City' => 'London',
            ])
            ->postJson("/api/v1/websites/{$website->id}/traffic/test-ping")
            ->assertStatus(201);

        $statsRes = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->getJson("/api/v1/websites/{$website->id}/traffic/realtime?range=24h");

        $statsRes->assertOk()
            ->assertJsonPath('data.summary.active_visitors_now', 2)
            ->assertJsonPath('data.summary.pageviews_range', 2)
            ->assertJsonPath('data.tracker.real_events_all_time', 2)
            ->assertJsonPath('data.tracker.test_events_all_time', 1);

        // Clear test visits works
        $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->deleteJson("/api/v1/websites/{$website->id}/traffic/test-events")
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1);

        // 5. Verify live installation detects GA4 G-tag and missing tracker script accurately
        Http::fake([
            'https://handymansuperservices.com*' => Http::response(
                '<html><head><script async src="https://www.googletagmanager.com/gtag/js?id=G-ECMTVCJLZC"></script></head><body>Hi</body></html>',
                200
            ),
            'https://analyticsdata.googleapis.com/v1beta/properties/412345678:runRealtimeReport' => Http::response([
                'rows' => [[
                    'dimensionValues' => [
                        ['value' => 'Singapore'],
                        ['value' => 'SG'],
                        ['value' => 'Singapore'],
                        ['value' => '24Hr Handyman Services Singapore'],
                        ['value' => 'mobile'],
                    ],
                    'metricValues' => [
                        ['value' => '7'],
                        ['value' => '14'],
                    ],
                ]],
            ], 200),
            'https://analyticsdata.googleapis.com/v1beta/properties/412345678:runReport' => Http::response([
                'rows' => [[
                    'dimensionValues' => [
                        ['value' => 'Singapore'],
                        ['value' => 'SG'],
                        ['value' => 'Central Singapore'],
                        ['value' => 'Singapore'],
                    ],
                    'metricValues' => [
                        ['value' => '42'],
                        ['value' => '118'],
                    ],
                ]],
            ], 200),
        ]);

        $verifyRes = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->postJson("/api/v1/websites/{$website->id}/traffic/verify-install");
        $verifyRes->assertOk()
            ->assertJsonPath('data.tracker_installed', false)
            ->assertJsonPath('data.detected_ga4_id', 'G-ECMTVCJLZC');

        // 6. Native GA4 Realtime returns exact GA4 active users + Country/Region/City
        WebsiteIntegration::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'provider' => WebsiteIntegration::PROVIDER_GA4,
            'encrypted_credentials' => ['access_token' => 'fake_ga4_token', 'expires_at' => now()->addHour()->timestamp],
            'status' => WebsiteIntegration::STATUS_CONNECTED,
            'metadata' => ['selected_property_id' => '412345678', 'selected_property_name' => 'Handyman SG GA4'],
        ]);

        $ga4Res = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->getJson("/api/v1/websites/{$website->id}/traffic/realtime?range=24h&source=ga4");
        $ga4Res->assertOk()
            ->assertJsonPath('data.data_source', 'google_analytics_4')
            ->assertJsonPath('data.summary.active_visitors_now', 7)
            ->assertJsonPath('data.by_country.0.country_code', 'SG')
            ->assertJsonPath('data.by_region.0.region', 'Central Singapore');

        // 7. Tenant isolation blocks foreign organization
        $this->actingAs($foreignUser)
            ->withHeaders(['X-Organization-Id' => (string) $foreignOrg->id])
            ->getJson("/api/v1/websites/{$website->id}/traffic/realtime")
            ->assertStatus(403);
    }

    public function test_ga4_one_click_reuses_org_google_oauth_token_and_surfaces_api_diagnostics(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Techboloy', 'slug' => 'techboloy-ga4', 'owner_id' => $user->id]);
        OrganizationMember::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => OrganizationMember::ROLE_OWNER]);

        $siteA = Website::create([
            'organization_id' => $org->id,
            'name' => 'CareMate BD',
            'domain' => 'carematebd.com',
            'target_url' => 'https://carematebd.com',
            'platform' => Website::PLATFORM_CUSTOM,
        ]);

        $siteB = Website::create([
            'organization_id' => $org->id,
            'name' => 'Handyman Super Services',
            'domain' => 'handymansuperservices.com',
            'target_url' => 'https://handymansuperservices.com',
            'platform' => Website::PLATFORM_WORDPRESS,
        ]);

        // Site A already connected Google OAuth with both webmasters + analytics.readonly scopes
        WebsiteIntegration::create([
            'organization_id' => $org->id,
            'website_id' => $siteA->id,
            'provider' => WebsiteIntegration::PROVIDER_GSC,
            'encrypted_credentials' => [
                'access_token' => 'org_google_token',
                'refresh_token' => 'org_refresh_token',
                'scope' => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly',
                'expires_at' => now()->addHour()->timestamp,
                'is_sandbox' => false,
            ],
            'status' => WebsiteIntegration::STATUS_CONNECTED,
        ]);

        // First call simulates Google Analytics Admin API disabled -> exact error & enable_url must be surfaced!
        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::sequence()
                ->push([
                    'error' => [
                        'message' => 'Google Analytics Admin API has not been used in project 12345 before or it is disabled. Enable it by visiting https://console.developers.google.com/apis/api/analyticsadmin.googleapis.com/overview?project=12345 then retry.',
                    ],
                ], 403)
                ->push([
                    'accountSummaries' => [[
                        'displayName' => 'Techboloy Agency GA4',
                        'propertySummaries' => [[
                            'property' => 'properties/381234567',
                            'displayName' => 'Handyman Super Services SG',
                        ]],
                    ]],
                ], 200),
        ]);

        // 1-Click connect on Site B immediately reuses org Google token (zero OAuth popup!) and surfaces Admin API error clearly
        $connRes = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->getJson("/api/v1/websites/{$siteB->id}/traffic/ga4/connect");

        $connRes->assertOk()
            ->assertJsonPath('data.reused_google_oauth', true)
            ->assertJsonPath('data.ga4.connected', true);
        $this->assertStringContainsString('analyticsadmin.googleapis.com', (string) $connRes->json('data.ga4.admin_api_enable_url'));

        // After user enables Admin API in Google Cloud Console, Re-Scan immediately discovers & auto-selects Handyman property!
        $refreshRes = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->postJson("/api/v1/websites/{$siteB->id}/traffic/ga4/refresh-properties");

        $refreshRes->assertOk()
            ->assertJsonPath('data.selected_property_id', '381234567')
            ->assertJsonPath('data.error', null);
    }
}
