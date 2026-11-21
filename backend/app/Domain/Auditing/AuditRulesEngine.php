<?php

namespace App\Domain\Auditing;

use App\Domain\Crawler\UrlNormalizer;
use App\Models\AuditIssue;
use App\Models\AuditRun;
use App\Models\CrawledPage;
use App\Models\CrawlJob;
use App\Models\Website;
use Illuminate\Database\Eloquent\Collection;

class AuditRulesEngine
{
    protected CrawlJob $job;
    protected Website $website;
    protected Collection $pages;
    protected array $siteChecks = [];
    protected string $homepageUrl;
    protected array $issues = [];
    protected array $passedChecks = [];

    public function __construct(CrawlJob $job)
    {
        $this->job = $job;
        $this->website = $job->website;
        $this->pages = CrawledPage::where('crawl_job_id', $job->id)->get();
        $this->siteChecks = $job->site_checks ?? [];
        $this->homepageUrl = UrlNormalizer::canonicalHomepageUrl($this->website->target_url);
    }

    /**
     * Execute all deterministic site-level and page-level SEO audit rules.
     */
    public function evaluate(): AuditRun
    {
        $this->issues = [];
        $this->passedChecks = [];

        // Site-level foundational checks
        $this->checkSiteFoundations();

        // Technical & Performance checks
        $this->checkHttpStatusCodesAndBrokenLinks();
        $this->checkRedirectChainsAndTempRedirects();
        $this->checkTransportSecurityAndMixedContent();
        $this->checkMobileViewportAndPerformance();
        $this->checkUrlPatterns();
        $this->checkSpaAndWafDiagnostics();

        // On-Page & Content checks
        $this->checkTitleTags();
        $this->checkMetaDescriptions();
        $this->checkHeadingHierarchy();
        $this->checkThinAndDuplicateContent();
        $this->checkImageAltAttributes();
        $this->checkSocialAndStructuredData();
        $this->checkHtmlLanguage();

        // Indexing & Crawlability checks
        $this->checkCanonicalTags();
        $this->checkIndexingDirectivesAndMetaRefresh();
        $this->checkSitemapAndOrphans();

        // Links & Architecture checks
        $this->checkLinkArchitectureAndDepth();

        // Compile verified Passed Checks
        $this->buildPassedChecks();

        // Severity counts
        $criticalCount = count(array_filter($this->issues, fn($i) => $i['severity'] === AuditIssue::SEVERITY_CRITICAL));
        $warningCount = count(array_filter($this->issues, fn($i) => $i['severity'] === AuditIssue::SEVERITY_WARNING));
        $infoCount = count(array_filter($this->issues, fn($i) => $i['severity'] === AuditIssue::SEVERITY_INFO));

        // 4-Pillar Weighted Scoring
        $scores = $this->calculateScores();

        // Build issue groups summary
        $issueGroups = $this->buildIssueGroups();

        // Rich summary statistics
        $validHtmlPages = $this->pages->filter(fn(CrawledPage $p) => $p->status_code === 200 && !$p->is_blocked_by_waf);
        $avgLoadMs = $validHtmlPages->count() > 0 ? (int) round($validHtmlPages->avg('load_time_ms')) : 0;
        $avgWordCount = $validHtmlPages->count() > 0 ? (int) round($validHtmlPages->avg('word_count')) : 0;

        $summaryStats = [
            'total_pages_crawled' => $this->pages->count(),
            'indexable_pages' => $this->pages->where('is_indexable', true)->count(),
            'status_200_count' => $this->pages->where('status_code', 200)->count(),
            'status_3xx_count' => $this->pages->whereBetween('status_code', [300, 399])->count(),
            'status_4xx_count' => $this->pages->whereBetween('status_code', [400, 499])->count(),
            'status_5xx_count' => $this->pages->whereBetween('status_code', [500, 599])->count(),
            'avg_load_time_ms' => $avgLoadMs,
            'avg_word_count' => $avgWordCount,
            'js_rendered_pages' => $this->pages->where('is_js_rendered', true)->count(),
            'spa_shell_pages' => $this->pages->where('is_spa_shell', true)->count(),
            'waf_blocked_pages' => $this->pages->where('is_blocked_by_waf', true)->count(),
            'passed_checks_count' => count($this->passedChecks),
            'passed_checks' => $this->passedChecks,
            'site_checks' => $this->siteChecks,
            'issue_groups' => $issueGroups,
        ];

        $auditRun = AuditRun::create([
            'organization_id' => $this->website->organization_id,
            'website_id' => $this->website->id,
            'crawl_job_id' => $this->job->id,
            'overall_score' => $scores['overall'],
            'technical_score' => $scores['technical'],
            'content_score' => $scores['content'],
            'link_score' => $scores['link'],
            'indexing_score' => $scores['indexing'],
            'critical_count' => $criticalCount,
            'warning_count' => $warningCount,
            'info_count' => $infoCount,
            'summary_stats' => $summaryStats,
            'created_at' => now(),
        ]);

        foreach ($this->issues as $issueData) {
            AuditIssue::create(array_merge($issueData, [
                'audit_run_id' => $auditRun->id,
                'website_id' => $this->website->id,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'created_at' => now(),
            ]));
        }

        $this->website->update([
            'health_score' => $scores['overall'],
            'last_audited_at' => now(),
        ]);

        return $auditRun;
    }

    /**
     * Site-level foundational checks: robots.txt, sitemap.xml, HTTPS, www consistency.
     */
    protected function checkSiteFoundations(): void
    {
        $robots = $this->siteChecks['robots_txt'] ?? null;
        if (is_array($robots)) {
            if (empty($robots['exists'])) {
                $this->addIssue([
                    'crawled_page_id' => null,
                    'issue_type' => 'MISSING_ROBOTS_TXT',
                    'category' => AuditIssue::CATEGORY_INDEXING,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $robots['url'] ?? (rtrim($this->homepageUrl, '/') . '/robots.txt'),
                    'evidence' => ['status_code' => $robots['status_code'] ?? null],
                    'explanation' => 'No valid robots.txt file was found at the domain root. Search engines rely on robots.txt to discover sitemaps and crawl directives.',
                    'recommended_fix' => 'Create a valid /robots.txt file that allows search engine crawling and declares your Sitemap URL.',
                ]);
            } elseif (!empty($robots['blocks_all'])) {
                $this->addIssue([
                    'crawled_page_id' => null,
                    'issue_type' => 'ROBOTS_BLOCKS_SITE',
                    'category' => AuditIssue::CATEGORY_INDEXING,
                    'severity' => AuditIssue::SEVERITY_CRITICAL,
                    'affected_url' => $robots['url'] ?? (rtrim($this->homepageUrl, '/') . '/robots.txt'),
                    'evidence' => ['blocks_all' => true],
                    'explanation' => 'robots.txt currently disallows crawling of the root path ("/"), which prevents search engines from indexing your website.',
                    'recommended_fix' => 'Remove "Disallow: /" from robots.txt for production websites and only restrict private/admin paths.',
                ]);
            }
        }

        $sitemap = $this->siteChecks['sitemap'] ?? null;
        if (is_array($sitemap) && empty($sitemap['found'])) {
            $this->addIssue([
                'crawled_page_id' => null,
                'issue_type' => 'MISSING_SITEMAP',
                'category' => AuditIssue::CATEGORY_INDEXING,
                'severity' => AuditIssue::SEVERITY_WARNING,
                'affected_url' => rtrim($this->homepageUrl, '/') . '/sitemap.xml',
                'evidence' => ['sitemaps_checked' => $sitemap['sitemaps_checked'] ?? []],
                'explanation' => 'No valid XML sitemap was discovered via robots.txt or standard paths (/sitemap.xml, /sitemap_index.xml, /wp-sitemap.xml).',
                'recommended_fix' => 'Generate an XML sitemap listing all canonical, indexable URLs and reference it in robots.txt and Google Search Console.',
            ]);
        }

        $https = $this->siteChecks['https_enforcement'] ?? null;
        if (is_array($https)) {
            if (empty($https['uses_https'])) {
                $this->addIssue([
                    'crawled_page_id' => null,
                    'issue_type' => 'NO_HTTPS',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => AuditIssue::SEVERITY_CRITICAL,
                    'affected_url' => $this->homepageUrl,
                    'evidence' => ['uses_https' => false],
                    'explanation' => 'Website target URL is served over unencrypted HTTP instead of HTTPS. HTTPS is a confirmed Google ranking and trust signal.',
                    'recommended_fix' => 'Install an SSL/TLS certificate, migrate all URLs to HTTPS, and add a permanent 301 redirect from HTTP to HTTPS.',
                ]);
            } elseif ($https['http_redirects_to_https'] === false) {
                $this->addIssue([
                    'crawled_page_id' => null,
                    'issue_type' => 'HTTP_NOT_REDIRECTING_TO_HTTPS',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => str_replace('https://', 'http://', $this->homepageUrl),
                    'evidence' => ['http_status' => $https['http_status']],
                    'explanation' => 'The HTTP version of the domain does not redirect directly to HTTPS, creating protocol duplication and security risk.',
                    'recommended_fix' => 'Configure a server-level 301 redirect forcing all http:// traffic to https:// and enable HSTS.',
                ]);
            }
        }

        $www = $this->siteChecks['www_consistency'] ?? null;
        if (is_array($www) && $www['redirects_cleanly'] === false && ($www['alternate_status'] ?? null) === 200) {
            $this->addIssue([
                'crawled_page_id' => null,
                'issue_type' => 'WWW_DUPLICATE_HOST',
                'category' => AuditIssue::CATEGORY_TECHNICAL,
                'severity' => AuditIssue::SEVERITY_WARNING,
                'affected_url' => $this->homepageUrl,
                'evidence' => [
                    'target_host' => $www['target_host'],
                    'alternate_host' => $www['alternate_host'],
                    'alternate_status' => $www['alternate_status'],
                ],
                'explanation' => "Both {$www['target_host']} and {$www['alternate_host']} return HTTP 200 without redirecting, which splits link equity and causes duplicate indexing.",
                'recommended_fix' => "Configure a permanent 301 redirect from {$www['alternate_host']} to your preferred canonical host ({$www['target_host']}).",
            ]);
        }
    }

    /**
     * Check HTTP status codes (0, 4xx, 5xx) and broken internal links.
     */
    protected function checkHttpStatusCodesAndBrokenLinks(): void
    {
        $statusMap = [];
        foreach ($this->pages as $p) {
            $statusMap[$p->url] = $p->status_code;
            if ($p->final_url) {
                $statusMap[$p->final_url] = $p->status_code;
            }
        }

        foreach ($this->pages as $page) {
            if ($page->is_blocked_by_waf) {
                continue;
            }

            if ($page->status_code === 0) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'FETCH_ERROR',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => AuditIssue::SEVERITY_CRITICAL,
                    'affected_url' => $page->url,
                    'evidence' => ['error' => $page->error],
                    'explanation' => 'Crawler failed to retrieve this URL due to a connection timeout, SSL error, or unreachable host.',
                    'recommended_fix' => 'Verify DNS, SSL certificate validity, firewall settings, and server responsiveness for this URL.',
                ]);
                continue;
            }

            if ($page->status_code >= 400 && $page->status_code < 500) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'STATUS_4XX',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => AuditIssue::SEVERITY_CRITICAL,
                    'affected_url' => $page->url,
                    'evidence' => ['status_code' => $page->status_code],
                    'explanation' => "Page returned an HTTP {$page->status_code} client error.",
                    'recommended_fix' => 'Restore the missing page or implement a 301 redirect to the closest relevant live page, and update any internal links.',
                ]);
            }

            if ($page->status_code >= 500) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'STATUS_5XX',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => AuditIssue::SEVERITY_CRITICAL,
                    'affected_url' => $page->url,
                    'evidence' => ['status_code' => $page->status_code],
                    'explanation' => "Server returned an HTTP {$page->status_code} error, preventing search engines and users from accessing the page.",
                    'recommended_fix' => 'Inspect application error logs, PHP/Node runtime crashes, or database timeouts causing 5xx failures.',
                ]);
            }

            // Broken Internal Links
            $internal = $page->internal_links ?? [];
            foreach ($internal as $link) {
                $targetUrl = $link['url'] ?? null;
                if ($targetUrl && isset($statusMap[$targetUrl]) && ($statusMap[$targetUrl] >= 400 || $statusMap[$targetUrl] === 0)) {
                    $this->addIssue([
                        'crawled_page_id' => $page->id,
                        'issue_type' => 'BROKEN_INTERNAL_LINK',
                        'category' => AuditIssue::CATEGORY_LINKS,
                        'severity' => AuditIssue::SEVERITY_CRITICAL,
                        'affected_url' => $page->url,
                        'evidence' => [
                            'broken_url' => $targetUrl,
                            'target_status' => $statusMap[$targetUrl],
                            'anchor_text' => $link['text'] ?? '',
                        ],
                        'explanation' => "Contains an internal link pointing to a broken URL ({$targetUrl}) returning HTTP {$statusMap[$targetUrl]}.",
                        'recommended_fix' => 'Update the link href to a valid 200 OK destination or remove the dead hyperlink.',
                    ]);
                }
            }
        }
    }

    /**
     * Check redirect chains (2+ hops) and temporary redirects (302/307).
     */
    protected function checkRedirectChainsAndTempRedirects(): void
    {
        foreach ($this->pages as $page) {
            $hops = $page->redirect_hops ?? [];
            if (empty($hops)) {
                continue;
            }

            if (count($hops) >= 2) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'REDIRECT_CHAIN',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => ['hops' => $hops, 'hop_count' => count($hops), 'final_url' => $page->final_url],
                    'explanation' => 'URL triggers a redirect chain of ' . count($hops) . ' hops, adding latency and wasting crawl budget.',
                    'recommended_fix' => 'Update internal links to point directly to the final destination URL and collapse server redirects into a single 301 hop.',
                ]);
            } else {
                $firstStatus = $hops[0]['status'] ?? 301;
                if (in_array($firstStatus, [302, 303, 307], true)) {
                    $this->addIssue([
                        'crawled_page_id' => $page->id,
                        'issue_type' => 'TEMPORARY_REDIRECT',
                        'category' => AuditIssue::CATEGORY_TECHNICAL,
                        'severity' => AuditIssue::SEVERITY_INFO,
                        'affected_url' => $page->url,
                        'evidence' => ['status' => $firstStatus, 'redirect_to' => $page->final_url],
                        'explanation' => "URL uses a temporary HTTP {$firstStatus} redirect instead of a permanent 301 redirect.",
                        'recommended_fix' => 'If the move is permanent, switch to a 301 redirect so full link equity transfers to the destination URL.',
                    ]);
                }
            }
        }
    }

    /**
     * Check mixed content and missing HTTP security headers.
     */
    protected function checkTransportSecurityAndMixedContent(): void
    {
        $checkedSecurityHeaderSummary = false;

        foreach ($this->pages as $page) {
            if ($page->status_code !== 200 || $page->is_blocked_by_waf) {
                continue;
            }

            $mixed = $page->mixed_content_urls ?? [];
            if (!empty($mixed)) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'MIXED_CONTENT',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => ['insecure_urls' => $mixed, 'count' => count($mixed)],
                    'explanation' => 'HTTPS page references ' . count($mixed) . ' insecure http:// asset(s), triggering browser security warnings or blocked resources.',
                    'recommended_fix' => 'Update all embedded image, script, and stylesheet URLs to load over https://.',
                ]);
            }

            // Evaluate security headers once on the homepage (or first healthy 200 page) so we don't spam every URL
            if (!$checkedSecurityHeaderSummary && ($page->url === $this->homepageUrl || $page->depth === 0)) {
                $checkedSecurityHeaderSummary = true;
                $sec = $page->security_headers ?? [];
                $missing = [];
                if (($sec['https'] ?? false) && empty($sec['hsts'])) {
                    $missing[] = 'Strict-Transport-Security (HSTS)';
                }
                if (empty($sec['x_content_type_options'])) {
                    $missing[] = 'X-Content-Type-Options';
                }
                if (empty($sec['x_frame_options']) && empty($sec['csp'])) {
                    $missing[] = 'X-Frame-Options / Content-Security-Policy';
                }

                if (!empty($missing)) {
                    $this->addIssue([
                        'crawled_page_id' => $page->id,
                        'issue_type' => 'MISSING_SECURITY_HEADERS',
                        'category' => AuditIssue::CATEGORY_TECHNICAL,
                        'severity' => AuditIssue::SEVERITY_INFO,
                        'affected_url' => $page->url,
                        'evidence' => ['missing_headers' => $missing, 'detected' => $sec],
                        'explanation' => 'Recommended HTTP security headers are missing: ' . implode(', ', $missing) . '.',
                        'recommended_fix' => 'Add HSTS, X-Content-Type-Options: nosniff, and clickjacking protection headers in your web server or CDN configuration.',
                    ]);
                }
            }
        }
    }

    /**
     * Check mobile viewport, slow server response times, and oversized HTML payloads.
     */
    protected function checkMobileViewportAndPerformance(): void
    {
        foreach ($this->pages as $page) {
            if ($page->status_code !== 200 || $page->is_blocked_by_waf) {
                continue;
            }

            // Mobile Viewport
            if (empty($page->viewport) && $page->is_indexable) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'MISSING_VIEWPORT',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => ['viewport' => null],
                    'explanation' => 'Page is missing a <meta name="viewport"> tag, which hurts mobile usability under Google mobile-first indexing.',
                    'recommended_fix' => 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> inside the <head> section.',
                ]);
            }

            // Server Response / Load Time
            $ms = $page->ttfb_ms ?? $page->load_time_ms ?? 0;
            if ($ms > 3000) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'SLOW_PAGE_RESPONSE',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => ['response_time_ms' => $ms, 'threshold_ms' => 3000],
                    'explanation' => "Server response time was very slow ({$ms}ms), exceeding the 3,000ms performance threshold.",
                    'recommended_fix' => 'Enable full-page caching / CDN edge caching, optimize backend queries, and reduce server TTFB under 800ms.',
                ]);
            } elseif ($ms > 1500) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'SLOW_PAGE_RESPONSE',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => AuditIssue::SEVERITY_INFO,
                    'affected_url' => $page->url,
                    'evidence' => ['response_time_ms' => $ms, 'threshold_ms' => 1500],
                    'explanation' => "Server response time was elevated ({$ms}ms). Faster TTFB (< 800ms) improves Core Web Vitals and crawl efficiency.",
                    'recommended_fix' => 'Optimize server caching, compression (Brotli/Gzip), and CDN edge delivery.',
                ]);
            }

            // Large HTML Payload (> 500 KB)
            if (($page->html_size_bytes ?? 0) > 512000) {
                $kb = round($page->html_size_bytes / 1024, 1);
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'LARGE_HTML_SIZE',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => ['html_size_bytes' => $page->html_size_bytes, 'size_kb' => $kb],
                    'explanation' => "HTML document size is {$kb} KB, exceeding 500 KB which slows parsing and mobile rendering.",
                    'recommended_fix' => 'Externalize large inline CSS/JS/SVG blobs, paginate long lists, and enable Brotli/Gzip compression.',
                ]);
            }
        }
    }

    /**
     * Report honest WAF/anti-bot blocking and Client-Side SPA rendering diagnostics.
     */
    protected function checkSpaAndWafDiagnostics(): void
    {
        $spaReported = false;

        foreach ($this->pages as $page) {
            if ($page->is_blocked_by_waf) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'WAF_OR_BOT_BLOCKED',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => [
                        'status_code' => $page->status_code,
                        'challenge_title' => $page->page_title,
                    ],
                    'explanation' => 'Target server returned an anti-bot / WAF challenge interstitial instead of page HTML. Content audit rules were skipped for this URL to avoid false positives.',
                    'recommended_fix' => 'Allowlist WebSeoAi-AuditBot (or your audit IP) in your CDN/WAF firewall rules so full technical and on-page SEO audits can inspect the real HTML.',
                ]);
                continue;
            }

            if ($page->is_spa_shell && !$spaReported) {
                $spaReported = true;
                $wasHydrated = (bool) $page->is_js_rendered;
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'SPA_CLIENT_RENDERED',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => $wasHydrated ? AuditIssue::SEVERITY_INFO : AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => [
                        'js_rendered_by_auditor' => $wasHydrated,
                        'words_after_render' => $page->word_count,
                    ],
                    'explanation' => $wasHydrated
                        ? 'Website serves an empty client-side JavaScript SPA shell initially. Our Web Rendering Service executed JavaScript to audit the hydrated DOM, but non-JS crawlers (social previews, AI bots, some search engines) will see an empty body.'
                        : 'Website serves a client-side JavaScript SPA shell with minimal initial HTML, preventing non-JS crawlers from reading content or discovering internal links.',
                    'recommended_fix' => 'Implement Server-Side Rendering (SSR) or Static Site Generation (SSG / pre-rendering) so search engines and social scrapers receive complete HTML immediately.',
                ]);
            }
        }
    }

    /**
     * Check Title Tags: missing, duplicate, too short, too long.
     */
    protected function checkTitleTags(): void
    {
        $titleCounts = [];
        foreach ($this->pages as $p) {
            if ($p->status_code === 200 && $p->is_indexable && !$p->is_blocked_by_waf && !empty($p->page_title)) {
                $titleCounts[$p->page_title] = ($titleCounts[$p->page_title] ?? 0) + 1;
            }
        }

        foreach ($this->pages as $page) {
            if ($page->status_code !== 200 || !$page->is_indexable || $page->is_blocked_by_waf) {
                continue;
            }

            if (empty($page->page_title)) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'MISSING_TITLE',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_CRITICAL,
                    'affected_url' => $page->url,
                    'evidence' => ['title' => null],
                    'explanation' => 'Page is missing a <title> tag, which is one of the strongest on-page ranking and SERP click-through signals.',
                    'recommended_fix' => 'Add a unique, keyword-focused <title> tag between 40 and 60 characters.',
                ]);
                continue;
            }

            if (($titleCounts[$page->page_title] ?? 0) > 1) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'DUPLICATE_TITLE',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => ['title' => $page->page_title, 'duplicates_count' => $titleCounts[$page->page_title]],
                    'explanation' => "Identical <title> tag is shared across {$titleCounts[$page->page_title]} indexable pages.",
                    'recommended_fix' => 'Write a distinct, descriptive title tag for each page to prevent keyword cannibalization.',
                ]);
            }

            $len = mb_strlen($page->page_title);
            if ($len < 30) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'TITLE_TOO_SHORT',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_INFO,
                    'affected_url' => $page->url,
                    'evidence' => ['title' => $page->page_title, 'character_length' => $len],
                    'explanation' => "Title is short ({$len} characters) and underutilizes available SERP headline space.",
                    'recommended_fix' => 'Expand the title to 40–60 characters with primary intent keywords and brand positioning.',
                ]);
            } elseif ($len > 65) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'TITLE_TOO_LONG',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_INFO,
                    'affected_url' => $page->url,
                    'evidence' => ['title' => $page->page_title, 'character_length' => $len],
                    'explanation' => "Title is {$len} characters long and will likely be truncated on Google search results.",
                    'recommended_fix' => 'Trim the title to under 60 characters while keeping the core keyword near the beginning.',
                ]);
            }
        }
    }

    /**
     * Check Meta Descriptions: missing, duplicate, too short, too long.
     */
    protected function checkMetaDescriptions(): void
    {
        $descCounts = [];
        foreach ($this->pages as $p) {
            if ($p->status_code === 200 && $p->is_indexable && !$p->is_blocked_by_waf && !empty($p->meta_description)) {
                $descCounts[$p->meta_description] = ($descCounts[$p->meta_description] ?? 0) + 1;
            }
        }

        foreach ($this->pages as $page) {
            if ($page->status_code !== 200 || !$page->is_indexable || $page->is_blocked_by_waf) {
                continue;
            }

            if (empty($page->meta_description)) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'MISSING_META_DESCRIPTION',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => ['meta_description' => null],
                    'explanation' => 'Page is missing a <meta name="description"> tag, leaving search engines to auto-generate snippets.',
                    'recommended_fix' => 'Add a compelling, unique meta description between 120 and 155 characters with a clear value proposition.',
                ]);
                continue;
            }

            if (($descCounts[$page->meta_description] ?? 0) > 1) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'DUPLICATE_META_DESCRIPTION',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => [
                        'meta_description' => mb_substr($page->meta_description, 0, 160),
                        'duplicates_count' => $descCounts[$page->meta_description],
                    ],
                    'explanation' => "Identical meta description is reused across {$descCounts[$page->meta_description]} indexable pages.",
                    'recommended_fix' => 'Write a unique summary tailored to the specific topic and user intent of each page.',
                ]);
            }

            $len = mb_strlen($page->meta_description);
            if ($len < 70) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'META_DESCRIPTION_TOO_SHORT',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_INFO,
                    'affected_url' => $page->url,
                    'evidence' => ['character_length' => $len, 'meta_description' => $page->meta_description],
                    'explanation' => "Meta description is short ({$len} characters), missing space to communicate benefits and increase CTR.",
                    'recommended_fix' => 'Expand the meta description to 120–155 characters summarizing the page value and CTA.',
                ]);
            } elseif ($len > 165) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'META_DESCRIPTION_TOO_LONG',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_INFO,
                    'affected_url' => $page->url,
                    'evidence' => ['character_length' => $len, 'meta_description' => mb_substr($page->meta_description, 0, 180)],
                    'explanation' => "Meta description is {$len} characters and will likely be truncated on desktop and mobile SERPs.",
                    'recommended_fix' => 'Condense the meta description to 120–155 characters so the full message stays visible.',
                ]);
            }
        }
    }

    /**
     * Check Heading Structure & H1 Tags.
     */
    protected function checkHeadingHierarchy(): void
    {
        foreach ($this->pages as $page) {
            if ($page->status_code !== 200 || !$page->is_indexable || $page->is_blocked_by_waf) {
                continue;
            }
            if ($page->is_spa_shell && !$page->is_js_rendered) {
                continue;
            }

            $h1Tags = $page->h1_tags ?? [];
            $h1Count = count($h1Tags);

            if ($h1Count === 0) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'MISSING_H1',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_CRITICAL,
                    'affected_url' => $page->url,
                    'evidence' => ['h1_count' => 0],
                    'explanation' => 'Page has no primary <h1> heading tag to establish topical hierarchy.',
                    'recommended_fix' => 'Add a single, clear <h1> heading matching the main search intent of the page.',
                ]);
            } elseif ($h1Count > 1) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'MULTIPLE_H1',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => ['h1_tags' => $h1Tags, 'count' => $h1Count],
                    'explanation' => "Page contains {$h1Count} <h1> headings. Best practice is a single primary H1 per document.",
                    'recommended_fix' => 'Keep one primary <h1> for the main page headline and change secondary headings to <h2>.',
                ]);
            }

            $tree = $page->headings_tree ?? [];
            $previousLevel = 0;
            $skippedFrom = null;
            $skippedTo = null;

            foreach ($tree as $heading) {
                $curr = $heading['level'] ?? 1;
                if ($previousLevel > 0 && ($curr - $previousLevel) > 1) {
                    $skippedFrom = "H{$previousLevel}";
                    $skippedTo = "H{$curr}";
                    break;
                }
                $previousLevel = $curr;
            }

            if ($skippedFrom !== null) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'HEADING_HIERARCHY_SKIPPED',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_INFO,
                    'affected_url' => $page->url,
                    'evidence' => ['skipped' => "{$skippedFrom} -> {$skippedTo}", 'headings_count' => count($tree)],
                    'explanation' => "Heading hierarchy skips levels ({$skippedFrom} directly to {$skippedTo}), weakening document accessibility and semantic outline.",
                    'recommended_fix' => 'Use sequential heading levels (H1 → H2 → H3) without skipping intermediate ranks.',
                ]);
            }
        }
    }

    /**
     * Check Thin Content (< 250 words) and Exact Duplicate Content across distinct pages.
     */
    protected function checkThinAndDuplicateContent(): void
    {
        $hashGroups = [];
        foreach ($this->pages as $p) {
            if ($p->status_code === 200 && $p->is_indexable && !$p->is_blocked_by_waf && !empty($p->content_hash)) {
                $hashGroups[$p->content_hash][] = $p->url;
            }
        }

        foreach ($this->pages as $page) {
            if ($page->status_code !== 200 || !$page->is_indexable || $page->is_blocked_by_waf) {
                continue;
            }
            if ($page->is_spa_shell && !$page->is_js_rendered) {
                continue;
            }

            // Thin content check (only flag if genuinely low visible copy)
            if ($page->word_count < 250) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'THIN_CONTENT',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_INFO,
                    'affected_url' => $page->url,
                    'evidence' => ['word_count' => $page->word_count, 'recommended_minimum' => 250],
                    'explanation' => "Page contains only {$page->word_count} visible words, which may be treated as thin content for competitive search queries.",
                    'recommended_fix' => 'Expand page copy with helpful, original answers, FAQs, or structured details covering user search intent.',
                ]);
            }

            // Exact Duplicate Content
            if (!empty($page->content_hash) && count($hashGroups[$page->content_hash] ?? []) > 1) {
                $duplicates = array_values(array_filter($hashGroups[$page->content_hash], fn($u) => $u !== $page->url));
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'DUPLICATE_CONTENT',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => [
                        'duplicate_urls' => array_slice($duplicates, 0, 5),
                        'total_matching_pages' => count($hashGroups[$page->content_hash]),
                    ],
                    'explanation' => 'Visible body content is identical to ' . count($duplicates) . ' other indexable URL(s) on this domain.',
                    'recommended_fix' => 'Consolidate duplicate URLs with a 301 redirect or rel="canonical" tag, or differentiate the page content.',
                ]);
            }
        }
    }

    /**
     * Check Image Alt Attributes (with concrete sample src URLs).
     */
    protected function checkImageAltAttributes(): void
    {
        foreach ($this->pages as $page) {
            if ($page->status_code !== 200 || $page->is_blocked_by_waf) {
                continue;
            }

            if ($page->images_missing_alt > 0) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'MISSING_IMAGE_ALT',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => [
                        'missing_alt_count' => $page->images_missing_alt,
                        'total_images' => $page->images_count,
                        'sample_image_urls' => $page->images_missing_alt_samples ?? [],
                    ],
                    'explanation' => "Page contains {$page->images_missing_alt} of {$page->images_count} image(s) missing descriptive alt text.",
                    'recommended_fix' => 'Add concise, descriptive alt attributes to informative images (or role="presentation" for purely decorative visuals).',
                ]);
            }
        }
    }

    /**
     * Check Open Graph social tags and Schema.org JSON-LD Structured Data.
     */
    protected function checkSocialAndStructuredData(): void
    {
        foreach ($this->pages as $page) {
            if ($page->status_code !== 200 || !$page->is_indexable || $page->is_blocked_by_waf) {
                continue;
            }

            // Invalid JSON-LD syntax
            $sd = $page->structured_data ?? [];
            if (($sd['invalid_blocks'] ?? 0) > 0) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'INVALID_STRUCTURED_DATA',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => $sd,
                    'explanation' => "Page contains {$sd['invalid_blocks']} malformed JSON-LD <script type=\"application/ld+json\"> block(s) that fail JSON parsing.",
                    'recommended_fix' => 'Validate and repair the JSON-LD syntax using Google Rich Results Test / Schema Markup Validator.',
                ]);
            } elseif (($sd['count'] ?? 0) === 0 && ($page->url === $this->homepageUrl || $page->depth === 0)) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'MISSING_STRUCTURED_DATA',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_INFO,
                    'affected_url' => $page->url,
                    'evidence' => ['json_ld_blocks' => 0],
                    'explanation' => 'Homepage has no Schema.org JSON-LD structured data (e.g. Organization, WebSite, LocalBusiness).',
                    'recommended_fix' => 'Add valid Organization / WebSite JSON-LD structured data to enhance rich results and entity recognition.',
                ]);
            }

            // Open Graph completeness on key pages (depth <= 1)
            if ($page->depth <= 1) {
                $og = $page->open_graph ?? [];
                $missingOg = [];
                if (empty($og['title'])) $missingOg[] = 'og:title';
                if (empty($og['description'])) $missingOg[] = 'og:description';
                if (empty($og['image'])) $missingOg[] = 'og:image';

                if (!empty($missingOg)) {
                    $this->addIssue([
                        'crawled_page_id' => $page->id,
                        'issue_type' => 'MISSING_OPEN_GRAPH',
                        'category' => AuditIssue::CATEGORY_CONTENT,
                        'severity' => AuditIssue::SEVERITY_INFO,
                        'affected_url' => $page->url,
                        'evidence' => ['missing_tags' => $missingOg],
                        'explanation' => 'Missing Open Graph social metadata (' . implode(', ', $missingOg) . '), reducing visual presentation when shared on social platforms.',
                        'recommended_fix' => 'Add og:title, og:description, and og:image (1200x630px) meta tags to key landing pages.',
                    ]);
                }
            }
        }
    }

    /**
     * Check HTML lang attribute for international SEO & accessibility.
     */
    protected function checkHtmlLanguage(): void
    {
        foreach ($this->pages as $page) {
            if ($page->status_code !== 200 || !$page->is_indexable || $page->is_blocked_by_waf) {
                continue;
            }

            if (empty($page->html_lang)) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'MISSING_HTML_LANG',
                    'category' => AuditIssue::CATEGORY_CONTENT,
                    'severity' => AuditIssue::SEVERITY_INFO,
                    'affected_url' => $page->url,
                    'evidence' => ['html_lang' => null],
                    'explanation' => 'The <html> element is missing a valid lang attribute (e.g. lang="en").',
                    'recommended_fix' => 'Declare the primary language on the root element, such as <html lang="en">.',
                ]);
            }
        }
    }

    /**
     * Check Canonical URL Consistency & Broken Canonicals.
     */
    protected function checkCanonicalTags(): void
    {
        $statusMap = $this->pages->pluck('status_code', 'url')->toArray();

        foreach ($this->pages as $page) {
            if ($page->status_code !== 200 || $page->is_blocked_by_waf) {
                continue;
            }

            $expectedSelf = $page->final_url ?: $page->url;

            if (empty($page->canonical_url)) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'MISSING_CANONICAL',
                    'category' => AuditIssue::CATEGORY_INDEXING,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => ['canonical_url' => null],
                    'explanation' => 'Page is missing a <link rel="canonical"> tag, increasing duplicate-URL risk from query parameters or trailing slashes.',
                    'recommended_fix' => "Add a self-referencing canonical tag: <link rel=\"canonical\" href=\"{$expectedSelf}\">.",
                ]);
            } else {
                if (isset($statusMap[$page->canonical_url]) && $statusMap[$page->canonical_url] >= 400) {
                    $this->addIssue([
                        'crawled_page_id' => $page->id,
                        'issue_type' => 'BROKEN_CANONICAL',
                        'category' => AuditIssue::CATEGORY_INDEXING,
                        'severity' => AuditIssue::SEVERITY_CRITICAL,
                        'affected_url' => $page->url,
                        'evidence' => [
                            'canonical_url' => $page->canonical_url,
                            'canonical_status' => $statusMap[$page->canonical_url],
                        ],
                        'explanation' => "Canonical tag points to a broken URL ({$page->canonical_url}) returning HTTP {$statusMap[$page->canonical_url]}.",
                        'recommended_fix' => 'Update the rel="canonical" href to a live HTTP 200 canonical URL.',
                    ]);
                } elseif ($page->canonical_url !== $page->url && $page->canonical_url !== $expectedSelf) {
                    $this->addIssue([
                        'crawled_page_id' => $page->id,
                        'issue_type' => 'CANONICAL_MISMATCH',
                        'category' => AuditIssue::CATEGORY_INDEXING,
                        'severity' => AuditIssue::SEVERITY_INFO,
                        'affected_url' => $page->url,
                        'evidence' => [
                            'page_url' => $page->url,
                            'canonical_url' => $page->canonical_url,
                        ],
                        'explanation' => "Canonical URL points to a different target: {$page->canonical_url}.",
                        'recommended_fix' => 'Confirm this canonical target is intentional; otherwise update it to self-reference this page.',
                    ]);
                }
            }
        }
    }

    /**
     * Check Indexing Directives (Noindex, Homepage Noindex, Noindex in Sitemap) and Meta Refresh.
     */
    protected function checkIndexingDirectivesAndMetaRefresh(): void
    {
        foreach ($this->pages as $page) {
            if ($page->is_blocked_by_waf) {
                continue;
            }

            if ($page->status_code === 200 && !$page->is_indexable) {
                $isHomepage = ($page->url === $this->homepageUrl || $page->depth === 0);

                if ($isHomepage) {
                    $this->addIssue([
                        'crawled_page_id' => $page->id,
                        'issue_type' => 'NOINDEX_PAGE',
                        'category' => AuditIssue::CATEGORY_INDEXING,
                        'severity' => AuditIssue::SEVERITY_CRITICAL,
                        'affected_url' => $page->url,
                        'evidence' => [
                            'meta_robots' => $page->meta_robots,
                            'x_robots_tag' => $page->x_robots_tag,
                            'is_homepage' => true,
                        ],
                        'explanation' => 'The homepage is marked as noindex / blocked from indexing, preventing search engines from indexing your primary domain entry point.',
                        'recommended_fix' => 'Remove the noindex directive from meta robots / X-Robots-Tag headers immediately.',
                    ]);
                } elseif ($page->in_sitemap) {
                    $this->addIssue([
                        'crawled_page_id' => $page->id,
                        'issue_type' => 'NOINDEX_IN_SITEMAP',
                        'category' => AuditIssue::CATEGORY_INDEXING,
                        'severity' => AuditIssue::SEVERITY_WARNING,
                        'affected_url' => $page->url,
                        'evidence' => [
                            'meta_robots' => $page->meta_robots,
                            'x_robots_tag' => $page->x_robots_tag,
                            'in_sitemap' => true,
                        ],
                        'explanation' => 'Page is listed in sitemap.xml while simultaneously declaring a noindex directive (conflicting indexing signals).',
                        'recommended_fix' => 'Either remove this URL from sitemap.xml or remove the noindex tag if it should rank organically.',
                    ]);
                } else {
                    $this->addIssue([
                        'crawled_page_id' => $page->id,
                        'issue_type' => 'NOINDEX_PAGE',
                        'category' => AuditIssue::CATEGORY_INDEXING,
                        'severity' => AuditIssue::SEVERITY_INFO,
                        'affected_url' => $page->url,
                        'evidence' => [
                            'meta_robots' => $page->meta_robots,
                            'x_robots_tag' => $page->x_robots_tag,
                        ],
                        'explanation' => 'Page carries a noindex directive and will not appear in search engine results.',
                        'recommended_fix' => 'Verify that excluding this page from search results is intentional.',
                    ]);
                }
            }

            if (!empty($page->meta_refresh)) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'META_REFRESH_REDIRECT',
                    'category' => AuditIssue::CATEGORY_INDEXING,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => ['meta_refresh' => $page->meta_refresh],
                    'explanation' => 'Page uses a <meta http-equiv="refresh"> tag, which harms UX and is discouraged compared to server-side HTTP 301 redirects.',
                    'recommended_fix' => 'Replace meta refresh redirects with proper server-side HTTP 301 redirects.',
                ]);
            }
        }
    }

    /**
     * Check Sitemap Coverage & Orphan Pages (accurately matching normalized homepage URL).
     */
    protected function checkSitemapAndOrphans(): void
    {
        $hasSitemap = (bool) ($this->siteChecks['sitemap']['found'] ?? $this->pages->where('in_sitemap', true)->isNotEmpty());

        foreach ($this->pages as $page) {
            if ($page->status_code !== 200 || !$page->is_indexable || $page->is_blocked_by_waf) {
                continue;
            }

            $isHomepage = ($page->url === $this->homepageUrl || $page->final_url === $this->homepageUrl || $page->depth === 0);

            if ($page->in_sitemap && $page->in_degree === 0 && !$isHomepage) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'ORPHAN_PAGE',
                    'category' => AuditIssue::CATEGORY_LINKS,
                    'severity' => AuditIssue::SEVERITY_WARNING,
                    'affected_url' => $page->url,
                    'evidence' => ['in_degree' => 0, 'in_sitemap' => true],
                    'explanation' => 'Page appears in sitemap.xml but receives zero internal links from any crawled page on the website.',
                    'recommended_fix' => 'Add contextual internal links from related hubs, navigation, or parent pages so users and crawlers can reach it.',
                ]);
            }

            // Only flag PAGE_NOT_IN_SITEMAP if the site actually has a sitemap (otherwise MISSING_SITEMAP already covers it)
            if ($hasSitemap && !$page->in_sitemap && !$isHomepage) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'PAGE_NOT_IN_SITEMAP',
                    'category' => AuditIssue::CATEGORY_INDEXING,
                    'severity' => AuditIssue::SEVERITY_INFO,
                    'affected_url' => $page->url,
                    'evidence' => ['in_sitemap' => false],
                    'explanation' => 'Indexable page discovered via internal links is missing from sitemap.xml.',
                    'recommended_fix' => 'Include this canonical URL in your XML sitemap for faster search discovery.',
                ]);
            }
        }
    }

    /**
     * Check deep crawl depth (> 3 clicks) and nofollow internal links.
     */
    protected function checkLinkArchitectureAndDepth(): void
    {
        foreach ($this->pages as $page) {
            if ($page->status_code !== 200 || $page->is_blocked_by_waf) {
                continue;
            }

            if ($page->depth > 3 && $page->is_indexable) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'DEEP_CRAWL_DEPTH',
                    'category' => AuditIssue::CATEGORY_LINKS,
                    'severity' => AuditIssue::SEVERITY_INFO,
                    'affected_url' => $page->url,
                    'evidence' => ['depth' => $page->depth, 'recommended_max_depth' => 3],
                    'explanation' => "Page sits {$page->depth} clicks deep from the homepage, reducing crawl frequency and internal PageRank.",
                    'recommended_fix' => 'Flatten site architecture by linking key pages from category hubs, breadcrumbs, or primary navigation within 3 clicks.',
                ]);
            }

            $nofollowInternals = array_values(array_filter(
                $page->internal_links ?? [],
                fn($l) => str_contains(strtolower($l['rel'] ?? ''), 'nofollow')
            ));

            if (!empty($nofollowInternals)) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'NOFOLLOW_INTERNAL_LINK',
                    'category' => AuditIssue::CATEGORY_LINKS,
                    'severity' => AuditIssue::SEVERITY_INFO,
                    'affected_url' => $page->url,
                    'evidence' => [
                        'count' => count($nofollowInternals),
                        'sample_urls' => array_slice(array_column($nofollowInternals, 'url'), 0, 5),
                    ],
                    'explanation' => 'Page contains ' . count($nofollowInternals) . ' internal link(s) with rel="nofollow", restricting internal authority flow.',
                    'recommended_fix' => 'Remove rel="nofollow" from internal navigation and content links.',
                ]);
            }
        }
    }

    /**
     * Check URL Patterns (uppercase characters).
     */
    protected function checkUrlPatterns(): void
    {
        foreach ($this->pages as $page) {
            $parsed = parse_url($page->url);
            $path = $parsed['path'] ?? '';

            if (preg_match('/[A-Z]/', $path)) {
                $this->addIssue([
                    'crawled_page_id' => $page->id,
                    'issue_type' => 'URL_UPPERCASE_CHARACTERS',
                    'category' => AuditIssue::CATEGORY_TECHNICAL,
                    'severity' => AuditIssue::SEVERITY_INFO,
                    'affected_url' => $page->url,
                    'evidence' => ['path' => $path],
                    'explanation' => 'URL path contains uppercase letters, which can cause duplicate URLs on case-sensitive servers.',
                    'recommended_fix' => 'Standardize all internal URLs to lowercase and 301-redirect uppercase variants.',
                ]);
            }
        }
    }

    /**
     * Compile verified Passed Checks so users see a complete, honest audit picture.
     */
    protected function buildPassedChecks(): void
    {
        $issueTypes = array_flip(array_column($this->issues, 'issue_type'));
        $healthyPages = $this->pages->filter(fn(CrawledPage $p) => $p->status_code === 200 && !$p->is_blocked_by_waf);
        $totalHealthy = $healthyPages->count();

        // 1. HTTPS
        if (!isset($issueTypes['NO_HTTPS']) && str_starts_with(strtolower($this->homepageUrl), 'https://')) {
            $this->passedChecks[] = [
                'code' => 'HTTPS_ENABLED',
                'category' => AuditIssue::CATEGORY_TECHNICAL,
                'label' => 'HTTPS Encryption Active',
                'detail' => 'Website is served securely over HTTPS.',
            ];
        }

        // 2. Zero 5xx errors
        if (!isset($issueTypes['STATUS_5XX']) && $this->pages->isNotEmpty()) {
            $this->passedChecks[] = [
                'code' => 'NO_5XX_ERRORS',
                'category' => AuditIssue::CATEGORY_TECHNICAL,
                'label' => 'Zero 5xx Server Errors',
                'detail' => "All {$this->pages->count()} crawled URL(s) responded without server crashes.",
            ];
        }

        // 3. Zero 4xx & broken links
        if (!isset($issueTypes['STATUS_4XX']) && !isset($issueTypes['BROKEN_INTERNAL_LINK']) && $this->pages->isNotEmpty()) {
            $this->passedChecks[] = [
                'code' => 'NO_BROKEN_LINKS',
                'category' => AuditIssue::CATEGORY_LINKS,
                'label' => 'No Broken Internal Links or 4xx Pages',
                'detail' => 'Every crawled internal link resolved without 404/4xx errors.',
            ];
        }

        // 4. robots.txt valid
        if (!empty($this->siteChecks['robots_txt']['exists']) && empty($this->siteChecks['robots_txt']['blocks_all'])) {
            $this->passedChecks[] = [
                'code' => 'ROBOTS_TXT_VALID',
                'category' => AuditIssue::CATEGORY_INDEXING,
                'label' => 'Valid robots.txt Present',
                'detail' => 'robots.txt is accessible and allows search engine crawling.',
            ];
        }

        // 5. XML Sitemap found
        if (!empty($this->siteChecks['sitemap']['found'])) {
            $urlCount = $this->siteChecks['sitemap']['urls_count'] ?? 0;
            $this->passedChecks[] = [
                'code' => 'XML_SITEMAP_VALID',
                'category' => AuditIssue::CATEGORY_INDEXING,
                'label' => 'XML Sitemap Discovered',
                'detail' => "Valid XML sitemap parsed with {$urlCount} internal URL(s).",
            ];
        }

        // 6. Mobile Viewport
        if (!isset($issueTypes['MISSING_VIEWPORT']) && $totalHealthy > 0) {
            $this->passedChecks[] = [
                'code' => 'MOBILE_VIEWPORT_OK',
                'category' => AuditIssue::CATEGORY_TECHNICAL,
                'label' => 'Mobile Viewport Configured',
                'detail' => 'All indexable pages declare a responsive mobile viewport tag.',
            ];
        }

        // 7. Titles
        if (!isset($issueTypes['MISSING_TITLE']) && !isset($issueTypes['DUPLICATE_TITLE']) && $totalHealthy > 0) {
            $this->passedChecks[] = [
                'code' => 'TITLES_UNIQUE',
                'category' => AuditIssue::CATEGORY_CONTENT,
                'label' => 'Unique Page Titles',
                'detail' => 'All indexable pages have unique, non-empty <title> tags.',
            ];
        }

        // 8. H1 tags
        if (!isset($issueTypes['MISSING_H1']) && !isset($issueTypes['MULTIPLE_H1']) && $totalHealthy > 0) {
            $this->passedChecks[] = [
                'code' => 'H1_STRUCTURE_CLEAN',
                'category' => AuditIssue::CATEGORY_CONTENT,
                'label' => 'Clean Single H1 Structure',
                'detail' => 'Every indexable page uses a single primary <h1> heading.',
            ];
        }

        // 9. Structured Data
        $sdTypes = [];
        foreach ($healthyPages as $p) {
            foreach (($p->structured_data['types'] ?? []) as $t) {
                $sdTypes[] = $t;
            }
        }
        $sdTypes = array_values(array_unique($sdTypes));
        if (!empty($sdTypes) && !isset($issueTypes['INVALID_STRUCTURED_DATA'])) {
            $this->passedChecks[] = [
                'code' => 'STRUCTURED_DATA_VALID',
                'category' => AuditIssue::CATEGORY_CONTENT,
                'label' => 'Valid Schema.org JSON-LD Detected',
                'detail' => 'Detected schema types: ' . implode(', ', array_slice($sdTypes, 0, 6)),
            ];
        }

        // 10. No mixed content
        if (!isset($issueTypes['MIXED_CONTENT']) && $totalHealthy > 0) {
            $this->passedChecks[] = [
                'code' => 'NO_MIXED_CONTENT',
                'category' => AuditIssue::CATEGORY_TECHNICAL,
                'label' => 'Zero Mixed Content Assets',
                'detail' => 'No insecure http:// assets detected on HTTPS pages.',
            ];
        }
    }

    /**
     * Group issues by issue_type for executive reporting.
     */
    protected function buildIssueGroups(): array
    {
        $groups = [];
        foreach ($this->issues as $issue) {
            $type = $issue['issue_type'];
            if (!isset($groups[$type])) {
                $groups[$type] = [
                    'issue_type' => $type,
                    'category' => $issue['category'],
                    'severity' => $issue['severity'],
                    'count' => 0,
                    'sample_explanation' => $issue['explanation'],
                ];
            }
            $groups[$type]['count']++;
        }

        $severityRank = [
            AuditIssue::SEVERITY_CRITICAL => 1,
            AuditIssue::SEVERITY_WARNING => 2,
            AuditIssue::SEVERITY_INFO => 3,
        ];

        usort($groups, function ($a, $b) use ($severityRank) {
            $cmp = ($severityRank[$a['severity']] ?? 9) <=> ($severityRank[$b['severity']] ?? 9);
            return $cmp !== 0 ? $cmp : ($b['count'] <=> $a['count']);
        });

        return array_values($groups);
    }

    /**
     * Compute mathematically transparent 4-pillar scores and weighted overall score.
     *
     * Each distinct rule deducts based on severity + affected page ratio (with diminishing cap per rule),
     * so a single minor notice never destroys the score while genuine critical defects meaningfully weigh it down.
     */
    protected function calculateScores(): array
    {
        $totalPages = max(1, $this->pages->count());

        $technicalScore = $this->scoreCategory(AuditIssue::CATEGORY_TECHNICAL, $totalPages);
        $contentScore = $this->scoreCategory(AuditIssue::CATEGORY_CONTENT, $totalPages);
        $indexingScore = $this->scoreCategory(AuditIssue::CATEGORY_INDEXING, $totalPages);
        $linkScore = $this->scoreCategory(AuditIssue::CATEGORY_LINKS, $totalPages);

        // Weighted composite: Technical 30%, Content 30%, Indexing 20%, Links 20%
        $overall = (int) round(
            ($technicalScore * 0.30)
            + ($contentScore * 0.30)
            + ($indexingScore * 0.20)
            + ($linkScore * 0.20)
        );

        return [
            'overall' => max(0, min(100, $overall)),
            'technical' => $technicalScore,
            'content' => $contentScore,
            'indexing' => $indexingScore,
            'link' => $linkScore,
        ];
    }

    protected function scoreCategory(string $category, int $totalPages): int
    {
        $catIssues = array_filter($this->issues, fn($i) => $i['category'] === $category);
        if (empty($catIssues)) {
            return 100;
        }

        // Group by issue_type so each rule contributes a fair bounded deduction scaled by prevalence
        $byType = [];
        foreach ($catIssues as $issue) {
            $type = $issue['issue_type'];
            if (!isset($byType[$type])) {
                $byType[$type] = [
                    'severity' => $issue['severity'],
                    'count' => 0,
                ];
            }
            $byType[$type]['count']++;
        }

        $totalPenalty = 0.0;
        foreach ($byType as $info) {
            $ratio = min(1.0, $info['count'] / $totalPages);
            // Base impact + prevalence impact:
            // Critical rule: 12 to 28 pts
            // Warning rule:   5 to 14 pts
            // Info rule:      1.5 to 5 pts
            $penalty = match ($info['severity']) {
                AuditIssue::SEVERITY_CRITICAL => 12.0 + (16.0 * $ratio),
                AuditIssue::SEVERITY_WARNING => 5.0 + (9.0 * $ratio),
                default => 1.5 + (3.5 * $ratio),
            };
            $totalPenalty += $penalty;
        }

        return (int) max(0, min(100, round(100 - $totalPenalty)));
    }

    protected function addIssue(array $data): void
    {
        $this->issues[] = array_merge([
            'current_status' => AuditIssue::STATUS_OPEN,
            'verification_status' => 'verified',
            'is_fully_determined' => true,
        ], $data);
    }
}
