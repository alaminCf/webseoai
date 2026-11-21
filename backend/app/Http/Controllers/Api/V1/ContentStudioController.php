<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ContentStudio\ContentStrategist;
use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\AuditLog;
use App\Models\ContentArticle;
use App\Models\ContentBrief;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentStudioController extends Controller
{
    use ApiResponseTrait;

    /**
     * List content briefs for a website.
     */
    public function listBriefs(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();
        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $briefs = ContentBrief::with('creator:id,name', 'approver:id,name')
            ->where('website_id', $website->id)
            ->orderBy('id', 'desc')
            ->paginate($request->integer('per_page', 20));

        return $this->success($briefs->items(), [
            'current_page' => $briefs->currentPage(),
            'per_page' => $briefs->perPage(),
            'total' => $briefs->total(),
            'last_page' => $briefs->lastPage(),
        ]);
    }

    /**
     * Generate and save a new content brief.
     */
    public function createBrief(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->hasMinimumRole(OrganizationMember::ROLE_EDITOR)) {
            return $this->error('Insufficient permissions to generate content briefs.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();
        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:255'],
            'search_intent' => ['nullable', 'string', 'in:informational,commercial,transactional,navigational'],
            'target_word_count' => ['nullable', 'integer', 'min:300', 'max:5000'],
        ]);

        $strategist = new ContentStrategist($website);
        $brief = $strategist->generateBrief(
            $validated['topic'],
            $validated['search_intent'] ?? null,
            $validated['target_word_count'] ?? 1200,
            $request->user()->id
        );

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'content_studio.brief_created',
            $brief,
            null,
            ['topic' => $validated['topic']],
            $request
        );

        return $this->success($brief, null, 201);
    }

    /**
     * Get a specific content brief.
     */
    public function getBrief(Request $request, int $id): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $brief = ContentBrief::with('creator:id,name', 'approver:id,name', 'articles')
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->first();

        if (!$brief) {
            return $this->error('Content brief not found.', 'BRIEF_NOT_FOUND', null, 404);
        }

        return $this->success($brief);
    }

    /**
     * List content articles for a website.
     */
    public function listArticles(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();
        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $query = ContentArticle::with('author:id,name', 'reviewer:id,name')
            ->where('website_id', $website->id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $articles = $query->orderBy('id', 'desc')
            ->paginate($request->integer('per_page', 20));

        return $this->success($articles->items(), [
            'current_page' => $articles->currentPage(),
            'per_page' => $articles->perPage(),
            'total' => $articles->total(),
            'last_page' => $articles->lastPage(),
        ]);
    }

    /**
     * Generate a new draft article from a brief or topic.
     */
    public function generateArticle(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->hasMinimumRole(OrganizationMember::ROLE_EDITOR)) {
            return $this->error('Insufficient permissions to generate articles.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();
        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'content_brief_id' => ['nullable', 'exists:content_briefs,id'],
            'topic' => ['required_without:content_brief_id', 'nullable', 'string', 'max:255'],
        ]);

        $strategist = new ContentStrategist($website);

        if (!empty($validated['content_brief_id'])) {
            $brief = ContentBrief::where('organization_id', $org->id)->where('id', $validated['content_brief_id'])->firstOrFail();
        } else {
            $brief = $strategist->generateBrief($validated['topic'], null, 1200, $request->user()->id);
        }

        $article = $strategist->draftArticle($brief, $request->user()->id);

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'content_studio.article_drafted',
            $article,
            null,
            ['title' => $article->title],
            $request
        );

        return $this->success($article, null, 201);
    }

    /**
     * Get a specific article and its revision history.
     */
    public function getArticle(Request $request, int $id): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $article = ContentArticle::with('author:id,name', 'reviewer:id,name', 'revisions.editor:id,name', 'brief')
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->first();

        if (!$article) {
            return $this->error('Article not found.', 'ARTICLE_NOT_FOUND', null, 404);
        }

        return $this->success($article);
    }

    /**
     * Update an article's content and record a revision snapshot.
     */
    public function updateArticle(Request $request, int $id): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->hasMinimumRole(OrganizationMember::ROLE_EDITOR)) {
            return $this->error('Insufficient permissions to edit articles.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $article = ContentArticle::where('organization_id', $org->id)->where('id', $id)->first();
        if (!$article) {
            return $this->error('Article not found.', 'ARTICLE_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'content_body' => ['required', 'string'],
            'change_summary' => ['nullable', 'string', 'max:255'],
        ]);

        $wordCount = str_word_count(strip_tags($validated['content_body']));
        $newVersion = $article->version + 1;

        $article->update([
            'title' => $validated['title'],
            'meta_description' => $validated['meta_description'] ?? $article->meta_description,
            'content_body' => $validated['content_body'],
            'word_count' => $wordCount,
            'version' => $newVersion,
        ]);

        // Record revision snapshot
        $article->recordRevision($request->user()->id, $validated['change_summary'] ?? "Updated to version {$newVersion}");

        return $this->success($article->load('revisions.editor:id,name'));
    }

    /**
     * Update the workflow approval status of an article.
     * Enforces that publication requires explicit human approval.
     */
    public function updateArticleStatus(Request $request, int $id): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        $article = ContentArticle::where('organization_id', $org->id)->where('id', $id)->first();
        if (!$article) {
            return $this->error('Article not found.', 'ARTICLE_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:draft,in_review,approved,ready_for_publish,rejected'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $targetStatus = $validated['status'];

        // Approving or transitioning to ready_for_publish requires at least SEO Manager or Admin/Owner
        if (in_array($targetStatus, [ContentArticle::STATUS_APPROVED, ContentArticle::STATUS_READY_FOR_PUBLISH], true)) {
            if (!$membership->hasMinimumRole(OrganizationMember::ROLE_SEO_MANAGER)) {
                return $this->error('Only SEO Managers, Admins, or Owners can approve content for publishing.', 'INSUFFICIENT_PERMISSIONS', null, 403);
            }

            $article->update([
                'status' => $targetStatus,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        } else {
            // Authors/editors can request review or move back to draft
            if (!$membership->hasMinimumRole(OrganizationMember::ROLE_EDITOR)) {
                return $this->error('Insufficient permissions.', 'INSUFFICIENT_PERMISSIONS', null, 403);
            }

            $article->update(['status' => $targetStatus]);
        }

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'content_studio.status_changed',
            $article,
            null,
            ['status' => $targetStatus],
            $request
        );

        return $this->success($article->load('reviewer:id,name'));
    }

    /**
     * Generate title and meta description candidate variants.
     */
    public function generateTitlesAndMeta(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();
        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:255'],
            'content_snippet' => ['nullable', 'string', 'max:4000'],
        ]);

        $strategist = new ContentStrategist($website);
        $candidates = $strategist->generateTitleAndMeta($validated['topic'], $validated['content_snippet'] ?? null);

        return $this->success([
            'topic' => $validated['topic'],
            'candidates' => $candidates,
        ]);
    }

    /**
     * Generate FAQ items for a topic.
     */
    public function generateFaq(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();
        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:255'],
        ]);

        $strategist = new ContentStrategist($website);
        $faqs = $strategist->generateFaqs($validated['topic']);

        return $this->success([
            'topic' => $validated['topic'],
            'faq' => $faqs,
        ]);
    }

    /**
     * Analyze content gaps based on website goals vs crawled inventory.
     */
    public function getGapAnalysis(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();
        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $strategist = new ContentStrategist($website);
        $gaps = $strategist->analyzeGaps();

        return $this->success([
            'website_id' => $website->id,
            'domain' => $website->domain,
            'gaps' => $gaps,
        ]);
    }

    /**
     * Aggregate AI usage and token costs for a website/organization.
     */
    public function getUsageStats(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();
        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $logs = AiUsageLog::where('website_id', $website->id)->orderBy('id', 'desc')->take(50)->get();

        $totalTokens = AiUsageLog::where('website_id', $website->id)->sum('total_tokens');
        $totalCost = AiUsageLog::where('website_id', $website->id)->sum('estimated_cost');
        $successfulRequests = AiUsageLog::where('website_id', $website->id)->where('status', 'success')->count();
        $failedRequests = AiUsageLog::where('website_id', $website->id)->where('status', 'failed')->count();

        return $this->success([
            'summary' => [
                'total_tokens' => (int) $totalTokens,
                'total_cost_usd' => round((float) $totalCost, 4),
                'successful_requests' => $successfulRequests,
                'failed_requests' => $failedRequests,
            ],
            'recent_logs' => $logs->map(fn($l) => [
                'id' => $l->id,
                'provider' => $l->provider,
                'model' => $l->model,
                'feature' => $l->feature,
                'tokens' => $l->total_tokens,
                'cost' => (float) $l->estimated_cost,
                'duration_ms' => $l->duration_ms,
                'status' => $l->status,
                'error_message' => $l->error_message,
                'created_at' => $l->created_at->toIso8601String(),
            ]),
        ]);
    }
}
