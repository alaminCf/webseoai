<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Strategist\RecommendationEngine;
use App\Http\Controllers\Controller;
use App\Models\AiRecommendation;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return $this->success($recommendations->items(), [
            'current_page' => $recommendations->currentPage(),
            'per_page' => $recommendations->perPage(),
            'total' => $recommendations->total(),
            'last_page' => $recommendations->lastPage(),
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

        $engine = new RecommendationEngine($website);
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
}
