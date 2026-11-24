<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\QuotaEnforcementService;
use App\Domain\Reports\WhiteLabelReportService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhiteLabelReportController extends Controller
{
    use ApiResponseTrait;

    protected WhiteLabelReportService $reportService;
    protected QuotaEnforcementService $quotaService;

    public function __construct(WhiteLabelReportService $reportService, QuotaEnforcementService $quotaService)
    {
        $this->reportService = $reportService;
        $this->quotaService = $quotaService;
    }

    /**
     * Generate structured white-labeled agency report for a website.
     */
    public function getReport(Request $request, Website $website): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        if ($website->organization_id !== $org->id) {
            return $this->error('Website does not belong to this organization.', 'NOT_FOUND', null, 404);
        }

        // Quota check: White-label export capability
        try {
            $this->quotaService->assertCanExportWhiteLabel($org);
        } catch (\App\Domain\Billing\Exceptions\QuotaExceededException $e) {
            return $this->error($e->getMessage(), 'QUOTA_EXCEEDED', [
                'quota_type' => $e->getQuotaType(),
                'required_plan' => 'agency_pro',
            ], 403);
        }

        $report = $this->reportService->generateReport($website);

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'report.white_label_generated',
            $website,
            null,
            ['report_id' => $report['report_id']]
        );

        return $this->success($report);
    }

    /**
     * Get active white-label branding settings for the organization.
     */
    public function getBranding(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        return $this->success($org->getWhiteLabelSettings());
    }

    /**
     * Update agency white-label branding settings.
     */
    public function updateBranding(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->hasMinimumRole(OrganizationMember::ROLE_SEO_MANAGER)) {
            return $this->error('Only owners, admins, or SEO managers can modify white-label branding.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $validated = $request->validate([
            'brand_name' => ['required', 'string', 'max:255'],
            'logo_url' => [
                'nullable',
                'string',
                'max:700000',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === null || trim((string) $value) === '') {
                        return;
                    }
                    $str = trim((string) $value);
                    $isHttpUrl = filter_var($str, FILTER_VALIDATE_URL) !== false
                        && preg_match('#^https?://#i', $str) === 1;
                    $isSafeDataUri = preg_match('#^data:image/(png|jpeg|jpg|webp);base64,[A-Za-z0-9+/=\s]+$#i', $str) === 1;

                    if (!$isHttpUrl && !$isSafeDataUri) {
                        $fail('The logo must be a valid image upload (PNG/JPG/WebP) or http(s) image URL.');
                    }
                },
            ],
            'primary_color' => ['nullable', 'regex:/^#([a-f0-9]{6}|[a-f0-9]{3})$/i'],
            'contact_email' => ['nullable', 'email'],
            'footer_text' => ['nullable', 'string', 'max:500'],
            'remove_platform_branding' => ['nullable', 'boolean'],
        ]);

        $cleanLogo = isset($validated['logo_url']) && trim((string) $validated['logo_url']) !== ''
            ? trim((string) $validated['logo_url'])
            : null;

        $settings = $org->settings ?? [];
        $settings['white_label'] = [
            'brand_name' => $validated['brand_name'],
            'logo_url' => $cleanLogo,
            'primary_color' => $validated['primary_color'] ?? '#10b981',
            'contact_email' => $validated['contact_email'] ?? null,
            'footer_text' => $validated['footer_text'] ?? 'Confidential SEO Strategy & Technical Audit Report',
            'remove_platform_branding' => (bool) ($validated['remove_platform_branding'] ?? false),
        ];

        $org->update(['settings' => $settings]);

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'white_label.updated',
            $org,
            null,
            [
                'brand_name' => $settings['white_label']['brand_name'],
                'has_logo' => $cleanLogo !== null,
                'primary_color' => $settings['white_label']['primary_color'],
            ]
        );

        return $this->success($settings['white_label']);
    }
}
