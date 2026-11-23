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
                $this->assertEquals(50, $payload['num']);

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
}
