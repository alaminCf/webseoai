<?php

namespace Tests\Feature;

use App\Models\AiRecommendation;
use App\Models\AuditIssue;
use App\Models\AuditRun;
use App\Models\CompetitorDomain;
use App\Models\CompetitorKeyword;
use App\Models\CompetitorSerpSnapshot;
use App\Models\ContentArticle;
use App\Models\ContentBrief;
use App\Models\CrawlJob;
use App\Models\CrawledPage;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\SearchPerformanceRecord;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteTrafficEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_comprehensive_report_bundle_and_csv_exports_cover_all_modules_with_tenant_isolation(): void
    {
        $user = User::factory()->create();
        $foreignUser = User::factory()->create();

        $org = Organization::create([
            'name' => 'Techboloy Agency',
            'slug' => 'techboloy-reports',
            'owner_id' => $user->id,
        ]);
        OrganizationMember::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => OrganizationMember::ROLE_SEO_MANAGER,
        ]);

        $foreignOrg = Organization::create([
            'name' => 'Foreign Agency',
            'slug' => 'foreign-reports',
            'owner_id' => $foreignUser->id,
        ]);
        OrganizationMember::create([
            'organization_id' => $foreignOrg->id,
            'user_id' => $foreignUser->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        $website = Website::create([
            'organization_id' => $org->id,
            'name' => 'Handyman Super Services',
            'domain' => 'handymansuperservices.com',
            'target_url' => 'https://handymansuperservices.com',
            'platform' => Website::PLATFORM_WORDPRESS,
            'target_country' => 'SG',
            'preferred_language' => 'en',
        ]);

        // Seed Audit + Issue + CrawledPage
        $crawlJob = CrawlJob::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'status' => 'completed',
            'pages_crawled' => 10,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(4),
        ]);

        $page = CrawledPage::create([
            'crawl_job_id' => $crawlJob->id,
            'website_id' => $website->id,
            'url' => 'https://handymansuperservices.com/',
            'status_code' => 200,
            'load_time_ms' => 420,
            'word_count' => 850,
            'page_title' => 'Handyman Singapore 24Hr Repair',
            'meta_description' => 'Trusted plumbing & electrical handyman in Singapore.',
            'h1_tags' => ['Handyman Singapore'],
            'is_indexable' => true,
            'in_sitemap' => true,
            'images_count' => 8,
            'images_missing_alt' => 1,
        ]);

        $audit = AuditRun::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'crawl_job_id' => $crawlJob->id,
            'overall_score' => 92,
            'technical_score' => 95,
            'content_score' => 88,
            'link_score' => 90,
            'indexing_score' => 96,
            'critical_count' => 1,
            'warning_count' => 2,
            'info_count' => 0,
            'created_at' => now(),
        ]);

        AuditIssue::create([
            'audit_run_id' => $audit->id,
            'website_id' => $website->id,
            'crawled_page_id' => $page->id,
            'issue_type' => 'missing_meta_description',
            'category' => 'content',
            'severity' => 'critical',
            'affected_url' => 'https://handymansuperservices.com/plumber',
            'explanation' => 'Meta description tag is missing.',
            'recommended_fix' => 'Add a 140-character meta description.',
            'current_status' => 'open',
            'created_at' => now(),
        ]);

        // Seed Action Plan Recommendation
        AiRecommendation::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'audit_run_id' => $audit->id,
            'title' => 'Optimize Emergency Plumber Title Tag',
            'category' => 'content',
            'affected_url' => 'https://handymansuperservices.com/plumber',
            'recommended_action' => 'Include Singapore & 24hr keyword in title.',
            'reasoning_summary' => 'Title tag is missing high-intent Singapore geo modifier.',
            'expected_benefit' => '+15% organic CTR',
            'verification_procedure' => 'Re-crawl target URL and verify <title> length and keyword inclusion.',
            'priority_score' => 84.5,
            'impact_score' => 9,
            'confidence_score' => 8,
            'effort_score' => 2,
            'risk_score' => 1,
            'status' => AiRecommendation::STATUS_PROPOSED,
        ]);

        // Seed GSC Performance
        SearchPerformanceRecord::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'property_id' => 'sc-domain:handymansuperservices.com',
            'date' => now()->subDays(2)->toDateString(),
            'query' => 'repair hot water system',
            'page' => 'https://handymansuperservices.com/water-heater/',
            'country' => 'SGP',
            'device' => 'MOBILE',
            'clicks' => 24,
            'impressions' => 510,
            'ctr' => 4.71,
            'position' => 7.4,
        ]);

        // Seed Rank Checker Snapshot
        CompetitorSerpSnapshot::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'keyword' => 'repair hot water system',
            'country' => 'SG',
            'language' => 'en',
            'search_engine' => 'google',
            'device' => 'desktop',
            'location' => 'Singapore',
            'target_position' => 7,
            'serp_features' => ['local_pack', 'people_also_ask'],
            'organic_results' => [
                ['position' => 7, 'google_page' => 1, 'rank_on_page' => 7, 'domain' => 'handymansuperservices.com', 'url' => 'https://handymansuperservices.com/water-heater/', 'is_target' => true],
            ],
            'data_source' => 'serper',
            'captured_at' => now(),
        ]);

        // Seed Competitor + Keyword Gap
        CompetitorDomain::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'domain' => 'mrhandyman.sg',
            'category' => 'direct',
            'tier' => 'primary',
            'country' => 'SG',
            'language' => 'en',
        ]);

        CompetitorKeyword::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'keyword' => 'water heater installation singapore',
            'country' => 'SG',
            'language' => 'en',
            'gap_type' => 'missing',
            'competitor_positions' => ['mrhandyman.sg' => 4],
            'opportunity_score' => 88.0,
            'data_source' => 'serper',
            'last_updated_at' => now(),
        ]);

        // Seed Traffic Event
        WebsiteTrafficEvent::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'visitor_hash' => 'vis_abc123',
            'session_id' => 'sess_abc123',
            'event_type' => 'pageview',
            'is_test' => false,
            'path' => '/water-heater/',
            'page_title' => 'Water Heater Repair',
            'country_code' => 'SG',
            'country_name' => 'Singapore',
            'region' => 'Central',
            'city' => 'Singapore',
            'device_type' => 'mobile',
            'occurred_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(2),
        ]);

        // Seed Content Studio Brief & Article
        $brief = ContentBrief::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'created_by' => $user->id,
            'target_keyword' => 'emergency plumber singapore',
            'suggested_title' => 'Emergency Plumber Singapore Cost Guide',
            'suggested_meta_description' => 'Transparent 24/7 emergency plumber Singapore pricing and response times.',
            'word_count_target' => 1400,
            'search_intent' => 'commercial',
            'status' => ContentBrief::STATUS_APPROVED,
        ]);

        ContentArticle::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'content_brief_id' => $brief->id,
            'created_by' => $user->id,
            'title' => 'Emergency Plumber Singapore Cost Guide',
            'slug' => 'emergency-plumber-singapore-cost-guide',
            'content_body' => 'Complete guide to emergency plumbing repair costs in Singapore.',
            'word_count' => 1420,
            'quality_score' => 91,
            'search_intent' => 'commercial',
            'status' => ContentArticle::STATUS_READY_FOR_PUBLISH,
            'version' => 1,
        ]);

        // 1. Report bundle returns verified data across ALL 8 modules
        $bundleRes = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->getJson("/api/v1/websites/{$website->id}/reports/bundle?range=28d&sections=all");

        $bundleRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.executive_summary.overall_health_score', 92)
            ->assertJsonPath('data.executive_summary.gsc_clicks', 24)
            ->assertJsonPath('data.executive_summary.rank_checks_count', 1)
            ->assertJsonPath('data.executive_summary.competitors_count', 1)
            ->assertJsonPath('data.executive_summary.action_plan_total', 1)
            ->assertJsonPath('data.executive_summary.content_articles_count', 1)
            ->assertJsonPath('data.site_audit.issues.0.issue_type', 'missing_meta_description')
            ->assertJsonPath('data.rank_checker.checks.0.target_position', 7)
            ->assertJsonPath('data.competitors.keyword_gaps.0.keyword', 'water heater installation singapore');

        // 2. CSV downloads stream valid UTF-8 BOM CSV across datasets
        foreach (['full_summary', 'audit_issues', 'crawled_pages', 'action_plan', 'gsc_queries', 'rank_checker', 'competitors', 'keyword_gap', 'live_traffic', 'content_studio'] as $dataset) {
            $csvRes = $this->actingAs($user)
                ->withHeaders(['X-Organization-Id' => (string) $org->id])
                ->get("/api/v1/websites/{$website->id}/reports/export/csv?dataset={$dataset}&range=28d");

            $csvRes->assertOk();
            $this->assertStringContainsString('text/csv', (string) $csvRes->headers->get('Content-Type'));
            $this->assertStringContainsString('.csv', (string) $csvRes->headers->get('Content-Disposition'));
            $body = $csvRes->getContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        }

        $gscCsv = $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->get("/api/v1/websites/{$website->id}/reports/export/csv?dataset=gsc_queries&range=28d")
            ->getContent();
        $this->assertStringContainsString('repair hot water system', $gscCsv);

        // 3. Tenant isolation blocks foreign organization
        $this->actingAs($foreignUser)
            ->withHeaders(['X-Organization-Id' => (string) $foreignOrg->id])
            ->getJson("/api/v1/websites/{$website->id}/reports/bundle")
            ->assertStatus(403);

        $this->actingAs($foreignUser)
            ->withHeaders(['X-Organization-Id' => (string) $foreignOrg->id])
            ->get("/api/v1/websites/{$website->id}/reports/export/csv?dataset=full_summary")
            ->assertStatus(403);

        // 4. White-label base64 PNG logo upload persists and flows into report bundle
        $tinyPngDataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->putJson('/api/v1/white-label/branding', [
                'brand_name' => 'Techboloy',
                'logo_url' => $tinyPngDataUri,
                'primary_color' => '#8b5cf6',
                'contact_email' => 'seo@techboloy.com',
                'footer_text' => 'Confidential Techboloy Report',
            ])
            ->assertOk()
            ->assertJsonPath('data.logo_url', $tinyPngDataUri);

        $this->actingAs($user)
            ->withHeaders(['X-Organization-Id' => (string) $org->id])
            ->getJson("/api/v1/websites/{$website->id}/reports/bundle?range=28d&sections=executive_summary")
            ->assertOk()
            ->assertJsonPath('data.branding.agency_name', 'Techboloy')
            ->assertJsonPath('data.branding.agency_logo_url', $tinyPngDataUri);
    }
}
