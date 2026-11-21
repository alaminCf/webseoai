<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CrawledPage;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Website;
use App\Models\WebsiteContextProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContextProfileController extends Controller
{
    use ApiResponseTrait;

    /**
     * Get or initialize the website context profile.
     */
    public function getProfile(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();
        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $profile = WebsiteContextProfile::with('approver:id,name,email')
            ->firstOrCreate(
                ['website_id' => $website->id],
                [
                    'organization_id' => $org->id,
                    'business_name' => $website->name,
                    'business_description' => "Web property operating under {$website->domain}",
                    'brand_voice' => 'Professional, authoritative, and helpful',
                    'target_countries' => [$website->target_country ?? 'US'],
                    'target_languages' => [$website->preferred_language ?? 'en'],
                    'business_goals' => ['Increase organic traffic', 'Improve search visibility'],
                ]
            );

        // Aggregate connected website summary
        $pagesCount = CrawledPage::where('website_id', $website->id)->count();

        return $this->success([
            'profile' => $profile,
            'connected_summary' => [
                'domain' => $website->domain,
                'platform' => $website->platform,
                'health_score' => $website->health_score,
                'total_crawled_pages' => $pagesCount,
                'last_crawled_at' => $website->last_crawled_at?->toIso8601String(),
                'is_verified' => $website->isVerified(),
            ],
        ]);
    }

    /**
     * Update website context profile details.
     */
    public function updateProfile(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->hasMinimumRole(OrganizationMember::ROLE_EDITOR)) {
            return $this->error('Insufficient permissions to edit context profile.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();
        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'business_name' => ['nullable', 'string', 'max:255'],
            'business_description' => ['nullable', 'string', 'max:5000'],
            'target_audience' => ['nullable', 'string', 'max:3000'],
            'products_and_services' => ['nullable', 'string', 'max:3000'],
            'target_countries' => ['nullable', 'array'],
            'target_languages' => ['nullable', 'array'],
            'brand_voice' => ['nullable', 'string', 'max:1000'],
            'business_goals' => ['nullable', 'array'],
            'competitors' => ['nullable', 'array'],
        ]);

        $profile = WebsiteContextProfile::firstOrCreate(
            ['website_id' => $website->id],
            ['organization_id' => $org->id]
        );

        $profile->update($validated);

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'context_profile.updated',
            $profile,
            null,
            ['business_name' => $profile->business_name],
            $request
        );

        return $this->success($profile);
    }

    /**
     * Explicitly approve the website context profile.
     */
    public function approveProfile(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->hasMinimumRole(OrganizationMember::ROLE_SEO_MANAGER)) {
            return $this->error('Only SEO Managers, Admins, or Owners can approve context profiles.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();
        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $profile = WebsiteContextProfile::where('website_id', $website->id)->first();
        if (!$profile) {
            return $this->error('Profile not found.', 'PROFILE_NOT_FOUND', null, 404);
        }

        $profile->update([
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'context_profile.approved',
            $profile,
            null,
            ['approved_by' => $request->user()->id],
            $request
        );

        return $this->success($profile->load('approver:id,name,email'));
    }
}
