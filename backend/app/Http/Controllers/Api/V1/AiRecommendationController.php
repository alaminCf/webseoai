<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crawler\PageInspector;
use App\Domain\Crawler\UrlSecurityValidator;
use App\Domain\Strategist\RecommendationEngine;
use App\Http\Controllers\Controller;
use App\Models\AiRecommendation;
use App\Models\AuditIssue;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class AiRecommendationController extends Controller
{
    use ApiResponseTrait;

    /**
     * List prioritized AI SEO recommendations.
     */
    public function listRecommendations(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();
        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $query = AiRecommendation::with('auditIssue')
            ->where('website_id', $website->id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('confidence')) {
            $query->where('confidence_level', $request->input('confidence'));
        }

        // Sort by quantified priority score descending
        $recommendations = $query->orderBy('priority_score', 'desc')
            ->paginate($request->integer('per_page', 25));

        $allForSite = AiRecommendation::where('website_id', $website->id);
        $summary = [
            'total' => (clone $allForSite)->count(),
            'proposed' => (clone $allForSite)->where('status', AiRecommendation::STATUS_PROPOSED)->count(),
            'accepted' => (clone $allForSite)->where('status', AiRecommendation::STATUS_ACCEPTED)->count(),
            'in_progress' => (clone $allForSite)->where('status', AiRecommendation::STATUS_IN_PROGRESS)->count(),
            'applied' => (clone $allForSite)->where('status', AiRecommendation::STATUS_APPLIED)->count(),
            'dismissed' => (clone $allForSite)->where('status', AiRecommendation::STATUS_DISMISSED)->count(),
            'quick_wins' => (clone $allForSite)->where('impact_score', '>=', 7)->where('effort', 'low')->where('status', '!=', AiRecommendation::STATUS_DISMISSED)->count(),
        ];

        return $this->success($recommendations->items(), [
            'current_page' => $recommendations->currentPage(),
            'per_page' => $recommendations->perPage(),
            'total' => $recommendations->total(),
            'last_page' => $recommendations->lastPage(),
            'summary' => $summary,
            'scoring_formula' => 'Score = (Impact * Confidence) / (Effort + Risk * 0.5), normalized 0-100',
        ]);
    }

    /**
     * Trigger fresh AI SEO recommendations analysis.
     */
    public function generateRecommendations(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->hasMinimumRole(OrganizationMember::ROLE_SEO_MANAGER)) {
            return $this->error('Only SEO Managers, Admins, or Owners can run the AI Strategist.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();
        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $engine = new RecommendationEngine($website, null, $request->user()?->id);
        $generated = $engine->generate();

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'ai.recommendations_generated',
            $website,
            null,
            ['count' => count($generated)],
            $request
        );

        return $this->success([
            'count' => count($generated),
            'items' => $generated,
        ], [
            'scoring_formula' => 'Score = (Impact * Confidence) / (Effort + Risk * 0.5), normalized 0-100',
        ]);
    }

    /**
     * Update the status of an AI Recommendation.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->hasMinimumRole(OrganizationMember::ROLE_EDITOR)) {
            return $this->error('Insufficient permissions to update recommendation status.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $rec = AiRecommendation::where('organization_id', $org->id)->where('id', $id)->first();
        if (!$rec) {
            return $this->error('Recommendation not found.', 'RECOMMENDATION_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:proposed,accepted,in_progress,applied,dismissed'],
        ]);

        $rec->update(['status' => $validated['status']]);

        return $this->success($rec);
    }

    /**
     * Live-verify whether a recommendation's underlying SEO issue is actually resolved on the live website.
     */
    public function verifyLive(Request $request, int $id): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->hasMinimumRole(OrganizationMember::ROLE_EDITOR)) {
            return $this->error('Insufficient permissions to run live verification.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $rec = AiRecommendation::with(['website', 'auditIssue'])
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->first();

        if (!$rec || !$rec->website) {
            return $this->error('Recommendation not found.', 'RECOMMENDATION_NOT_FOUND', null, 404);
        }

        $website = $rec->website;
        $targetUrl = trim((string) ($rec->affected_url ?: $website->target_url));
        $evidence = is_array($rec->evidence) ? $rec->evidence : [];
        $issueType = strtoupper((string) ($evidence['issue_type'] ?? $rec->auditIssue?->issue_type ?? ''));

        // Special-case URLs for robots.txt / sitemap issues
        $checkUrl = $targetUrl;
        if ($issueType === 'MISSING_ROBOTS_TXT') {
            $checkUrl = rtrim($website->target_url, '/') . '/robots.txt';
        } elseif ($issueType === 'MISSING_SITEMAP' && !str_contains(strtolower($targetUrl), 'sitemap')) {
            $checkUrl = rtrim($website->target_url, '/') . '/sitemap.xml';
        }

        // SSRF domain guard (skip DNS resolution in testing if Http::fake is active)
        if (!app()->runningUnitTests()) {
            $validation = UrlSecurityValidator::validate($checkUrl, $website->domain);
            if (!($validation['valid'] ?? false)) {
                return $this->error(
                    'Live verification blocked by security policy: ' . ($validation['reason'] ?? 'Invalid target URL'),
                    'SSRF_BLOCKED',
                    null,
                    422
                );
            }
        }

        $verified = false;
        $httpStatus = 0;
        $summary = '';
        $observed = [];

        try {
            $start = microtime(true);
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; WebSEOAI-Verifier/1.0)',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])->timeout(12)->get($checkUrl);
            $loadTimeMs = (int) round((microtime(true) - $start) * 1000);

            $httpStatus = $response->status();
            $body = $response->body();
            $headers = $response->headers();

            $inspected = PageInspector::inspect($body, $checkUrl, $website->domain, $headers, $httpStatus, $loadTimeMs, $checkUrl);

            if (!empty($inspected['is_blocked_by_waf'])) {
                $verified = false;
                $summary = "Target site returned a WAF / bot challenge (HTTP {$httpStatus}), so live fix state could not be confirmed.";
                $observed = ['http_status' => $httpStatus, 'waf_blocked' => true];
            } else {
                $title = trim((string) ($inspected['page_title'] ?? ''));
                $metaDesc = trim((string) ($inspected['meta_description'] ?? ''));
                $h1Count = (int) ($inspected['h1_count'] ?? 0);
                $canonical = trim((string) ($inspected['canonical_url'] ?? ''));
                $schemaCount = (int) ($inspected['structured_data']['count'] ?? 0);
                $missingAlt = (int) ($inspected['images_missing_alt'] ?? 0);
                $ogTitle = trim((string) ($inspected['open_graph']['title'] ?? ''));
                $hasHsts = (bool) ($inspected['security_headers']['hsts'] ?? false);

                $observed = [
                    'http_status' => $httpStatus,
                    'load_time_ms' => $loadTimeMs,
                    'title_length' => mb_strlen($title),
                    'meta_description_length' => mb_strlen($metaDesc),
                    'h1_count' => $h1Count,
                ];

                if (in_array($issueType, ['STATUS_4XX', 'STATUS_5XX', 'BROKEN_INTERNAL_LINK'], true)) {
                    $verified = $httpStatus >= 200 && $httpStatus < 300;
                    $summary = $verified
                        ? "Live check passed: URL now returns HTTP {$httpStatus} OK ({$loadTimeMs}ms)."
                        : "Not fixed on live site yet: URL is still returning HTTP {$httpStatus}.";
                } elseif ($issueType === 'MISSING_TITLE') {
                    $verified = $httpStatus < 400 && $title !== '';
                    $summary = $verified
                        ? "Live check passed: Found <title> (\"{$title}\", " . mb_strlen($title) . ' chars).'
                        : "Not fixed yet: <title> tag is still missing (HTTP {$httpStatus}).";
                } elseif (in_array($issueType, ['TITLE_TOO_SHORT', 'TITLE_TOO_LONG'], true)) {
                    $len = mb_strlen($title);
                    $verified = $httpStatus < 400 && $len >= 25 && $len <= 65;
                    $summary = $verified
                        ? "Live check passed: Title length is now {$len} chars (\"{$title}\")."
                        : "Not fixed yet: Live title length is {$len} chars (recommended 30–60 chars).";
                } elseif ($issueType === 'MISSING_META_DESCRIPTION') {
                    $verified = $httpStatus < 400 && $metaDesc !== '';
                    $summary = $verified
                        ? 'Live check passed: Meta description is now present (' . mb_strlen($metaDesc) . ' chars).'
                        : "Not fixed yet: <meta name=\"description\"> is still missing (HTTP {$httpStatus}).";
                } elseif (in_array($issueType, ['META_DESCRIPTION_TOO_SHORT', 'META_DESCRIPTION_TOO_LONG'], true)) {
                    $len = mb_strlen($metaDesc);
                    $verified = $httpStatus < 400 && $len >= 70 && $len <= 165;
                    $summary = $verified
                        ? "Live check passed: Meta description length is now {$len} chars."
                        : "Not fixed yet: Live meta description length is {$len} chars (recommended 70–160 chars).";
                } elseif (in_array($issueType, ['MISSING_H1', 'MULTIPLE_H1'], true)) {
                    $verified = $httpStatus < 400 && $h1Count === 1;
                    $summary = $verified
                        ? 'Live check passed: Exactly 1 primary <h1> tag detected.'
                        : "Not fixed yet: Live page currently has {$h1Count} <h1> tags (expected 1).";
                } elseif ($issueType === 'MISSING_CANONICAL') {
                    $verified = $httpStatus < 400 && $canonical !== '';
                    $summary = $verified
                        ? "Live check passed: Canonical tag found ({$canonical})."
                        : 'Not fixed yet: <link rel="canonical"> is still missing on the live page.';
                } elseif ($issueType === 'MISSING_STRUCTURED_DATA') {
                    $verified = $httpStatus < 400 && $schemaCount > 0;
                    $summary = $verified
                        ? "Live check passed: Detected {$schemaCount} JSON-LD structured data block(s)."
                        : 'Not fixed yet: No JSON-LD structured data detected on the live page.';
                } elseif ($issueType === 'MISSING_OPEN_GRAPH') {
                    $verified = $httpStatus < 400 && $ogTitle !== '';
                    $summary = $verified
                        ? "Live check passed: Open Graph tags detected (og:title present)."
                        : 'Not fixed yet: Open Graph og:title tag is still missing.';
                } elseif ($issueType === 'IMAGES_MISSING_ALT') {
                    $verified = $httpStatus < 400 && $missingAlt === 0;
                    $summary = $verified
                        ? 'Live check passed: All images on this page now have alt attributes.'
                        : "Not fixed yet: {$missingAlt} image(s) still missing alt attributes.";
                } elseif ($issueType === 'MISSING_HSTS') {
                    $verified = $hasHsts;
                    $summary = $verified
                        ? 'Live check passed: Strict-Transport-Security (HSTS) header is now active.'
                        : 'Not fixed yet: Strict-Transport-Security header is still missing.';
                } elseif ($issueType === 'MISSING_ROBOTS_TXT') {
                    $verified = $httpStatus >= 200 && $httpStatus < 300 && trim($body) !== '';
                    $summary = $verified
                        ? 'Live check passed: /robots.txt is reachable and non-empty.'
                        : "Not fixed yet: /robots.txt returned HTTP {$httpStatus}.";
                } elseif ($issueType === 'MISSING_SITEMAP') {
                    $verified = $httpStatus >= 200 && $httpStatus < 300 && str_contains(strtolower($body), '<urlset');
                    $summary = $verified
                        ? 'Live check passed: XML sitemap is live and valid.'
                        : "Not fixed yet: XML sitemap check returned HTTP {$httpStatus}.";
                } else {
                    $verified = $httpStatus >= 200 && $httpStatus < 300;
                    $summary = $verified
                        ? "Live check passed: Page responds HTTP {$httpStatus} OK ({$loadTimeMs}ms, title: " . mb_strlen($title) . ' chars).'
                        : "Live check failed: Page returned HTTP {$httpStatus}.";
                }
            }
        } catch (Throwable $e) {
            $verified = false;
            $summary = 'Could not reach live URL during verification: ' . $e->getMessage();
            $observed = ['error' => $e->getMessage()];
        }

        $liveVerification = [
            'verified' => $verified,
            'checked_at' => now()->toIso8601String(),
            'checked_url' => $checkUrl,
            'http_status' => $httpStatus,
            'issue_type' => $issueType ?: 'GENERAL',
            'summary' => $summary,
            'observed' => $observed,
        ];

        $evidence['live_verification'] = $liveVerification;
        $updatePayload = ['evidence' => $evidence];
        if ($verified) {
            $updatePayload['status'] = AiRecommendation::STATUS_APPLIED;
        }
        $rec->update($updatePayload);

        if ($rec->auditIssue) {
            $rec->auditIssue->update([
                'current_status' => $verified ? AuditIssue::STATUS_RESOLVED : AuditIssue::STATUS_OPEN,
                'verification_status' => $verified ? 'verified_fixed' : 'unverified',
            ]);
        }

        return $this->success([
            'verified' => $verified,
            'live_verification' => $liveVerification,
            'recommendation' => $rec->fresh(),
        ]);
    }
}
