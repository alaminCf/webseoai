<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Throwable;

class WebsiteController extends Controller
{
    use ApiResponseTrait;

    /**
     * List all websites in the active organization.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $query = Website::where('organization_id', $org->id);

        if ($request->boolean('archived', false)) {
            $query->archived();
        } else {
            $query->active();
        }

        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }

        if ($request->filled('connection_status')) {
            $query->where('connection_status', $request->input('connection_status'));
        }

        $websites = $query->orderBy('created_at', 'desc')->get()->map(function (Website $w) {
            return $this->formatWebsite($w);
        });

        return $this->success($websites);
    }

    /**
     * Add a new website to the organization.
     * Minimum role: Editor.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->canManageWebsites()) {
            return $this->error('Your role does not have permission to add websites.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        // Auto-normalize domain and target_url so inputs like "example.com" or "https://example.com/" both work cleanly
        $rawDomain = trim((string) $request->input('domain', ''));
        $rawTargetUrl = trim((string) $request->input('target_url', ''));

        if ($rawTargetUrl === '' && $rawDomain !== '') {
            $rawTargetUrl = preg_match('#^https?://#i', $rawDomain)
                ? $rawDomain
                : 'https://' . ltrim($rawDomain, '/');
        } elseif ($rawTargetUrl !== '' && !preg_match('#^https?://#i', $rawTargetUrl)) {
            $rawTargetUrl = 'https://' . ltrim($rawTargetUrl, '/');
        }

        if ($rawDomain !== '' && preg_match('#^https?://#i', $rawDomain)) {
            $parsedHost = parse_url($rawDomain, PHP_URL_HOST);
            if ($parsedHost) {
                $rawDomain = $parsedHost;
            }
        }

        $request->merge([
            'domain' => strtolower(rtrim($rawDomain, '/')),
            'target_url' => rtrim($rawTargetUrl, '/'),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255'],
            'target_url' => ['required', 'url', 'max:1024'],
            'platform' => ['required', 'string', Rule::in(Website::PLATFORMS)],
            'timezone' => ['nullable', 'string', 'max:50'],
            'preferred_language' => ['nullable', 'string', 'max:10'],
            'target_country' => ['nullable', 'string', 'max:10'],
            'settings' => ['nullable', 'array'],
        ]);

        // Normalize domain
        $normalizedDomain = strtolower(trim(parse_url($validated['target_url'], PHP_URL_HOST) ?? $validated['domain']));

        // Check unique per organization
        $existing = Website::where('organization_id', $org->id)
            ->where('domain', $normalizedDomain)
            ->first();

        if ($existing) {
            return $this->error(
                'A website with this domain already exists in your organization.',
                'WEBSITE_EXISTS',
                ['existing_website' => $this->formatWebsite($existing, true)],
                422
            );
        }

        // Enforce SaaS plan website limits
        try {
            app(\App\Domain\Billing\QuotaEnforcementService::class)->assertCanAddWebsite($org);
        } catch (\App\Domain\Billing\Exceptions\QuotaExceededException $e) {
            return $this->error($e->getMessage(), 'QUOTA_EXCEEDED', [
                'quota_type' => $e->getQuotaType(),
                'current' => $e->getCurrentUsage(),
                'limit' => $e->getLimit(),
            ], 403);
        }

        $website = Website::create([
            'organization_id' => $org->id,
            'name' => $validated['name'],
            'domain' => $normalizedDomain,
            'target_url' => rtrim($validated['target_url'], '/'),
            'platform' => $validated['platform'],
            'timezone' => $validated['timezone'] ?? 'UTC',
            'preferred_language' => $validated['preferred_language'] ?? 'en',
            'target_country' => $validated['target_country'] ?? 'US',
            'connection_status' => Website::STATUS_DISCONNECTED,
            'verification_status' => Website::VERIFICATION_UNVERIFIED,
            'settings' => $validated['settings'] ?? [],
        ]);

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'website.created',
            $website,
            null,
            ['name' => $website->name, 'domain' => $website->domain, 'platform' => $website->platform],
            $request
        );

        return $this->success($this->formatWebsite($website, true), null, 201);
    }

    /**
     * Get specific website details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $id)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        return $this->success($this->formatWebsite($website, true));
    }

    /**
     * Edit website details.
     * Minimum role: Editor.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->canManageWebsites()) {
            return $this->error('Your role does not have permission to edit websites.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $website = Website::where('organization_id', $org->id)->where('id', $id)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'target_url' => ['sometimes', 'url', 'max:1024'],
            'platform' => ['sometimes', 'string', Rule::in(Website::PLATFORMS)],
            'timezone' => ['sometimes', 'string', 'max:50'],
            'preferred_language' => ['sometimes', 'string', 'max:10'],
            'target_country' => ['sometimes', 'string', 'max:10'],
            'settings' => ['nullable', 'array'],
        ]);

        $old = $website->only(['name', 'target_url', 'platform', 'timezone', 'preferred_language', 'target_country']);
        $website->update($validated);

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'website.updated',
            $website,
            $old,
            $website->only(array_keys($validated)),
            $request
        );

        return $this->success($this->formatWebsite($website, true));
    }

    /**
     * Archive website (Soft hide from active views).
     * Minimum role: Admin.
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->canDeleteWebsites()) {
            return $this->error('Only owners and admins can archive websites.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $website = Website::where('organization_id', $org->id)->where('id', $id)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $website->update(['is_archived' => true]);

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'website.archived',
            $website,
            ['is_archived' => false],
            ['is_archived' => true],
            $request
        );

        return $this->success(['message' => 'Website successfully archived.']);
    }

    /**
     * Restore archived website.
     * Minimum role: Admin.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->canDeleteWebsites()) {
            return $this->error('Only owners and admins can restore websites.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $website = Website::where('organization_id', $org->id)->where('id', $id)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $website->update(['is_archived' => false]);

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'website.restored',
            $website,
            ['is_archived' => true],
            ['is_archived' => false],
            $request
        );

        return $this->success(['message' => 'Website successfully restored.']);
    }

    /**
     * Permanently delete website.
     * Minimum role: Admin.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->canDeleteWebsites()) {
            return $this->error('Only owners and admins can permanently delete websites.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $website = Website::where('organization_id', $org->id)->where('id', $id)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'website.deleted',
            $website,
            ['name' => $website->name, 'domain' => $website->domain],
            null,
            $request
        );

        $website->delete();

        return $this->success(['message' => 'Website permanently deleted.']);
    }

    /**
     * Verify website ownership via Meta Tag, DNS TXT, or File Token.
     */
    public function verifyOwnership(Request $request, int $id): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->canManageWebsites()) {
            return $this->error('Insufficient permissions to verify ownership.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $website = Website::where('organization_id', $org->id)->where('id', $id)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in(['meta_tag', 'dns_txt', 'file_token', 'manual_override'])],
        ]);

        $verified = false;
        $reason = null;

        // Manual override permitted only for owners/admins in local/internal agency mode
        if ($validated['method'] === 'manual_override') {
            if ($membership->isAdmin()) {
                $verified = true;
                $website->update([
                    'verification_status' => Website::VERIFICATION_VERIFIED,
                    'verification_method' => 'manual_override',
                    'verified_at' => now(),
                ]);
            } else {
                return $this->error('Manual verification override requires admin permissions.', 'INSUFFICIENT_PERMISSIONS', null, 403);
            }
        } elseif ($validated['method'] === 'meta_tag') {
            try {
                $response = Http::timeout(10)->get($website->target_url);
                if ($response->successful() && str_contains($response->body(), $website->verification_token)) {
                    $verified = true;
                    $website->update([
                        'verification_status' => Website::VERIFICATION_VERIFIED,
                        'verification_method' => 'meta_tag',
                        'verified_at' => now(),
                    ]);
                } else {
                    $reason = 'Verification meta tag was not detected on the homepage HTML.';
                }
            } catch (Throwable $e) {
                $reason = 'Could not reach target URL: ' . $e->getMessage();
            }
        } elseif ($validated['method'] === 'dns_txt') {
            try {
                $records = dns_get_record($website->domain, DNS_TXT);
                $expected = 'web-seo-ai-site-verification=' . $website->verification_token;
                foreach ($records as $r) {
                    if (isset($r['txt']) && str_contains($r['txt'], $expected)) {
                        $verified = true;
                        break;
                    }
                }
                if ($verified) {
                    $website->update([
                        'verification_status' => Website::VERIFICATION_VERIFIED,
                        'verification_method' => 'dns_txt',
                        'verified_at' => now(),
                    ]);
                } else {
                    $reason = 'Expected DNS TXT record not found for ' . $website->domain;
                }
            } catch (Throwable $e) {
                $reason = 'DNS query failed: ' . $e->getMessage();
            }
        }

        if ($verified) {
            AuditLog::record(
                $org->id,
                $request->user()->id,
                'website.ownership_verified',
                $website,
                null,
                ['method' => $validated['method']],
                $request
            );

            return $this->success([
                'verified' => true,
                'website' => $this->formatWebsite($website, true),
                'message' => 'Website ownership successfully verified! Write integrations are now unlocked.',
            ]);
        }

        return $this->error(
            $reason ?? 'Verification check failed. Please ensure the token is published and try again.',
            'VERIFICATION_FAILED',
            ['method' => $validated['method']],
            422
        );
    }

    /**
     * Format website model for API serialization.
     */
    protected function formatWebsite(Website $w, bool $includeVerificationDetails = false): array
    {
        $data = [
            'id' => $w->id,
            'name' => $w->name,
            'domain' => $w->domain,
            'target_url' => $w->target_url,
            'platform' => $w->platform,
            'timezone' => $w->timezone,
            'preferred_language' => $w->preferred_language,
            'target_country' => $w->target_country,
            'connection_status' => $w->connection_status,
            'verification_status' => $w->verification_status,
            'verified_at' => $w->verified_at?->toIso8601String(),
            'last_crawled_at' => $w->last_crawled_at?->toIso8601String(),
            'last_audited_at' => $w->last_audited_at?->toIso8601String(),
            'health_score' => $w->health_score,
            'settings' => $w->settings ?? [],
            'is_archived' => $w->is_archived,
            'created_at' => $w->created_at->toIso8601String(),
            'updated_at' => $w->updated_at->toIso8601String(),
        ];

        if ($includeVerificationDetails) {
            $data['verification'] = [
                'token' => $w->verification_token,
                'status' => $w->verification_status,
                'meta_tag' => $w->meta_tag_snippet,
                'dns_txt' => $w->dns_txt_snippet,
                'file_token' => $w->file_token_snippet,
            ];
        }

        return $data;
    }
}
