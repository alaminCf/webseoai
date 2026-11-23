<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveTrafficTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_traffic_script_beacon_geo_breakdown_and_tenant_isolation(): void
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

        // 1. Tracker JS script serves valid JS
        $scriptRes = $this->get("/api/v1/t/{$token}.js");
        $scriptRes->assertOk();
        $this->assertStringContainsString($token, $scriptRes->getContent());

        // 2. Public beacon ingests live visits with Vercel Geo headers (Country, Region, City)
        $this->withHeaders([
            'X-Vercel-IP-Country' => 'BD',
            'X-Vercel-IP-Country-Region' => 'C',
            'X-Vercel-IP-City' => 'Dhaka',
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile Safari/604.1',
            'X-Forwarded-For' => '103.100.10.1',
        ])->postJson('/api/v1/t/collect', [
            'token' => $token,
            'event_type' => 'pageview',
            'path' => '/services/plumbing',
            'title' => 'Plumbing Services',
            'referrer' => 'https://www.google.com/search?q=handyman',
        ])->assertStatus(204);

        $this->withHeaders([
            'X-Vercel-IP-Country' => 'US',
            'X-Vercel-IP-Country-Region' => 'CA',
            'X-Vercel-IP-City' => 'San%20Francisco',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/126.0.0.0 Safari/537.36',
            'X-Forwarded-For' => '45.55.10.2',
        ])->postJson('/api/v1/t/collect', [
            'token' => $token,
            'event_type' => 'pageview',
            'path' => '/',
            'title' => 'Homepage',
            'referrer' => '',
        ])->assertStatus(204);

        // 3. Realtime analytics returns active visitors, BD Dhaka Division + US CA San Francisco breakdown
        $statsRes = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->getJson("/api/v1/websites/{$website->id}/traffic/realtime?range=24h");

        $statsRes->assertOk()
            ->assertJsonPath('data.summary.active_visitors_now', 2)
            ->assertJsonPath('data.summary.pageviews_range', 2)
            ->assertJsonPath('data.summary.countries_count', 2);

        $countries = collect($statsRes->json('data.by_country'));
        $bd = $countries->firstWhere('country_code', 'BD');
        $this->assertNotNull($bd);
        $this->assertEquals('Bangladesh', $bd['country_name']);

        // Filter by country=BD shows Dhaka Division / Dhaka only
        $bdOnly = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->getJson("/api/v1/websites/{$website->id}/traffic/realtime?range=24h&country=BD");
        $bdOnly->assertOk()
            ->assertJsonPath('data.by_region.0.region', 'Dhaka Division')
            ->assertJsonPath('data.by_region.0.city', 'Dhaka');

        // 4. Test ping endpoint works
        $this->actingAs($user)
            ->withHeaders([
                'X-Organization-Id' => (string) $org->id,
                'X-Vercel-IP-Country' => 'GB',
                'X-Vercel-IP-Country-Region' => 'ENG',
                'X-Vercel-IP-City' => 'London',
            ])
            ->postJson("/api/v1/websites/{$website->id}/traffic/test-ping")
            ->assertStatus(201);

        // 5. Tenant isolation blocks foreign organization
        $this->actingAs($foreignUser)
            ->withHeaders(['X-Organization-Id' => (string) $foreignOrg->id])
            ->getJson("/api/v1/websites/{$website->id}/traffic/realtime")
            ->assertStatus(403);
    }
}
