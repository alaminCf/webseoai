<?php

namespace App\Domain\Crawler;

use App\Domain\Auditing\AuditRulesEngine;
use App\Models\CrawledPage;
use App\Models\CrawlJob;
use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Throwable;

class CrawlEngine
{
    protected CrawlJob $job;
    protected Website $website;
    protected array $visitedUrls = [];
    protected array $queuedUrls = [];
    protected array $sitemapUrls = [];
    protected RobotsTxtParser $robots;
    protected bool $allowLocalhost = false;
    protected string $renderJsMode = 'auto'; // auto | always | never
    protected array $siteChecks = [];
    protected bool $isHttpFaked = false;

    private const USER_AGENT = 'Mozilla/5.0 (compatible; WebSeoAi-AuditBot/2.0; +https://techboloy.com/seo-audit)';

    public function __construct(CrawlJob $job, bool $allowLocalhost = false)
    {
        $this->job = $job;
        $this->website = $job->website;
        $this->allowLocalhost = $allowLocalhost;
        $this->renderJsMode = $job->config['render_js'] ?? 'auto';
        $this->isHttpFaked = app()->runningUnitTests();
    }

    /**
     * Execute the full professional technical SEO crawl & audit pipeline.
     */
    public function run(): void
    {
        $this->job->update([
            'status' => CrawlJob::STATUS_CRAWLING,
            'started_at' => now(),
            'pages_discovered' => 0,
            'pages_crawled' => 0,
            'pages_failed' => 0,
            'error_message' => null,
        ]);

        $this->job->logStep('Crawl run initiated', [
            'target' => $this->website->target_url,
            'max_pages' => $this->job->max_pages,
            'max_depth' => $this->job->max_depth,
            'render_js' => $this->renderJsMode,
        ]);

        try {
            $rootDomain = $this->website->domain;
            $homepageUrl = UrlNormalizer::canonicalHomepageUrl($this->website->target_url);
            $origin = rtrim($homepageUrl, '/');

            // 1. Site-level foundation checks (robots.txt, recursive sitemaps, HTTPS & www consistency)
            $this->inspectRobotsTxt($origin);
            $this->inspectSitemaps($origin);
            $this->inspectTransportAndHostConsistency($homepageUrl);

            $this->siteChecks['js_rendering_mode'] = $this->renderJsMode;
            $this->siteChecks['headless_available'] = !$this->isHttpFaked && HeadlessRenderer::isAvailable();
            $this->siteChecks['spa_detected'] = false;
            $this->siteChecks['js_rendered_pages'] = 0;
            $this->siteChecks['waf_blocked_pages'] = 0;

            $this->job->update(['site_checks' => $this->siteChecks]);

            // 2. Initialize deduplicated BFS crawl queue: [url, depth]
            $queue = [];
            $this->enqueueUrl($queue, $homepageUrl, 0);

            // Seed crawlable HTML URLs from sitemap at depth 1
            foreach ($this->sitemapUrls as $sitemapUrl) {
                if ($sitemapUrl !== $homepageUrl && UrlNormalizer::isCrawlableHtmlUrl($sitemapUrl)) {
                    $this->enqueueUrl($queue, $sitemapUrl, 1);
                }
            }

            $this->job->update(['pages_discovered' => count($this->queuedUrls)]);
            $this->job->logStep('Discovery queue initialized', [
                'robots_found' => $this->siteChecks['robots_txt']['exists'] ?? false,
                'sitemap_urls_found' => count($this->sitemapUrls),
                'initial_queue_size' => count($queue),
            ]);

            $crawledCount = 0;
            $failedCount = 0;
            $maxPages = $this->job->max_pages;
            $maxDepth = $this->job->max_depth;
            $wafConsecutiveCount = 0;

            // 3. Breadth-First Crawl Loop
            while (!empty($queue) && $crawledCount < $maxPages) {
                // Honor live cancellation requests
                if ($this->wasCancelled()) {
                    $this->job->logStep('Crawl stopped cleanly due to user cancellation', [
                        'crawled_so_far' => $crawledCount,
                    ]);
                    return;
                }

                $item = array_shift($queue);
                $rawUrl = $item['url'];
                $depth = $item['depth'];

                $normalizedUrl = UrlNormalizer::normalize($rawUrl, $homepageUrl);
                if (!$normalizedUrl || isset($this->visitedUrls[$normalizedUrl])) {
                    continue;
                }

                if (!UrlNormalizer::isCrawlableHtmlUrl($normalizedUrl)) {
                    continue;
                }

                $this->visitedUrls[$normalizedUrl] = true;

                // Robots.txt enforcement
                if (!$this->robots->isAllowed($normalizedUrl)) {
                    CrawledPage::create([
                        'crawl_job_id' => $this->job->id,
                        'website_id' => $this->website->id,
                        'url' => $normalizedUrl,
                        'final_url' => $normalizedUrl,
                        'depth' => $depth,
                        'status_code' => 200,
                        'is_indexable' => false,
                        'in_sitemap' => in_array($normalizedUrl, $this->sitemapUrls, true),
                        'error' => 'Blocked by robots.txt directive',
                    ]);
                    $crawledCount++;
                    continue;
                }

                // SSRF defense
                try {
                    UrlSecurityValidator::validate($normalizedUrl, $rootDomain, $this->allowLocalhost);
                } catch (Throwable $e) {
                    $this->job->logStep('SSRF guard blocked URL', [
                        'url' => $normalizedUrl,
                        'reason' => $e->getMessage(),
                    ]);
                    $failedCount++;
                    continue;
                }

                // Fetch & inspect page (with redirect tracking + smart SPA JS rendering)
                $pageResult = $this->fetchAndInspectPage($normalizedUrl, $homepageUrl, $rootDomain, $depth);

                if ($pageResult['success']) {
                    $crawledCount++;
                    $pageData = $pageResult['data'];

                    if (!empty($pageData['is_spa_shell'])) {
                        $this->siteChecks['spa_detected'] = true;
                    }
                    if (!empty($pageData['is_js_rendered'])) {
                        $this->siteChecks['js_rendered_pages'] = ($this->siteChecks['js_rendered_pages'] ?? 0) + 1;
                    }
                    if (!empty($pageData['is_blocked_by_waf'])) {
                        $this->siteChecks['waf_blocked_pages'] = ($this->siteChecks['waf_blocked_pages'] ?? 0) + 1;
                        $wafConsecutiveCount++;
                    } else {
                        $wafConsecutiveCount = 0;
                    }

                    // If 3 consecutive pages hit a WAF/bot challenge, stop politely instead of hammering the WAF
                    if ($wafConsecutiveCount >= 3) {
                        $this->job->logStep('WAF / Anti-bot protection detected — stopping crawl politely', [
                            'waf_blocked_pages' => $this->siteChecks['waf_blocked_pages'],
                        ]);
                        break;
                    }

                    // Enqueue newly discovered internal links within depth limit
                    if ($depth + 1 <= $maxDepth && !empty($pageData['internal_links'])) {
                        foreach ($pageData['internal_links'] as $link) {
                            $target = $link['url'] ?? null;
                            if ($target && UrlNormalizer::isCrawlableHtmlUrl($target)) {
                                $this->enqueueUrl($queue, $target, $depth + 1);
                            }
                        }
                    }
                } else {
                    $failedCount++;
                }

                // Persist real-time progress
                $this->job->update([
                    'pages_crawled' => $crawledCount,
                    'pages_failed' => $failedCount,
                    'pages_discovered' => count($this->queuedUrls),
                    'site_checks' => $this->siteChecks,
                ]);

                // Polite rate-limit pause (honors robots.txt crawl-delay capped at 2s)
                if (!$this->isHttpFaked) {
                    $delayMs = min(2000, max(50, ($this->robots->getCrawlDelay() ?? 0) * 1000));
                    usleep($delayMs * 1000);
                }
            }

            // 4. Compute internal link graph in-degree
            $this->calculateInDegrees();

            $this->job->update([
                'status' => CrawlJob::STATUS_COMPLETED,
                'pages_crawled' => $crawledCount,
                'pages_failed' => $failedCount,
                'pages_discovered' => count($this->queuedUrls),
                'site_checks' => $this->siteChecks,
                'finished_at' => now(),
            ]);

            $this->website->update([
                'last_crawled_at' => now(),
                'connection_status' => Website::STATUS_CONNECTED,
            ]);

            $this->job->logStep('Crawl completed — running SEO audit rules engine', [
                'total_crawled' => $crawledCount,
                'total_failed' => $failedCount,
                'js_rendered_pages' => $this->siteChecks['js_rendered_pages'] ?? 0,
                'waf_blocked_pages' => $this->siteChecks['waf_blocked_pages'] ?? 0,
            ]);

            // 5. Evaluate comprehensive deterministic SEO audit rules
            $rulesEngine = new AuditRulesEngine($this->job->fresh());
            $auditRun = $rulesEngine->evaluate();

            $this->job->logStep('Audit evaluation completed', [
                'audit_run_id' => $auditRun->id,
                'overall_score' => $auditRun->overall_score,
                'critical' => $auditRun->critical_count,
                'warnings' => $auditRun->warning_count,
                'notices' => $auditRun->info_count,
            ]);
        } catch (Throwable $e) {
            $this->job->update([
                'status' => CrawlJob::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
            $this->job->logStep('Crawl failed', ['exception' => $e->getMessage()]);
        }
    }

    protected function enqueueUrl(array &$queue, string $url, int $depth): void
    {
        if (isset($this->visitedUrls[$url]) || isset($this->queuedUrls[$url])) {
            return;
        }

        $this->queuedUrls[$url] = true;
        $queue[] = ['url' => $url, 'depth' => $depth];
    }

    protected function wasCancelled(): bool
    {
        $freshStatus = CrawlJob::where('id', $this->job->id)->value('status');
        return $freshStatus === CrawlJob::STATUS_CANCELLED;
    }

    /**
     * Inspect and parse robots.txt.
     */
    protected function inspectRobotsTxt(string $origin): void
    {
        $robotsUrl = "{$origin}/robots.txt";
        $content = '';
        $statusCode = null;
        $exists = false;

        try {
            UrlSecurityValidator::validate($robotsUrl, $this->website->domain, $this->allowLocalhost);
            $res = Http::timeout(8)
                ->withHeaders($this->defaultRequestHeaders())
                ->get($robotsUrl);

            $statusCode = $res->status();
            if ($res->successful()) {
                $body = $res->body();
                // Ensure it isn't an HTML 404 fallback page served with HTTP 200
                if (stripos($body, '<html') === false && stripos($body, '<!doctype html') === false) {
                    $content = $body;
                    $exists = trim($body) !== '';
                }
            }
        } catch (Throwable) {
            // robots.txt unreachable
        }

        $this->robots = new RobotsTxtParser($content);

        $this->siteChecks['robots_txt'] = [
            'url' => $robotsUrl,
            'exists' => $exists,
            'status_code' => $statusCode,
            'blocks_all' => $exists && $this->robots->blocksAll(),
            'crawl_delay' => $this->robots->getCrawlDelay(),
            'sitemaps_declared' => $this->robots->getSitemaps(),
            'disallow_count' => count($this->robots->getDisallowRules()),
        ];
    }

    /**
     * Recursively discover and parse XML sitemaps (including sitemap indexes & WordPress/Shopify sitemaps).
     */
    protected function inspectSitemaps(string $origin): void
    {
        $candidates = $this->robots->getSitemaps();
        if (empty($candidates)) {
            $candidates = [
                "{$origin}/sitemap.xml",
                "{$origin}/sitemap_index.xml",
                "{$origin}/wp-sitemap.xml",
            ];
        }

        $checked = [];
        $childSitemapsProcessed = 0;
        $maxChildSitemaps = 10;
        $maxSitemapUrls = 1500;
        $foundAnySitemap = false;
        $queue = $candidates;

        while (!empty($queue) && count($this->sitemapUrls) < $maxSitemapUrls) {
            $sitemapUrl = array_shift($queue);
            if (isset($checked[$sitemapUrl])) {
                continue;
            }
            $checked[$sitemapUrl] = true;

            try {
                UrlSecurityValidator::validate($sitemapUrl, $this->website->domain, $this->allowLocalhost);
                $res = Http::timeout(10)
                    ->withHeaders($this->defaultRequestHeaders('application/xml,text/xml;q=0.9,*/*;q=0.8'))
                    ->get($sitemapUrl);

                if (!$res->successful()) {
                    continue;
                }

                $parsed = SitemapParser::parseDetailed($res->body());

                if (!empty($parsed['urls']) || !empty($parsed['child_sitemaps'])) {
                    $foundAnySitemap = true;
                }

                // Recursively enqueue child sitemaps from sitemap indexes
                foreach ($parsed['child_sitemaps'] as $childUrl) {
                    if ($childSitemapsProcessed < $maxChildSitemaps && !isset($checked[$childUrl])) {
                        $queue[] = $childUrl;
                        $childSitemapsProcessed++;
                    }
                }

                foreach ($parsed['urls'] as $u) {
                    $normalized = UrlNormalizer::normalize($u, "{$origin}/");
                    if ($normalized && UrlNormalizer::isInternal($normalized, $this->website->domain)) {
                        $this->sitemapUrls[] = $normalized;
                    }
                }

                // If we already found a valid sitemap via fallback probing, stop trying extra fallback paths
                if ($foundAnySitemap && empty($this->robots->getSitemaps()) && empty($parsed['child_sitemaps'])) {
                    break;
                }
            } catch (Throwable) {
                // Ignore individual unreachable sitemap candidate
            }
        }

        $this->sitemapUrls = array_values(array_unique($this->sitemapUrls));

        $this->siteChecks['sitemap'] = [
            'found' => $foundAnySitemap,
            'urls_count' => count($this->sitemapUrls),
            'sitemaps_checked' => array_keys($checked),
            'nested_sitemaps_count' => $childSitemapsProcessed,
        ];
    }

    /**
     * Verify HTTPS enforcement (http -> https redirect) and www vs apex host consistency.
     */
    protected function inspectTransportAndHostConsistency(string $homepageUrl): void
    {
        $parts = parse_url($homepageUrl);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host'] ?? $this->website->domain);
        $usesHttps = $scheme === 'https';

        $httpCheck = [
            'uses_https' => $usesHttps,
            'http_redirects_to_https' => null,
            'http_status' => null,
        ];

        $wwwCheck = [
            'target_host' => $host,
            'alternate_host' => str_starts_with($host, 'www.') ? substr($host, 4) : "www.{$host}",
            'redirects_cleanly' => null,
            'alternate_status' => null,
        ];

        // Skip external probing during PHPUnit Http::fake() unless explicitly needed
        if (!$this->isHttpFaked) {
            if ($usesHttps) {
                try {
                    $httpUrl = "http://{$host}/";
                    UrlSecurityValidator::validate($httpUrl, $this->website->domain, $this->allowLocalhost);
                    $res = Http::timeout(6)
                        ->withHeaders($this->defaultRequestHeaders())
                        ->withOptions(['allow_redirects' => false])
                        ->get($httpUrl);

                    $httpCheck['http_status'] = $res->status();
                    $location = $res->header('Location');
                    $httpCheck['http_redirects_to_https'] = ($res->status() >= 300 && $res->status() < 400)
                        && is_string($location)
                        && str_starts_with(strtolower($location), 'https://');
                } catch (Throwable) {
                    $httpCheck['http_redirects_to_https'] = null;
                }
            }

            // Check alternate host (www <-> apex)
            try {
                $altUrl = "{$scheme}://{$wwwCheck['alternate_host']}/";
                UrlSecurityValidator::validate($altUrl, $this->website->domain, $this->allowLocalhost);
                $res = Http::timeout(6)
                    ->withHeaders($this->defaultRequestHeaders())
                    ->withOptions(['allow_redirects' => false])
                    ->get($altUrl);

                $wwwCheck['alternate_status'] = $res->status();
                if ($res->status() >= 300 && $res->status() < 400) {
                    $locHost = strtolower((string) parse_url((string) $res->header('Location'), PHP_URL_HOST));
                    $wwwCheck['redirects_cleanly'] = ($locHost === $host);
                } elseif ($res->status() === 200) {
                    $wwwCheck['redirects_cleanly'] = false;
                }
            } catch (Throwable) {
                // Alternate host does not resolve (fine — no duplicate host risk)
                $wwwCheck['redirects_cleanly'] = true;
            }
        }

        $this->siteChecks['https_enforcement'] = $httpCheck;
        $this->siteChecks['www_consistency'] = $wwwCheck;
    }

    /**
     * Fetch a single page with step-by-step redirect chain tracking, SSRF checks per hop,
     * and legitimate SPA JS rendering when needed.
     */
    protected function fetchAndInspectPage(string $url, string $homepageUrl, string $rootDomain, int $depth): array
    {
        $startTime = microtime(true);
        $redirectHops = [];
        $currentUrl = $url;
        $seenRawUrls = [$currentUrl => true];
        $maxRedirects = 5;

        try {
            $response = null;

            for ($hop = 0; $hop <= $maxRedirects; $hop++) {
                UrlSecurityValidator::validate($currentUrl, $rootDomain, $this->allowLocalhost);

                $response = Http::timeout(12)
                    ->withHeaders($this->defaultRequestHeaders())
                    ->withOptions(['allow_redirects' => false])
                    ->get($currentUrl);

                $status = $response->status();

                if ($status >= 300 && $status < 400) {
                    $location = $response->header('Location');
                    if (!$location) {
                        break;
                    }

                    // Preserve exact redirect target (including WordPress trailing slash) for HTTP fetch
                    $rawNextUrl = UrlNormalizer::resolveRelativeUrl(trim($location), $currentUrl);
                    $normalizedNext = UrlNormalizer::normalize($location, $currentUrl);

                    $redirectHops[] = [
                        'from' => $currentUrl,
                        'status' => $status,
                        'to' => $rawNextUrl ?? $location,
                    ];

                    if (!$rawNextUrl || !UrlNormalizer::isInternal($rawNextUrl, $rootDomain)) {
                        break;
                    }

                    // Prevent real redirect loops (compare exact raw URLs, not slash-stripped normalized URLs)
                    if (isset($seenRawUrls[$rawNextUrl]) || $hop === $maxRedirects) {
                        break;
                    }

                    $seenRawUrls[$rawNextUrl] = true;
                    $currentUrl = $rawNextUrl;
                    continue;
                }

                break;
            }

            $loadTimeMs = (int) round((microtime(true) - $startTime) * 1000);
            $statusCode = $response ? $response->status() : 0;
            $headers = $response ? $response->headers() : [];
            $html = $response ? $response->body() : '';

            $inspection = PageInspector::inspect(
                $html,
                $url,
                $rootDomain,
                $headers,
                $statusCode,
                $loadTimeMs,
                $currentUrl
            );

            // Legitimate SPA JS Rendering: ONLY when HTTP 200, NOT WAF-blocked, and either:
            // - mode is 'always', OR
            // - mode is 'auto' and the raw HTML is an empty CSR SPA shell (React/Vite/Vue/Angular)
            $shouldRenderJs = !$this->isHttpFaked
                && $statusCode === 200
                && !$inspection['is_blocked_by_waf']
                && stripos($inspection['content_type'] ?? '', 'html') !== false
                && (
                    $this->renderJsMode === 'always'
                    || ($this->renderJsMode === 'auto' && $inspection['is_spa_shell'])
                )
                && HeadlessRenderer::isAvailable();

            if ($shouldRenderJs) {
                $rendered = HeadlessRenderer::render($currentUrl);
                if ($rendered['success'] && !empty($rendered['html'])) {
                    $wasSpaShell = $inspection['is_spa_shell'];
                    $renderedInspection = PageInspector::inspect(
                        $rendered['html'],
                        $url,
                        $rootDomain,
                        $headers,
                        $statusCode,
                        $loadTimeMs,
                        $currentUrl
                    );

                    // Only accept rendered DOM if it didn't hit a WAF challenge
                    if (!$renderedInspection['is_blocked_by_waf']) {
                        $inspection = $renderedInspection;
                        $inspection['is_js_rendered'] = true;
                        $inspection['is_spa_shell'] = $wasSpaShell;
                        $inspection['ttfb_ms'] = $loadTimeMs;
                        $this->job->logStep('JS SPA rendered via Web Rendering Service', [
                            'url' => $url,
                            'words_extracted' => $inspection['word_count'],
                            'links_discovered' => count($inspection['internal_links']),
                            'render_ms' => $rendered['render_time_ms'],
                        ]);
                    }
                }
            }

            $isInSitemap = in_array($url, $this->sitemapUrls, true)
                || in_array($currentUrl, $this->sitemapUrls, true);

            $pageRecord = CrawledPage::create(array_merge($inspection, [
                'crawl_job_id' => $this->job->id,
                'website_id' => $this->website->id,
                'depth' => $depth,
                'in_sitemap' => $isInSitemap,
                'redirect_url' => !empty($redirectHops) ? $currentUrl : null,
                'redirect_hops' => !empty($redirectHops) ? $redirectHops : null,
            ]));

            return ['success' => true, 'data' => $pageRecord->toArray()];
        } catch (Throwable $e) {
            $loadTimeMs = (int) round((microtime(true) - $startTime) * 1000);

            CrawledPage::create([
                'crawl_job_id' => $this->job->id,
                'website_id' => $this->website->id,
                'url' => $url,
                'final_url' => $currentUrl,
                'depth' => $depth,
                'status_code' => 0,
                'load_time_ms' => $loadTimeMs,
                'ttfb_ms' => $loadTimeMs,
                'redirect_hops' => !empty($redirectHops) ? $redirectHops : null,
                'error' => $e->getMessage(),
                'is_indexable' => false,
                'in_sitemap' => in_array($url, $this->sitemapUrls, true),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function defaultRequestHeaders(string $accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'): array
    {
        return [
            'User-Agent' => self::USER_AGENT,
            'Accept' => $accept,
            'Accept-Language' => 'en-US,en;q=0.9',
            'Cache-Control' => 'no-cache',
        ];
    }

    /**
     * Compute in-degree (how many unique crawled pages link internally to each page).
     */
    protected function calculateInDegrees(): void
    {
        $pages = CrawledPage::where('crawl_job_id', $this->job->id)->get();
        $inDegreeCounts = [];

        foreach ($pages as $p) {
            $internal = $p->internal_links ?? [];
            foreach ($internal as $link) {
                $targetUrl = $link['url'] ?? null;
                if ($targetUrl && $targetUrl !== $p->url) {
                    $inDegreeCounts[$targetUrl] = ($inDegreeCounts[$targetUrl] ?? 0) + 1;
                }
            }
        }

        foreach ($pages as $p) {
            $count = $inDegreeCounts[$p->url] ?? 0;
            if ($p->final_url && $p->final_url !== $p->url) {
                $count += $inDegreeCounts[$p->final_url] ?? 0;
            }
            if ($count > 0) {
                $p->update(['in_degree' => $count]);
            }
        }
    }
}
