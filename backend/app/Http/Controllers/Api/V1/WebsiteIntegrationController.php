<?php

namespace App\Http\Controllers\Api\V1;

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

class WebsiteIntegrationController extends Controller
{
    use ApiResponseTrait;

    /**
     * List integrations for a website.
     */
    public function index(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $integrations = WebsiteIntegration::where('website_id', $website->id)
            ->get()
            ->map(fn(WebsiteIntegration $i) => $this->formatIntegration($i));

        return $this->success($integrations);
    }

    /**
     * Connect or update an integration.
     * Enforces ownership verification before write integration credentials can be saved.
     * Never stores or returns credentials as plaintext.
     */
    public function store(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->canManageIntegrations()) {
            return $this->error('Insufficient permissions to configure integrations.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        // Enforce ownership verification before enabling write integrations
        if (!$website->isVerified()) {
            return $this->error(
                'Website ownership must be verified before connecting write integrations. Please verify via Meta Tag or DNS first.',
                'WEBSITE_UNVERIFIED',
                ['website_id' => $website->id, 'verification_status' => $website->verification_status],
                422
            );
        }

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(WebsiteIntegration::PROVIDERS)],
            'credentials' => ['required', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $provider = $validated['provider'];
        $rawCredentials = $validated['credentials'];

        // Validate provider-specific credential fields
        if ($provider === WebsiteIntegration::PROVIDER_WORDPRESS) {
            if (empty($rawCredentials['username']) || empty($rawCredentials['application_password'])) {
                return $this->error('WordPress integration requires username and application_password.', 'INVALID_CREDENTIALS', null, 422);
            }
        } elseif ($provider === WebsiteIntegration::PROVIDER_SHOPIFY) {
            if (empty($rawCredentials['shop_domain']) || empty($rawCredentials['access_token'])) {
                return $this->error('Shopify integration requires shop_domain and access_token.', 'INVALID_CREDENTIALS', null, 422);
            }
        }

        $integration = WebsiteIntegration::where('website_id', $website->id)
            ->where('provider', $provider)
            ->first();

        if (!$integration) {
            $integration = new WebsiteIntegration([
                'organization_id' => $org->id,
                'website_id' => $website->id,
                'provider' => $provider,
            ]);
        }

        // Save encrypted credentials
        $integration->encrypted_credentials = $rawCredentials;
        $integration->status = WebsiteIntegration::STATUS_PENDING;
        $integration->metadata = array_merge($validated['metadata'] ?? [], [
            'configured_at' => now()->toIso8601String(),
        ]);
        $integration->save();

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'integration.configured',
            $integration,
            null,
            ['provider' => $provider, 'website_id' => $website->id],
            $request
        );

        return $this->success($this->formatIntegration($integration), null, 201);
    }

    /**
     * Test integration connection status live.
     * Do not report fake success; accurately reflect connected, pending, or failed.
     */
    public function testConnection(Request $request, int $websiteId, string $provider): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $integration = WebsiteIntegration::where('website_id', $website->id)
            ->where('provider', $provider)
            ->first();

        if (!$integration) {
            return $this->error('Integration not configured.', 'INTEGRATION_NOT_FOUND', null, 404);
        }

        $credentials = $integration->getDecryptedCredentials();

        if (!$credentials) {
            $integration->update([
                'status' => WebsiteIntegration::STATUS_FAILED,
                'last_error' => 'Stored credentials could not be decrypted.',
            ]);
            return $this->error('Credential decryption failed.', 'DECRYPTION_ERROR', null, 500);
        }

        $isConnected = false;
        $error = null;

        if ($provider === WebsiteIntegration::PROVIDER_WORDPRESS) {
            try {
                $endpoint = rtrim($website->target_url, '/') . '/wp-json/wp/v2/users/me';
                $res = Http::withBasicAuth($credentials['username'], $credentials['application_password'])
                    ->timeout(10)
                    ->get($endpoint);

                if ($res->successful()) {
                    $isConnected = true;
                    $userData = $res->json();
                    $metadata = $integration->metadata ?? [];
                    $metadata['wp_user'] = $userData['name'] ?? $credentials['username'];
                    $integration->metadata = $metadata;
                } else {
                    $error = 'WordPress API responded with HTTP ' . $res->status() . ': ' . ($res->json()['message'] ?? 'Unauthorized');
                }
            } catch (Throwable $e) {
                $error = 'Connection failed: ' . $e->getMessage();
            }
        } elseif ($provider === WebsiteIntegration::PROVIDER_SHOPIFY) {
            try {
                $shop = trim($credentials['shop_domain']);
                $endpoint = "https://{$shop}/admin/api/2024-01/shop.json";
                $res = Http::withHeaders(['X-Shopify-Access-Token' => $credentials['access_token']])
                    ->timeout(10)
                    ->get($endpoint);

                if ($res->successful()) {
                    $isConnected = true;
                    $shopData = $res->json('shop') ?? [];
                    $metadata = $integration->metadata ?? [];
                    $metadata['shop_name'] = $shopData['name'] ?? $shop;
                    $integration->metadata = $metadata;
                } else {
                    $error = 'Shopify API responded with HTTP ' . $res->status() . ': ' . ($res->json()['errors'] ?? 'Unauthorized');
                }
            } catch (Throwable $e) {
                $error = 'Connection failed: ' . $e->getMessage();
            }
        }

        $newStatus = $isConnected ? WebsiteIntegration::STATUS_CONNECTED : WebsiteIntegration::STATUS_FAILED;
        $integration->update([
            'status' => $newStatus,
            'last_error' => $error,
            'last_synced_at' => $isConnected ? now() : $integration->last_synced_at,
        ]);

        if ($isConnected) {
            $website->update(['connection_status' => Website::STATUS_CONNECTED]);
        }

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'integration.tested',
            $integration,
            null,
            ['status' => $newStatus, 'error' => $error],
            $request
        );

        return $this->success([
            'status' => $newStatus,
            'connected' => $isConnected,
            'last_error' => $error,
            'last_synced_at' => $integration->last_synced_at?->toIso8601String(),
        ]);
    }

    /**
     * Disconnect an integration.
     */
    public function disconnect(Request $request, int $websiteId, string $provider): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->canManageIntegrations()) {
            return $this->error('Insufficient permissions to disconnect integrations.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $integration = WebsiteIntegration::where('website_id', $website->id)
            ->where('provider', $provider)
            ->first();

        if ($integration) {
            AuditLog::record(
                $org->id,
                $request->user()->id,
                'integration.disconnected',
                $integration,
                ['provider' => $provider],
                null,
                $request
            );

            $integration->delete();
        }

        // Check if other integrations exist
        $remaining = WebsiteIntegration::where('website_id', $website->id)
            ->where('status', WebsiteIntegration::STATUS_CONNECTED)
            ->count();

        if ($remaining === 0) {
            $website->update(['connection_status' => Website::STATUS_DISCONNECTED]);
        }

        return $this->success(['message' => 'Integration disconnected successfully.']);
    }

    /**
     * Format integration response securely (NEVER exposes secrets).
     */
    protected function formatIntegration(WebsiteIntegration $i): array
    {
        return [
            'id' => $i->id,
            'website_id' => $i->website_id,
            'provider' => $i->provider,
            'status' => $i->status,
            'last_error' => $i->last_error,
            'last_synced_at' => $i->last_synced_at?->toIso8601String(),
            'metadata' => $i->metadata ?? [],
            'created_at' => $i->created_at->toIso8601String(),
            'updated_at' => $i->updated_at->toIso8601String(),
        ];
    }
}
