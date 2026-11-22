<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\SearchConsole\GoogleSearchConsoleService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Website;
use App\Models\WebsiteIntegration;
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
            return $this->formatWebsite($w, true);
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
     * Verify website ownership via Google Search Console, CMS Credentials, Meta Tag, DNS TXT, File Token, or Agency Override.
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

        $token = $website->ensureVerificationToken();

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in([
                'google_search_console',
                'cms_credentials',
                'meta_tag',
                'dns_txt',
                'file_token',
                'manual_override',
            ])],
            'provider' => ['nullable', 'string', Rule::in([
                WebsiteIntegration::PROVIDER_WORDPRESS,
                WebsiteIntegration::PROVIDER_SHOPIFY,
            ])],
            'credentials' => ['nullable', 'array'],
        ]);

        $verified = false;
        $verifiedMethod = $validated['method'];
        $reason = null;
        $successMessage = 'Website ownership successfully verified! Write integrations are now unlocked.';

        // 1. Agency / Internal Instant Override (Owner, Admin, or SEO Manager)
        if ($validated['method'] === 'manual_override') {
            if ($membership->canManageIntegrations()) {
                $verified = true;
                $website->update([
                    'verification_status' => Website::VERIFICATION_VERIFIED,
                    'verification_method' => 'manual_override',
                    'verified_at' => now(),
                ]);
            } else {
                return $this->error(
                    'Instant agency verification requires Owner, Admin, or SEO Manager permissions.',
                    'INSUFFICIENT_PERMISSIONS',
                    null,
                    403
                );
            }
        }
        // 2. 1-Click Auto-Verify via Connected Google Search Console
        elseif ($validated['method'] === 'google_search_console') {
            /** @var GoogleSearchConsoleService $gscService */
            $gscService = app(GoogleSearchConsoleService::class);
            $matchedProperty = $gscService->findVerifiedPropertyForWebsite($website);

            if ($matchedProperty !== null) {
                $verified = true;
                $website->update([
                    'verification_status' => Website::VERIFICATION_VERIFIED,
                    'verification_method' => 'google_search_console',
                    'verified_at' => now(),
                ]);
                $successMessage = "Ownership verified automatically via Google Search Console ({$matchedProperty})!";
            } else {
                $integration = $website->gscIntegration;
                if (!$integration || $integration->status !== WebsiteIntegration::STATUS_CONNECTED) {
                    $reason = 'Google Search Console is not connected yet. Click "Connect Google Search Console & Auto-Verify" first.';
                } else {
                    $reason = "Your connected Google account does not have Site Owner / Full User access for {$website->domain} in Google Search Console.";
                }
            }
        }
        // 3. One-Step Verify & Connect via WordPress REST or Shopify Admin API
        elseif ($validated['method'] === 'cms_credentials') {
            if (!$membership->canManageIntegrations()) {
                return $this->error(
                    'CMS verification requires Owner, Admin, or SEO Manager permissions.',
                    'INSUFFICIENT_PERMISSIONS',
                    null,
                    403
                );
            }

            $provider = $validated['provider'] ?? ($website->platform === Website::PLATFORM_SHOPIFY
                ? WebsiteIntegration::PROVIDER_SHOPIFY
                : WebsiteIntegration::PROVIDER_WORDPRESS);
            $rawCredentials = $validated['credentials'] ?? [];

            if ($provider === WebsiteIntegration::PROVIDER_WORDPRESS) {
                $username = trim((string) ($rawCredentials['username'] ?? ''));
                $appPassword = trim((string) ($rawCredentials['application_password'] ?? ''));

                if ($username === '' || $appPassword === '') {
                    return $this->error('WordPress username and application password are required.', 'INVALID_CREDENTIALS', null, 422);
                }

                try {
                    $endpoint = rtrim($website->target_url, '/') . '/wp-json/wp/v2/users/me';
                    $res = Http::withBasicAuth($username, $appPassword)
                        ->withHeaders(['Accept' => 'application/json', 'User-Agent' => 'WebSEOAI-Verifier/1.0'])
                        ->timeout(12)
                        ->get($endpoint);

                    if ($res->successful()) {
                        $verified = true;
                        $verifiedMethod = WebsiteIntegration::PROVIDER_WORDPRESS;
                        $userData = $res->json() ?? [];

                        $website->update([
                            'verification_status' => Website::VERIFICATION_VERIFIED,
                            'verification_method' => WebsiteIntegration::PROVIDER_WORDPRESS,
                            'verified_at' => now(),
                            'connection_status' => Website::STATUS_CONNECTED,
                        ]);

                        WebsiteIntegration::updateOrCreate(
                            [
                                'website_id' => $website->id,
                                'provider' => WebsiteIntegration::PROVIDER_WORDPRESS,
                            ],
                            [
                                'organization_id' => $org->id,
                                'encrypted_credentials' => [
                                    'username' => $username,
                                    'application_password' => $appPassword,
                                ],
                                'status' => WebsiteIntegration::STATUS_CONNECTED,
                                'last_error' => null,
                                'last_synced_at' => now(),
                                'metadata' => [
                                    'configured_at' => now()->toIso8601String(),
                                    'wp_user' => $userData['name'] ?? $username,
                                ],
                            ]
                        );

                        $successMessage = 'WordPress credentials verified & connected! Website ownership is now verified.';
                    } else {
                        $reason = 'WordPress REST authentication failed (HTTP ' . $res->status() . '): ' . ($res->json('message') ?? 'Invalid username or application password.');
                    }
                } catch (Throwable $e) {
                    $reason = 'Could not reach WordPress REST API at ' . rtrim($website->target_url, '/') . '/wp-json/wp/v2/users/me: ' . $e->getMessage();
                }
            } elseif ($provider === WebsiteIntegration::PROVIDER_SHOPIFY) {
                $shopDomain = trim((string) ($rawCredentials['shop_domain'] ?? ''));
                $accessToken = trim((string) ($rawCredentials['access_token'] ?? ''));

                if ($shopDomain === '' || $accessToken === '') {
                    return $this->error('Shopify store domain and Admin API access token are required.', 'INVALID_CREDENTIALS', null, 422);
                }

                $shopHost = preg_replace('#^https?://#i', '', rtrim($shopDomain, '/'));
                try {
                    $endpoint = "https://{$shopHost}/admin/api/2024-01/shop.json";
                    $res = Http::withHeaders([
                        'X-Shopify-Access-Token' => $accessToken,
                        'Accept' => 'application/json',
                    ])->timeout(12)->get($endpoint);

                    if ($res->successful()) {
                        $verified = true;
                        $verifiedMethod = WebsiteIntegration::PROVIDER_SHOPIFY;
                        $shopData = $res->json('shop') ?? [];

                        $website->update([
                            'verification_status' => Website::VERIFICATION_VERIFIED,
                            'verification_method' => WebsiteIntegration::PROVIDER_SHOPIFY,
                            'verified_at' => now(),
                            'connection_status' => Website::STATUS_CONNECTED,
                        ]);

                        WebsiteIntegration::updateOrCreate(
                            [
                                'website_id' => $website->id,
                                'provider' => WebsiteIntegration::PROVIDER_SHOPIFY,
                            ],
                            [
                                'organization_id' => $org->id,
                                'encrypted_credentials' => [
                                    'shop_domain' => $shopHost,
                                    'access_token' => $accessToken,
                                ],
                                'status' => WebsiteIntegration::STATUS_CONNECTED,
                                'last_error' => null,
                                'last_synced_at' => now(),
                                'metadata' => [
                                    'configured_at' => now()->toIso8601String(),
                                    'shop_name' => $shopData['name'] ?? $shopHost,
                                ],
                            ]
                        );

                        $successMessage = 'Shopify Admin API verified & connected! Website ownership is now verified.';
                    } else {
                        $reason = 'Shopify API authentication failed (HTTP ' . $res->status() . '). Please verify your Admin API token.';
                    }
                } catch (Throwable $e) {
                    $reason = 'Could not reach Shopify Admin API: ' . $e->getMessage();
                }
            }
        }
        // 4. HTML Meta Tag Verification
        elseif ($validated['method'] === 'meta_tag') {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; WebSEOAI-Verifier/1.0)',
                ])->timeout(12)->get($website->target_url);

                if ($response->successful() && str_contains($response->body(), $token)) {
                    $verified = true;
                    $website->update([
                        'verification_status' => Website::VERIFICATION_VERIFIED,
                        'verification_method' => 'meta_tag',
                        'verified_at' => now(),
                    ]);
                } else {
                    $reason = 'Verification meta tag was not detected on ' . $website->target_url . '. Ensure the tag is inside <head> and any page cache is cleared.';
                }
            } catch (Throwable $e) {
                $reason = 'Could not reach target URL: ' . $e->getMessage();
            }
        }
        // 5. Verification File Upload Check
        elseif ($validated['method'] === 'file_token') {
            $fileSnippet = $website->file_token_snippet;
            $fileUrl = rtrim($website->target_url, '/') . '/' . ltrim($fileSnippet['filename'], '/');
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; WebSEOAI-Verifier/1.0)',
                ])->timeout(12)->get($fileUrl);

                if ($response->successful() && str_contains(trim($response->body()), $token)) {
                    $verified = true;
                    $website->update([
                        'verification_status' => Website::VERIFICATION_VERIFIED,
                        'verification_method' => 'file_token',
                        'verified_at' => now(),
                    ]);
                } else {
                    $reason = "Verification file was not found or did not contain the expected token at {$fileUrl} (HTTP {$response->status()}).";
                }
            } catch (Throwable $e) {
                $reason = "Could not fetch verification file at {$fileUrl}: " . $e->getMessage();
            }
        }
        // 6. DNS TXT Record Check
        elseif ($validated['method'] === 'dns_txt') {
            try {
                $rootDomain = preg_replace('/^www\./i', '', strtolower($website->domain));
                $records = @dns_get_record($rootDomain, DNS_TXT) ?: [];
                $expected = 'web-seo-ai-site-verification=' . $token;
                foreach ($records as $r) {
                    $txtValue = (string) ($r['txt'] ?? '');
                    if (str_contains($txtValue, $expected) || str_contains($txtValue, $token)) {
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
                    $reason = "Expected DNS TXT record not found for {$rootDomain}. DNS changes can take 2–15 minutes to propagate.";
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
                ['method' => $verifiedMethod],
                $request
            );

            return $this->success([
                'verified' => true,
                'website' => $this->formatWebsite($website->fresh(), true),
                'message' => $successMessage,
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
            $token = $w->ensureVerificationToken();
            $data['verification'] = [
                'token' => $token,
                'status' => $w->verification_status,
                'meta_tag' => $w->meta_tag_snippet,
                'dns_txt' => $w->dns_txt_snippet,
                'file_token' => $w->file_token_snippet,
            ];
        }

        return $data;
    }
}
