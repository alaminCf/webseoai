<?php

namespace Tests\Feature;

use App\Domain\Auditing\AuditRulesEngine;
use App\Domain\Crawler\CrawlEngine;
use App\Domain\Crawler\PageInspector;
use App\Domain\Crawler\RobotsTxtParser;
use App\Domain\Crawler\SitemapParser;
use App\Domain\Crawler\UrlNormalizer;
use App\Domain\Crawler\UrlSecurityValidator;
use App\Models\AuditIssue;
use App\Models\AuditRun;
use App\Models\CrawledPage;
use App\Models\CrawlJob;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CrawlerAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $seoUser;
    private Organization $organization;
    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seoUser = User::create([
            'name' => 'SEO Engineer',
            'email' => 'seo@techboloy.com',
            'password' => bcrypt('Password123!'),
        ]);

        $this->organization = Organization::create([
            'name' => 'Techboloy SEO Lab',
            'slug' => 'techboloy-seo-lab',
        ]);

        OrganizationMember::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->seoUser->id,
            'role' => OrganizationMember::ROLE_SEO_MANAGER,
        ]);

        $this->website = Website::create([
            'organization_id' => $this->organization->id,
            'name' => 'Fixture Site',
            'domain' => 'fixturesite.com',
            'target_url' => 'https://fixturesite.com',
            'platform' => 'custom',
            'verification_status' => Website::VERIFICATION_VERIFIED,
            'verified_at' => now(),
        ]);
    }

    public function test_ssrf_validator_blocks_private_ips_and_metadata_endpoints(): void
    {
        // Link-local / Cloud metadata
        $this->expectException(RuntimeException::class);
        UrlSecurityValidator::validate('http://169.254.169.254/latest/meta-data', 'fixturesite.com');
    }

    public function test_ssrf_validator_blocks_loopback_and_private_rfc1918_ranges(): void
    {
        $this->assertTrue(UrlSecurityValidator::isDangerousIp('127.0.0.1'));
        $this->assertTrue(UrlSecurityValidator::isDangerousIp('10.0.0.1'));
        $this->assertTrue(UrlSecurityValidator::isDangerousIp('192.168.1.1'));
        $this->assertTrue(UrlSecurityValidator::isDangerousIp('172.16.0.1'));
        $this->assertTrue(UrlSecurityValidator::isDangerousIp('169.254.169.254'));
        $this->assertFalse(UrlSecurityValidator::isDangerousIp('93.184.216.34')); // public IP
    }

    public function test_ssrf_validator_blocks_cross_domain_unauthorized_targets(): void
    {
        $this->expectException(RuntimeException::class);
        UrlSecurityValidator::validate('https://malicious-external-target.com/exploit', 'fixturesite.com');
    }

    public function test_url_normalizer_resolves_relative_links_and_removes_fragments(): void
    {
        $base = 'https://fixturesite.com/blog/article-1';

        $this->assertEquals(
            'https://fixturesite.com/about',
            UrlNormalizer::normalize('/about#team', $base)
        );

        $this->assertEquals(
            'https://fixturesite.com/blog/article-2?a=2&b=1',
            UrlNormalizer::normalize('article-2?b=1&a=2', $base)
        );

        $this->assertTrue(UrlNormalizer::isInternal('https://fixturesite.com/subpage', 'fixturesite.com'));
        $this->assertTrue(UrlNormalizer::isInternal('https://blog.fixturesite.com/post', 'fixturesite.com'));
        $this->assertFalse(UrlNormalizer::isInternal('https://google.com', 'fixturesite.com'));
    }

    public function test_robots_txt_parser_honors_disallow_and_extracts_sitemaps(): void
    {
        $robotsContent = <<<TXT
User-agent: *
Disallow: /admin
Disallow: /checkout/
Allow: /admin/login
Sitemap: https://fixturesite.com/sitemap.xml
TXT;

        $parser = new RobotsTxtParser($robotsContent);

        $this->assertFalse($parser->isAllowed('https://fixturesite.com/admin/settings'));
        $this->assertTrue($parser->isAllowed('https://fixturesite.com/admin/login'));
        $this->assertTrue($parser->isAllowed('https://fixturesite.com/products'));
        $this->assertEquals(['https://fixturesite.com/sitemap.xml'], $parser->getSitemaps());
    }

    public function test_sitemap_parser_safely_extracts_urls_without_xxe(): void
    {
        $sitemapXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
   <url><loc>https://fixturesite.com/</loc></url>
   <url><loc>https://fixturesite.com/services</loc></url>
</urlset>
XML;

        $urls = SitemapParser::parse($sitemapXml);

        $this->assertCount(2, $urls);
        $this->assertContains('https://fixturesite.com/', $urls);
        $this->assertContains('https://fixturesite.com/services', $urls);
    }

    public function test_page_inspector_extracts_headings_images_meta_and_links(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Awesome Page Title - Target Keyword</title>
    <meta name="description" content="This is an accurate meta description of this page.">
    <link rel="canonical" href="https://fixturesite.com/canonical-url">
    <meta name="robots" content="noindex, follow">
</head>
<body>
    <h1>Main H1 Heading</h1>
    <h2>Subheading 1</h2>
    <h4>Skipped to H4</h4>
    <img src="/logo.png" alt="Company Logo">
    <img src="/banner.png"> <!-- missing alt -->
    <a href="/about">About Us</a>
    <a href="https://external.com" rel="nofollow">External Link</a>
    <p>This is test content containing several words to evaluate page inspector word count calculation.</p>
</body>
</html>
HTML;

        $result = PageInspector::inspect($html, 'https://fixturesite.com/test-page', 'fixturesite.com');

        $this->assertEquals('Awesome Page Title - Target Keyword', $result['page_title']);
        $this->assertEquals('This is an accurate meta description of this page.', $result['meta_description']);
        $this->assertEquals('https://fixturesite.com/canonical-url', $result['canonical_url']);
        $this->assertFalse($result['is_indexable']); // due to noindex
        $this->assertEquals(['Main H1 Heading'], $result['h1_tags']);
        $this->assertEquals(2, $result['images_count']);
        $this->assertEquals(1, $result['images_missing_alt']);
        $this->assertCount(1, $result['internal_links']);
        $this->assertCount(1, $result['external_links']);
        $this->assertGreaterThan(5, $result['word_count']);
    }

    public function test_end_to_end_crawler_and_audit_engine_against_fixture_site(): void
    {
        // Mock HTTP fixture website with deliberate SEO defects
        Http::fake([
            'https://fixturesite.com/robots.txt' => Http::response(
                "User-agent: *\nDisallow: /admin\nSitemap: https://fixturesite.com/sitemap.xml",
                200
            ),
            'https://fixturesite.com/sitemap.xml' => Http::response(
                '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' .
                '<url><loc>https://fixturesite.com</loc></url>' .
                '<url><loc>https://fixturesite.com/about</loc></url>' .
                '<url><loc>https://fixturesite.com/orphan-article</loc></url>' .
                '</urlset>',
                200
            ),
            'https://fixturesite.com/' => Http::response(
                '<!DOCTYPE html><html><head><title>Home Page</title><meta name="description" content="Valid description for homepage."></head>' .
                '<body><h1>Home H1</h1><a href="/about">About</a><a href="/broken-target">Broken</a><img src="/img.png"></body></html>',
                200
            ),
            'https://fixturesite.com' => Http::response(
                '<!DOCTYPE html><html><head><title>Home Page</title><meta name="description" content="Valid description for homepage."></head>' .
                '<body><h1>Home H1</h1><a href="/about">About</a><a href="/broken-target">Broken</a><img src="/img.png"></body></html>',
                200
            ),
            'https://fixturesite.com/about' => Http::response(
                '<!DOCTYPE html><html><head><!-- missing title --><meta name="description" content="Valid description for about."></head>' .
                '<body><h1>First H1</h1><h1>Second H1</h1><h3>Skipped H3</h3><a href="/">Home</a></body></html>',
                200
            ),
            'https://fixturesite.com/orphan-article' => Http::response(
                '<!DOCTYPE html><html><head><title>Orphan Article</title><meta name="description" content="Orphan description."></head>' .
                '<body><h1>Orphan Article</h1></body></html>',
                200
            ),
            'https://fixturesite.com/broken-target' => Http::response('Not Found', 404),
        ]);

        $crawlJob = CrawlJob::create([
            'organization_id' => $this->organization->id,
            'website_id' => $this->website->id,
            'initiated_by' => $this->seoUser->id,
            'status' => CrawlJob::STATUS_QUEUED,
            'max_pages' => 50,
            'max_depth' => 3,
        ]);

        $engine = new CrawlEngine($crawlJob, true);
        $engine->run();

        $crawlJob->refresh();
        $this->assertEquals(CrawlJob::STATUS_COMPLETED, $crawlJob->status);
        $this->assertGreaterThanOrEqual(3, $crawlJob->pages_crawled);

        // Audit run assertion
        $auditRun = AuditRun::where('crawl_job_id', $crawlJob->id)->first();
        $this->assertNotNull($auditRun);
        $this->assertGreaterThan(0, $auditRun->critical_count);

        // Verify specific deterministic rule issue detections
        $issues = AuditIssue::where('audit_run_id', $auditRun->id)->get();

        $issueTypes = $issues->pluck('issue_type')->toArray();

        // 1. HTTP 404 detected
        $this->assertContains('STATUS_4XX', $issueTypes);

        // 2. Broken internal link detected
        $this->assertContains('BROKEN_INTERNAL_LINK', $issueTypes);

        // 3. Missing title detected on /about
        $this->assertContains('MISSING_TITLE', $issueTypes);

        // 4. Multiple H1 detected on /about
        $this->assertContains('MULTIPLE_H1', $issueTypes);

        // 5. Missing image alt detected on /
        $this->assertContains('MISSING_IMAGE_ALT', $issueTypes);

        // 6. Orphan page detected on /orphan-article (in sitemap, in_degree = 0)
        $this->assertContains('ORPHAN_PAGE', $issueTypes);

        // Website health score and audit date updated
        $this->website->refresh();
        $this->assertNotNull($this->website->last_audited_at);
        $this->assertEquals($auditRun->overall_score, $this->website->health_score);
    }

    public function test_audit_api_endpoints_list_issues_and_compare_runs(): void
    {
        $job1 = CrawlJob::create([
            'organization_id' => $this->organization->id,
            'website_id' => $this->website->id,
            'status' => CrawlJob::STATUS_COMPLETED,
        ]);

        $run1 = AuditRun::create([
            'organization_id' => $this->organization->id,
            'website_id' => $this->website->id,
            'crawl_job_id' => $job1->id,
            'overall_score' => 65,
            'technical_score' => 70,
            'content_score' => 60,
            'link_score' => 65,
            'critical_count' => 2,
            'warning_count' => 3,
            'info_count' => 1,
        ]);

        AuditIssue::create([
            'audit_run_id' => $run1->id,
            'website_id' => $this->website->id,
            'issue_type' => 'MISSING_TITLE',
            'severity' => AuditIssue::SEVERITY_CRITICAL,
            'affected_url' => 'https://fixturesite.com/broken-page',
            'explanation' => 'Missing title tag',
            'recommended_fix' => 'Add title',
        ]);

        // Run 2: Improved score, issue resolved
        $job2 = CrawlJob::create([
            'organization_id' => $this->organization->id,
            'website_id' => $this->website->id,
            'status' => CrawlJob::STATUS_COMPLETED,
        ]);

        $run2 = AuditRun::create([
            'organization_id' => $this->organization->id,
            'website_id' => $this->website->id,
            'crawl_job_id' => $job2->id,
            'overall_score' => 90,
            'technical_score' => 90,
            'content_score' => 90,
            'link_score' => 90,
            'critical_count' => 0,
            'warning_count' => 1,
            'info_count' => 0,
        ]);

        // 1. Test issues listing
        $issuesRes = $this->actingAs($this->seoUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->getJson("/api/v1/audits/{$run1->id}/issues?severity=critical");

        $issuesRes->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
        $this->assertCount(1, $issuesRes->json('data'));

        // 2. Test before / after comparison
        $compareRes = $this->actingAs($this->seoUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->getJson("/api/v1/websites/{$this->website->id}/audits/compare?run1={$run1->id}&run2={$run2->id}");

        $compareRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'score_diff' => 25,
                    'resolved_issues_count' => 1,
                    'new_issues_count' => 0,
                ],
            ]);
    }

    public function test_page_inspector_excludes_scripts_from_word_count_and_extracts_schema_og_and_waf(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Genuine Technical SEO Audit Platform</title>
    <meta name="description" content="Enterprise technical SEO crawler and deterministic audit suite.">
    <meta property="og:title" content="OG Title">
    <meta property="og:description" content="OG Description">
    <meta property="og:image" content="https://fixturesite.com/og.png">
    <script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"FixtureCorp"}</script>
</head>
<body>
    <style>.hidden { color: red; font-family: sans-serif; }</style>
    <script>var hugeScriptText = "should never be counted as visible page copy words";</script>
    <h1>Welcome to FixtureCorp</h1>
    <p>বাংলা এবং English visible text works accurately.</p>
    <img src="http://insecure.fixturesite.com/banner.jpg" alt="Banner">
</body>
</html>
HTML;

        $result = PageInspector::inspect(
            $html,
            'https://fixturesite.com/',
            'fixturesite.com',
            ['Strict-Transport-Security' => ['max-age=31536000'], 'X-Content-Type-Options' => ['nosniff']],
            200,
            240
        );

        $this->assertEquals('en', $result['html_lang']);
        $this->assertEquals('width=device-width, initial-scale=1', $result['viewport']);
        $this->assertEquals(['Organization'], $result['structured_data']['types']);
        $this->assertEquals('OG Title', $result['open_graph']['title']);
        $this->assertTrue($result['security_headers']['hsts']);
        $this->assertTrue($result['security_headers']['x_content_type_options']);
        $this->assertContains('http://insecure.fixturesite.com/banner.jpg', $result['mixed_content_urls']);
        $this->assertFalse($result['is_blocked_by_waf']);
        // Script & style words must NOT be counted; visible heading + paragraph = 10 words
        $this->assertLessThan(20, $result['word_count']);
        $this->assertGreaterThanOrEqual(8, $result['word_count']);

        // WAF challenge detection
        $wafHtml = '<!DOCTYPE html><html><head><title>One moment, please...</title></head><body><script>var cf_chl_opt={};</script></body></html>';
        $wafResult = PageInspector::inspect($wafHtml, 'https://fixturesite.com/waf', 'fixturesite.com', [], 200, 120);
        $this->assertTrue($wafResult['is_blocked_by_waf']);
        $this->assertFalse($wafResult['is_indexable']);
    }

    public function test_crawler_tracks_redirect_chains_recursive_sitemaps_and_api_contract(): void
    {
        Http::fake([
            'https://fixturesite.com/robots.txt' => Http::response(
                "User-agent: *\nAllow: /\nSitemap: https://fixturesite.com/sitemap_index.xml",
                200
            ),
            'https://fixturesite.com/sitemap_index.xml' => Http::response(
                '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' .
                '<sitemap><loc>https://fixturesite.com/pages-sitemap.xml</loc></sitemap>' .
                '</sitemapindex>',
                200
            ),
            'https://fixturesite.com/pages-sitemap.xml' => Http::response(
                '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' .
                '<url><loc>https://fixturesite.com/</loc></url>' .
                '<url><loc>https://fixturesite.com/old-page</loc></url>' .
                '</urlset>',
                200
            ),
            'https://fixturesite.com/' => Http::response(
                '<!DOCTYPE html><html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1">' .
                '<title>Fixture Site Official Homepage Title For SEO</title>' .
                '<meta name="description" content="Complete technical SEO homepage description with proper length to pass audit checks cleanly.">' .
                '<link rel="canonical" href="https://fixturesite.com/"></head>' .
                '<body><h1>Fixture Site Home</h1><a href="/old-page">Old Page</a></body></html>',
                200
            ),
            'https://fixturesite.com/old-page' => Http::response('', 301, ['Location' => 'https://fixturesite.com/mid-page']),
            'https://fixturesite.com/mid-page' => Http::response('', 302, ['Location' => 'https://fixturesite.com/final-page']),
            'https://fixturesite.com/final-page' => Http::response(
                '<!DOCTYPE html><html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1">' .
                '<title>Final Destination Page Title After Redirect</title>' .
                '<meta name="description" content="Destination page after redirect chain for verifying multi-hop tracking.">' .
                '<link rel="canonical" href="https://fixturesite.com/final-page"></head>' .
                '<body><h1>Final Page</h1></body></html>',
                200
            ),
        ]);

        $crawlJob = CrawlJob::create([
            'organization_id' => $this->organization->id,
            'website_id' => $this->website->id,
            'initiated_by' => $this->seoUser->id,
            'status' => CrawlJob::STATUS_QUEUED,
            'max_pages' => 20,
            'max_depth' => 3,
        ]);

        $engine = new CrawlEngine($crawlJob, true);
        $engine->run();

        $auditRun = AuditRun::where('crawl_job_id', $crawlJob->id)->first();
        $this->assertNotNull($auditRun);

        // Verify redirect chain detected genuinely
        $issueTypes = AuditIssue::where('audit_run_id', $auditRun->id)->pluck('issue_type')->toArray();
        $this->assertContains('REDIRECT_CHAIN', $issueTypes);

        // Verify 4-pillar score consistency
        $expectedOverall = (int) round(
            ($auditRun->technical_score * 0.30)
            + ($auditRun->content_score * 0.30)
            + ($auditRun->indexing_score * 0.20)
            + ($auditRun->link_score * 0.20)
        );
        $this->assertEquals($expectedOverall, $auditRun->overall_score);

        // Verify latest audit API contract includes crawl_job_id, links_score, indexing_score, total_pages_analyzed
        $latestRes = $this->actingAs($this->seoUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->getJson("/api/v1/websites/{$this->website->id}/audits/latest");

        $latestRes->assertStatus(200)
            ->assertJsonPath('data.crawl_job_id', $crawlJob->id)
            ->assertJsonPath('data.links_score', $auditRun->link_score)
            ->assertJsonPath('data.indexing_score', $auditRun->indexing_score);
        $this->assertGreaterThanOrEqual(2, $latestRes->json('data.total_pages_analyzed'));

        // Verify crawled pages endpoint returns full telemetry without 500 error
        $pagesRes = $this->actingAs($this->seoUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->getJson("/api/v1/crawl-jobs/{$crawlJob->id}/pages");

        $pagesRes->assertStatus(200);
        $this->assertNotEmpty($pagesRes->json('data'));
    }
}
