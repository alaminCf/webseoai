<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crawler\CrawlEngine;
use App\Domain\Crawler\HeadlessRenderer;
use App\Http\Controllers\Controller;
use App\Jobs\RunWebsiteCrawlJob;
use App\Models\AuditLog;
use App\Models\CrawledPage;
use App\Models\CrawlJob;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrawlController extends Controller
{
    use ApiResponseTrait;

    /**
     * Trigger a genuine technical SEO crawl run for an authorized website.
     */
    public function startCrawl(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->hasMinimumRole(OrganizationMember::ROLE_SEO_MANAGER)) {
            return $this->error('Insufficient permissions to trigger crawls.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        // Clean up any stale crawl stuck in queued/crawling for > 15 minutes
        CrawlJob::where('website_id', $website->id)
            ->whereIn('status', [CrawlJob::STATUS_QUEUED, CrawlJob::STATUS_CRAWLING])
            ->where('updated_at', '<', now()->subMinutes(15))
            ->update([
                'status' => CrawlJob::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => 'Timed out after 15 minutes of inactivity.',
            ]);

        // Check if an active crawl is currently running
        $activeJob = CrawlJob::where('website_id', $website->id)
            ->whereIn('status', [CrawlJob::STATUS_QUEUED, CrawlJob::STATUS_CRAWLING])
            ->first();

        if ($activeJob) {
            return $this->error('A crawl job is already in progress for this website.', 'CRAWL_IN_PROGRESS', [
                'job_id' => $activeJob->id,
                'status' => $activeJob->status,
            ], 409);
        }

        $validated = $request->validate([
            'max_pages' => ['nullable', 'integer', 'min:5', 'max:500'],
            'max_depth' => ['nullable', 'integer', 'min:1', 'max:5'],
            'concurrency' => ['nullable', 'integer', 'min:1', 'max:4'],
            'render_js' => ['nullable', 'string', 'in:auto,always,never'],
        ]);

        $renderJs = $validated['render_js'] ?? 'auto';

        $crawlJob = CrawlJob::create([
            'organization_id' => $org->id,
            'website_id' => $website->id,
            'initiated_by' => $request->user()->id,
            'status' => CrawlJob::STATUS_QUEUED,
            'max_pages' => $validated['max_pages'] ?? 50,
            'max_depth' => $validated['max_depth'] ?? 3,
            'concurrency' => $validated['concurrency'] ?? 2,
            'config' => [
                'user_agent' => 'Mozilla/5.0 (compatible; WebSeoAi-AuditBot/2.0; +https://techboloy.com/seo-audit)',
                'render_js' => $renderJs,
                'headless_available' => HeadlessRenderer::isAvailable(),
            ],
            'execution_logs' => [
                [
                    'timestamp' => now()->toIso8601String(),
                    'step' => 'Crawl job queued',
                    'message' => 'Crawl job queued',
                    'context' => [
                        'target' => $website->target_url,
                        'max_pages' => $validated['max_pages'] ?? 50,
                        'render_js' => $renderJs,
                    ],
                ],
            ],
        ]);

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'crawler.dispatched',
            $crawlJob,
            null,
            [
                'website_id' => $website->id,
                'max_pages' => $crawlJob->max_pages,
                'render_js' => $renderJs,
            ],
            $request
        );

        if ($request->boolean('sync', false)) {
            $allowLocal = $request->boolean('allow_local', false);
            $engine = new CrawlEngine($crawlJob, $allowLocal);
            $engine->run();
            $crawlJob->refresh();
        } else {
            RunWebsiteCrawlJob::dispatch($crawlJob->id, false);
        }

        return $this->success([
            'crawl_job_id' => $crawlJob->id,
            'status' => $crawlJob->status,
            'max_pages' => $crawlJob->max_pages,
            'max_depth' => $crawlJob->max_depth,
            'render_js' => $renderJs,
            'pages_discovered' => $crawlJob->pages_discovered,
            'pages_crawled' => $crawlJob->pages_crawled,
            'message' => 'Crawl run initiated successfully.',
        ], null, 202);
    }

    /**
     * Get real-time status and progress of the latest or active crawl job.
     */
    public function getCrawlStatus(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $latestJob = CrawlJob::where('website_id', $website->id)
            ->orderBy('id', 'desc')
            ->first();

        if (!$latestJob) {
            return $this->success(null);
        }

        $normalizedLogs = array_map(function ($log) {
            $label = $log['step'] ?? $log['message'] ?? 'Event';
            return [
                'timestamp' => $log['timestamp'] ?? null,
                'step' => $label,
                'message' => $label,
                'context' => $log['context'] ?? null,
            ];
        }, $latestJob->execution_logs ?? []);

        return $this->success([
            'id' => $latestJob->id,
            'status' => $latestJob->status,
            'max_pages' => $latestJob->max_pages,
            'max_depth' => $latestJob->max_depth,
            'concurrency' => $latestJob->concurrency,
            'render_js' => $latestJob->config['render_js'] ?? 'auto',
            'pages_discovered' => $latestJob->pages_discovered,
            'pages_crawled' => $latestJob->pages_crawled,
            'pages_failed' => $latestJob->pages_failed,
            'site_checks' => $latestJob->site_checks,
            'started_at' => $latestJob->started_at?->toIso8601String(),
            'finished_at' => $latestJob->finished_at?->toIso8601String(),
            'duration_seconds' => $latestJob->finished_at && $latestJob->started_at
                ? $latestJob->started_at->diffInSeconds($latestJob->finished_at)
                : ($latestJob->started_at && in_array($latestJob->status, [CrawlJob::STATUS_QUEUED, CrawlJob::STATUS_CRAWLING], true)
                    ? $latestJob->started_at->diffInSeconds(now())
                    : null),
            'error_message' => $latestJob->error_message,
            'logs' => $normalizedLogs,
        ]);
    }

    /**
     * Cancel an in-progress crawl job.
     */
    public function cancelCrawl(Request $request, string $jobId): JsonResponse
    {
        if (!ctype_digit($jobId)) {
            return $this->error('Invalid crawl job ID.', 'INVALID_JOB_ID', null, 400);
        }

        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $job = CrawlJob::where('organization_id', $org->id)->where('id', (int) $jobId)->first();

        if (!$job) {
            return $this->error('Crawl job not found.', 'JOB_NOT_FOUND', null, 404);
        }

        if (in_array($job->status, [CrawlJob::STATUS_QUEUED, CrawlJob::STATUS_CRAWLING], true)) {
            $job->update([
                'status' => CrawlJob::STATUS_CANCELLED,
                'finished_at' => now(),
            ]);
            $job->logStep('Crawl manually cancelled by user', ['user_id' => $request->user()->id]);
        }

        return $this->success(['message' => 'Crawl job cancelled.']);
    }

    /**
     * List crawled pages with full SEO & technical telemetry for a crawl job.
     */
    public function listPages(Request $request, string $jobId): JsonResponse
    {
        if (!ctype_digit($jobId)) {
            return $this->error('Invalid crawl job ID.', 'INVALID_JOB_ID', null, 400);
        }

        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $job = CrawlJob::where('organization_id', $org->id)->where('id', (int) $jobId)->first();

        if (!$job) {
            return $this->error('Crawl job not found.', 'JOB_NOT_FOUND', null, 404);
        }

        $query = CrawledPage::withCount('issues')->where('crawl_job_id', $job->id);

        if ($request->filled('status_code')) {
            $query->where('status_code', $request->input('status_code'));
        }

        if ($request->has('is_indexable')) {
            $query->where('is_indexable', $request->boolean('is_indexable'));
        }

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('url', 'like', $term)
                  ->orWhere('page_title', 'like', $term);
            });
        }

        $perPage = min(500, max(1, $request->integer('per_page', 100)));
        $pages = $query->orderBy('depth', 'asc')->orderBy('id', 'asc')->paginate($perPage);

        $data = $pages->getCollection()->map(function (CrawledPage $p) {
            return [
                'id' => $p->id,
                'crawl_job_id' => $p->crawl_job_id,
                'website_id' => $p->website_id,
                'url' => $p->url,
                'final_url' => $p->final_url,
                'depth' => $p->depth,
                'status_code' => $p->status_code,
                'load_time_ms' => $p->load_time_ms,
                'ttfb_ms' => $p->ttfb_ms ?? $p->load_time_ms,
                'html_size_bytes' => $p->html_size_bytes ?? 0,
                'content_type' => $p->content_type,
                'page_title' => $p->page_title,
                'meta_description' => $p->meta_description,
                'canonical_url' => $p->canonical_url,
                'viewport' => $p->viewport,
                'html_lang' => $p->html_lang,
                'charset' => $p->charset,
                'favicon_url' => $p->favicon_url,
                'open_graph' => $p->open_graph,
                'twitter_card' => $p->twitter_card,
                'structured_data' => $p->structured_data,
                'hreflang_tags' => $p->hreflang_tags ?? [],
                'h1_count' => count($p->h1_tags ?? []),
                'h1_tags' => $p->h1_tags ?? [],
                'headings_tree' => $p->headings_tree ?? [],
                'meta_robots' => $p->meta_robots,
                'x_robots_tag' => $p->x_robots_tag,
                'meta_refresh' => $p->meta_refresh,
                'is_indexable' => $p->is_indexable,
                'in_sitemap' => $p->in_sitemap,
                'is_js_rendered' => (bool) $p->is_js_rendered,
                'is_spa_shell' => (bool) $p->is_spa_shell,
                'is_blocked_by_waf' => (bool) $p->is_blocked_by_waf,
                'in_degree' => $p->in_degree,
                'word_count' => $p->word_count,
                'images_count' => $p->images_count,
                'images_missing_alt' => $p->images_missing_alt,
                'images_without_dimensions' => $p->images_without_dimensions ?? 0,
                'images_missing_alt_samples' => $p->images_missing_alt_samples ?? [],
                'internal_links_count' => count($p->internal_links ?? []),
                'external_links_count' => count($p->external_links ?? []),
                'redirect_url' => $p->redirect_url,
                'redirect_hops' => $p->redirect_hops ?? [],
                'security_headers' => $p->security_headers,
                'mixed_content_urls' => $p->mixed_content_urls ?? [],
                'issues_count' => $p->issues_count ?? 0,
                'error' => $p->error,
            ];
        });

        return $this->success($data, [
            'current_page' => $pages->currentPage(),
            'per_page' => $pages->perPage(),
            'total' => $pages->total(),
            'last_page' => $pages->lastPage(),
        ]);
    }

    /**
     * Get single page inspection details.
     */
    public function getPageDetails(Request $request, string $jobId, int $pageId): JsonResponse
    {
        if (!ctype_digit($jobId)) {
            return $this->error('Invalid crawl job ID.', 'INVALID_JOB_ID', null, 400);
        }

        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $job = CrawlJob::where('organization_id', $org->id)->where('id', (int) $jobId)->first();

        if (!$job) {
            return $this->error('Crawl job not found.', 'JOB_NOT_FOUND', null, 404);
        }

        $page = CrawledPage::with('issues')->where('crawl_job_id', $job->id)->where('id', $pageId)->first();

        if (!$page) {
            return $this->error('Crawled page not found.', 'PAGE_NOT_FOUND', null, 404);
        }

        return $this->success($page);
    }
}
