<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Competitors\Providers\SerperProvider;
use App\Domain\RankChecker\SerpCreditService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GlobalSerpCache;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PlatformApiKey;
use App\Models\PlatformSetting;
use App\Models\SerpCreditUsage;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private SerpCreditService $serpCreditService)
    {
    }

    /**
     * Platform-wide Super Admin KPI overview, credit telemetry, and recent activity.
     */
    public function overview(): JsonResponse
    {
        $monthStart = now()->startOfMonth();
        $todayStart = now()->startOfDay();

        $totalUsers = User::count();
        $activeUsers = User::where('status', '!=', 'suspended')->orWhereNull('status')->count();
        $suspendedUsers = User::where('status', 'suspended')->count();
        $totalOrgs = Organization::count();
        $totalWebsites = Website::count();
        $verifiedWebsites = Website::where('verification_status', Website::VERIFICATION_VERIFIED)->count();

        $poolKeys = PlatformApiKey::where('provider', 'serper')->orderBy('priority')->orderBy('id')->get();
        $activePoolKeys = $poolKeys->where('status', PlatformApiKey::STATUS_ACTIVE)->count();
        $exhaustedPoolKeys = $poolKeys->where('status', PlatformApiKey::STATUS_EXHAUSTED)->count();
        $poolTotalCapacity = (int) $poolKeys->where('status', PlatformApiKey::STATUS_ACTIVE)->sum('monthly_limit');
        $poolTotalUsed = (int) $poolKeys->where('status', PlatformApiKey::STATUS_ACTIVE)->sum('credits_used');
        $poolRemaining = max(0, $poolTotalCapacity - $poolTotalUsed);

        $hasLegacyKey = SerperProvider::resolvePlatformSharedApiKey() !== '';
        $hasEnvKey = SerperProvider::resolveEnvApiKey() !== '';

        $creditsToday = (int) SerpCreditUsage::where('cache_hit', false)
            ->where('created_at', '>=', $todayStart)
            ->sum('credits_used');
        $creditsMonth = (int) SerpCreditUsage::where('cache_hit', false)
            ->where('created_at', '>=', $monthStart)
            ->sum('credits_used');
        $totalChecksMonth = (int) SerpCreditUsage::where('created_at', '>=', $monthStart)->count();
        $cacheHitsMonth = (int) SerpCreditUsage::where('cache_hit', true)
            ->where('created_at', '>=', $monthStart)
            ->count();
        $cacheHitRate = $totalChecksMonth > 0 ? round(($cacheHitsMonth / $totalChecksMonth) * 100, 1) : 0.0;

        $activeCacheEntries = GlobalSerpCache::where('expires_at', '>', now())->count();
        $totalCreditsSaved = (int) GlobalSerpCache::sum('credits_saved');

        $recentUsers = User::latest()->limit(8)->get()->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'avatar_url' => $u->avatar_url,
            'auth_provider' => $u->auth_provider ?? 'email',
            'is_super_admin' => $u->isSuperAdmin(),
            'status' => $u->status ?? 'active',
            'created_at' => $u->created_at?->toIso8601String(),
        ]);

        $topWorkspaces = SerpCreditUsage::selectRaw('organization_id, SUM(CASE WHEN cache_hit = false THEN credits_used ELSE 0 END) as credits_used, COUNT(*) as total_checks, SUM(CASE WHEN cache_hit = true THEN 1 ELSE 0 END) as cache_hits')
            ->where('created_at', '>=', $monthStart)
            ->groupBy('organization_id')
            ->orderByDesc('credits_used')
            ->limit(8)
            ->get()
            ->map(function ($row) {
                $org = Organization::find($row->organization_id);
                return [
                    'organization_id' => $row->organization_id,
                    'organization_name' => $org?->name ?? 'Deleted Workspace',
                    'billing_plan' => $org?->billing_plan ?? 'starter',
                    'credits_used' => (int) $row->credits_used,
                    'total_checks' => (int) $row->total_checks,
                    'cache_hits' => (int) $row->cache_hits,
                ];
            });

        return $this->success([
            'kpis' => [
                'total_users' => $totalUsers,
                'active_users' => $activeUsers,
                'suspended_users' => $suspendedUsers,
                'total_organizations' => $totalOrgs,
                'total_websites' => $totalWebsites,
                'verified_websites' => $verifiedWebsites,
                'active_pool_keys' => $activePoolKeys,
                'exhausted_pool_keys' => $exhaustedPoolKeys,
                'pool_capacity' => $poolTotalCapacity,
                'pool_used' => $poolTotalUsed,
                'pool_remaining' => $poolRemaining,
                'has_legacy_key' => $hasLegacyKey,
                'has_env_key' => $hasEnvKey,
                'serp_credits_today' => $creditsToday,
                'serp_credits_month' => $creditsMonth,
                'serp_checks_month' => $totalChecksMonth,
                'cache_hits_month' => $cacheHitsMonth,
                'cache_hit_rate_pct' => $cacheHitRate,
                'active_cache_entries' => $activeCacheEntries,
                'total_credits_saved' => $totalCreditsSaved,
            ],
            'recent_users' => $recentUsers,
            'top_workspaces' => $topWorkspaces,
        ]);
    }

    /**
     * List all platform SERP API keys in the Multi-Key Auto-Failover Pool.
     */
    public function listSerpKeys(): JsonResponse
    {
        $keys = PlatformApiKey::where('provider', 'serper')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (PlatformApiKey $k) => $k->toAdminArray());

        $legacyIntegration = SerperProvider::resolvePlatformSharedIntegration();

        return $this->success([
            'keys' => $keys,
            'env_key_configured' => SerperProvider::resolveEnvApiKey() !== '',
            'legacy_key_available' => $legacyIntegration !== null,
            'legacy_key_hint' => $legacyIntegration?->metadata['key_hint'] ?? null,
        ]);
    }

    /**
     * Verify and add a new Serper.dev API key to the Multi-Key Failover Pool.
     */
    public function addSerpKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'min:2', 'max:120'],
            'api_key' => ['required', 'string', 'min:10', 'max:250'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:999'],
            'monthly_limit' => ['nullable', 'integer', 'min:100', 'max:10000000'],
            'skip_verify' => ['nullable', 'boolean'],
        ]);

        $rawKey = trim($validated['api_key']);
        if (empty($validated['skip_verify'])) {
            $serper = new SerperProvider();
            $check = $serper->verifyApiKey($rawKey);
            if (!($check['valid'] ?? false)) {
                return $this->error(
                    $check['error'] ?? 'Could not verify this Serper.dev API key.',
                    'SERPER_KEY_INVALID',
                    null,
                    422
                );
            }
        }

        $hint = strlen($rawKey) >= 4 ? '••••' . substr($rawKey, -4) : '••••';
        $nextPriority = (int) ($validated['priority'] ?? ((PlatformApiKey::max('priority') ?? 0) + 10));

        $key = PlatformApiKey::create([
            'provider' => 'serper',
            'label' => trim($validated['label']),
            'encrypted_key' => PlatformApiKey::encryptKey($rawKey),
            'key_hint' => $hint,
            'priority' => $nextPriority,
            'status' => PlatformApiKey::STATUS_ACTIVE,
            'monthly_limit' => (int) ($validated['monthly_limit'] ?? 2500),
            'credits_used' => 0,
            'total_requests' => 0,
            'created_by_user_id' => $request->user()->id,
        ]);

        return $this->success([
            'message' => "Added \"{$key->label}\" to the Multi-Key Failover Pool.",
            'key' => $key->toAdminArray(),
        ], null, 201);
    }

    /**
     * Import the existing agency_internal or server env Serper key into the managed Multi-Key Pool.
     */
    public function importLegacySerpKey(Request $request): JsonResponse
    {
        $rawKey = SerperProvider::resolvePlatformSharedApiKey();
        $label = 'Primary Serper Key (Imported)';
        if ($rawKey === '') {
            $rawKey = SerperProvider::resolveEnvApiKey();
            $label = 'Server Env Serper Key (Imported)';
        }

        if ($rawKey === '') {
            return $this->error('No existing legacy or environment Serper key found to import.', 'NO_LEGACY_KEY', null, 404);
        }

        // Prevent duplicate import
        foreach (PlatformApiKey::where('provider', 'serper')->get() as $existing) {
            if ($existing->getDecryptedKey() === $rawKey) {
                return $this->error('This Serper key is already in your Multi-Key Pool.', 'ALREADY_IMPORTED', null, 422);
            }
        }

        $hint = strlen($rawKey) >= 4 ? '••••' . substr($rawKey, -4) : '••••';

        $key = PlatformApiKey::create([
            'provider' => 'serper',
            'label' => $label,
            'encrypted_key' => PlatformApiKey::encryptKey($rawKey),
            'key_hint' => $hint,
            'priority' => 1,
            'status' => PlatformApiKey::STATUS_ACTIVE,
            'monthly_limit' => 2500,
            'credits_used' => 0,
            'total_requests' => 0,
            'created_by_user_id' => $request->user()->id,
        ]);

        return $this->success([
            'message' => 'Imported existing Serper key into the Multi-Key Pool.',
            'key' => $key->toAdminArray(),
        ], null, 201);
    }

    /**
     * Update a pool key's label, priority, monthly limit, status, or reset exhausted counter.
     */
    public function updateSerpKey(Request $request, PlatformApiKey $key): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'min:2', 'max:120'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'monthly_limit' => ['sometimes', 'integer', 'min:100', 'max:10000000'],
            'status' => ['sometimes', Rule::in([
                PlatformApiKey::STATUS_ACTIVE,
                PlatformApiKey::STATUS_DISABLED,
                PlatformApiKey::STATUS_EXHAUSTED,
            ])],
            'reset_usage' => ['sometimes', 'boolean'],
        ]);

        $updates = [];
        foreach (['label', 'priority', 'monthly_limit', 'status'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        if (!empty($validated['reset_usage'])) {
            $updates['credits_used'] = 0;
            $updates['status'] = PlatformApiKey::STATUS_ACTIVE;
            $updates['exhausted_at'] = null;
            $updates['last_error'] = null;
        } elseif (($updates['status'] ?? null) === PlatformApiKey::STATUS_ACTIVE) {
            $updates['exhausted_at'] = null;
            $updates['last_error'] = null;
        }

        $key->update($updates);

        return $this->success([
            'message' => 'API key settings updated.',
            'key' => $key->fresh()->toAdminArray(),
        ]);
    }

    /**
     * Remove a key from the Multi-Key Pool.
     */
    public function deleteSerpKey(PlatformApiKey $key): JsonResponse
    {
        $label = $key->label;
        $key->delete();

        return $this->success([
            'message' => "Removed \"{$label}\" from the Multi-Key Pool.",
        ]);
    }

    /**
     * Get DataForSEO v3 platform-wide credentials status (for Search Volume, KD, CPC & Backlinks).
     */
    public function getDataForSeo(): JsonResponse
    {
        $creds = \App\Domain\Competitors\Providers\DataForSeoProvider::resolveCredentials();
        $dbRow = PlatformApiKey::where('provider', 'dataforseo')
            ->where('status', PlatformApiKey::STATUS_ACTIVE)
            ->orderBy('priority')
            ->orderBy('id')
            ->first();

        return $this->success([
            'configured' => $creds['login'] !== '' && $creds['password'] !== '',
            'source' => $creds['source'],
            'login_hint' => $creds['login'] !== '' ? $creds['login'] : null,
            'updated_at' => $dbRow?->updated_at?->toIso8601String(),
            'last_error' => $dbRow?->last_error,
        ]);
    }

    /**
     * Live-verify and save encrypted platform-wide DataForSEO v3 credentials.
     */
    public function saveDataForSeo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'min:3', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'skip_verify' => ['nullable', 'boolean'],
        ]);

        $login = trim($validated['login']);
        $password = trim($validated['password']);
        $balance = null;

        if (empty($validated['skip_verify'])) {
            $provider = new \App\Domain\Competitors\Providers\DataForSeoProvider();
            $check = $provider->verifyCredentials($login, $password);
            if (!($check['valid'] ?? false)) {
                return $this->error(
                    $check['error'] ?? 'Could not verify DataForSEO credentials.',
                    'DATAFORSEO_INVALID',
                    null,
                    422
                );
            }
            $balance = $check['balance'] ?? null;
        }

        // Replace any existing active admin DataForSEO row cleanly
        PlatformApiKey::where('provider', 'dataforseo')->delete();

        $key = PlatformApiKey::create([
            'provider' => 'dataforseo',
            'label' => 'DataForSEO v3 Platform Account',
            'encrypted_key' => PlatformApiKey::encryptKey(json_encode([
                'login' => $login,
                'password' => $password,
            ])),
            'key_hint' => $login,
            'priority' => 1,
            'status' => PlatformApiKey::STATUS_ACTIVE,
            'monthly_limit' => 100000,
            'credits_used' => 0,
            'total_requests' => 0,
            'created_by_user_id' => $request->user()->id,
        ]);

        return $this->success([
            'message' => 'DataForSEO v3 verified and activated across all workspaces (Search Volume, KD, CPC & Backlinks unlocked).',
            'configured' => true,
            'source' => 'admin_panel',
            'login_hint' => $login,
            'balance' => $balance,
            'key_id' => $key->id,
        ], null, 201);
    }

    /**
     * Disconnect Admin Panel DataForSEO v3 credentials.
     */
    public function deleteDataForSeo(): JsonResponse
    {
        PlatformApiKey::where('provider', 'dataforseo')->delete();

        return $this->success([
            'message' => 'DataForSEO credentials disconnected.',
            'configured' => \App\Domain\Competitors\Providers\DataForSeoProvider::resolveCredentials()['login'] !== '',
        ]);
    }

    /**
     * Get all platform settings (plan quotas, smart cache config, registration controls).
     */
    public function getSettings(): JsonResponse
    {
        return $this->success([
            'settings' => PlatformSetting::getAllMerged(),
            'cache_stats' => [
                'active_entries' => GlobalSerpCache::where('expires_at', '>', now())->count(),
                'total_entries' => GlobalSerpCache::count(),
                'total_hits' => (int) GlobalSerpCache::sum('hit_count'),
                'total_credits_saved' => (int) GlobalSerpCache::sum('credits_saved'),
            ],
        ]);
    }

    /**
     * Update platform settings from the Super Admin Control Panel.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'serp_quotas' => ['sometimes', 'array'],
            'serp_quotas.*.daily' => ['required_with:serp_quotas', 'integer', 'min:0', 'max:999999'],
            'serp_quotas.*.monthly' => ['required_with:serp_quotas', 'integer', 'min:0', 'max:9999999'],
            'serp_cache' => ['sometimes', 'array'],
            'serp_cache.enabled' => ['sometimes', 'boolean'],
            'serp_cache.ttl_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'serp_cache.default_depth' => ['sometimes', 'integer', Rule::in([20, 30, 50, 100])],
            'registration' => ['sometimes', 'array'],
            'registration.allow_public_registration' => ['sometimes', 'boolean'],
            'registration.default_signup_plan' => ['sometimes', Rule::in(['starter', 'agency_pro', 'enterprise'])],
        ]);

        foreach (['serp_quotas', 'serp_cache', 'registration'] as $section) {
            if (isset($validated[$section])) {
                $merged = array_replace_recursive(PlatformSetting::getValue($section), $validated[$section]);
                PlatformSetting::setValue($section, $merged);
            }
        }

        return $this->success([
            'message' => 'Platform settings saved and applied live.',
            'settings' => PlatformSetting::getAllMerged(),
        ]);
    }

    /**
     * Flush all Smart Cross-User SERP Cache entries immediately.
     */
    public function flushCache(): JsonResponse
    {
        $deleted = GlobalSerpCache::count();
        GlobalSerpCache::query()->delete();

        return $this->success([
            'message' => "Flushed {$deleted} cached SERP entries.",
            'deleted' => $deleted,
        ]);
    }

    /**
     * List all platform users with search, workspace count, website count, and status.
     */
    public function listUsers(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $query = User::with(['memberships.organization'])
            ->latest();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        $users = $query->limit(200)->get()->map(function (User $u) {
            $orgIds = $u->memberships->pluck('organization_id')->unique()->values();
            $websitesCount = $orgIds->isNotEmpty()
                ? Website::whereIn('organization_id', $orgIds)->count()
                : 0;

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar_url' => $u->avatar_url,
                'job_title' => $u->job_title,
                'auth_provider' => $u->auth_provider ?? ($u->google_id ? 'google' : 'email'),
                'google_connected' => !empty($u->google_id),
                'is_super_admin' => $u->isSuperAdmin(),
                'explicit_super_admin' => (bool) $u->is_super_admin,
                'status' => $u->status ?? 'active',
                'workspaces_count' => $orgIds->count(),
                'websites_count' => $websitesCount,
                'workspaces' => $u->memberships
                    ->filter(fn ($m) => $m->organization !== null)
                    ->map(fn ($m) => [
                        'id' => $m->organization->id,
                        'name' => $m->organization->name,
                        'plan' => $m->organization->billing_plan,
                        'role' => $m->role,
                    ])->values(),
                'created_at' => $u->created_at?->toIso8601String(),
            ];
        });

        return $this->success(['users' => $users]);
    }

    /**
     * Update a user's status (active / suspended) or Super Admin privilege.
     */
    public function updateUser(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'is_super_admin' => ['sometimes', 'boolean'],
        ]);

        if ((int) $user->id === (int) $request->user()->id) {
            if (($validated['status'] ?? null) === 'suspended') {
                return $this->error('You cannot suspend your own Super Admin account.', 'SELF_ACTION_BLOCKED', null, 422);
            }
            if (array_key_exists('is_super_admin', $validated) && !$validated['is_super_admin']) {
                return $this->error('You cannot remove Super Admin privileges from yourself.', 'SELF_ACTION_BLOCKED', null, 422);
            }
        }

        $updates = [];
        if (array_key_exists('status', $validated)) {
            $updates['status'] = $validated['status'];
            if ($validated['status'] === 'suspended') {
                $user->tokens()->delete(); // Immediately revoke active API tokens
            }
        }
        if (array_key_exists('is_super_admin', $validated)) {
            $updates['is_super_admin'] = (bool) $validated['is_super_admin'];
        }

        $user->update($updates);

        return $this->success([
            'message' => "Updated user {$user->name}.",
            'user' => $user->fresh()->toProfileArray(),
        ]);
    }

    /**
     * Permanently delete a user account (Super Admin only; self-deletion blocked).
     */
    public function deleteUser(Request $request, User $user): JsonResponse
    {
        if ((int) $user->id === (int) $request->user()->id) {
            return $this->error('You cannot delete your own account from Admin Panel.', 'SELF_ACTION_BLOCKED', null, 422);
        }

        $email = $user->email;
        $user->tokens()->delete();
        $user->delete();

        return $this->success([
            'message' => "Deleted user {$email}.",
        ]);
    }

    /**
     * List all workspaces/organizations with plan, websites, members, and real-time SERP usage.
     */
    public function listOrganizations(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $query = Organization::withCount(['websites', 'members'])
            ->latest();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('slug', 'LIKE', "%{$search}%");
            });
        }

        $orgs = $query->limit(200)->get()->map(function (Organization $org) {
            $ownerMembership = OrganizationMember::with('user')
                ->where('organization_id', $org->id)
                ->where('role', OrganizationMember::ROLE_OWNER)
                ->first();

            $quota = $this->serpCreditService->getQuotaStatus($org);

            return [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->slug,
                'billing_plan' => $org->billing_plan ?? 'starter',
                'websites_count' => (int) $org->websites_count,
                'members_count' => (int) $org->members_count,
                'owner' => $ownerMembership?->user ? [
                    'id' => $ownerMembership->user->id,
                    'name' => $ownerMembership->user->name,
                    'email' => $ownerMembership->user->email,
                ] : null,
                'serp_quota' => $quota,
                'serp_quota_override' => $org->settings['serp_quota_override'] ?? null,
                'created_at' => $org->created_at?->toIso8601String(),
            ];
        });

        return $this->success(['organizations' => $orgs]);
    }

    /**
     * Update a workspace's billing plan, custom SERP quota override, or reset its monthly SERP usage.
     */
    public function updateOrganization(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'billing_plan' => ['sometimes', Rule::in(['starter', 'agency_pro', 'enterprise', 'agency_internal'])],
            'serp_quota_override' => ['sometimes', 'nullable', 'array'],
            'serp_quota_override.daily' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'serp_quota_override.monthly' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'reset_serp_usage' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['billing_plan'])) {
            $organization->billing_plan = $validated['billing_plan'];
        }

        if (array_key_exists('serp_quota_override', $validated)) {
            $settings = is_array($organization->settings) ? $organization->settings : [];
            if ($validated['serp_quota_override'] === null || empty(array_filter($validated['serp_quota_override'], fn ($v) => $v !== null))) {
                unset($settings['serp_quota_override']);
            } else {
                $settings['serp_quota_override'] = [
                    'daily' => isset($validated['serp_quota_override']['daily']) ? (int) $validated['serp_quota_override']['daily'] : null,
                    'monthly' => isset($validated['serp_quota_override']['monthly']) ? (int) $validated['serp_quota_override']['monthly'] : null,
                ];
            }
            $organization->settings = $settings;
        }

        $organization->save();

        if (!empty($validated['reset_serp_usage'])) {
            SerpCreditUsage::where('organization_id', $organization->id)
                ->where('created_at', '>=', now()->startOfMonth())
                ->delete();
        }

        return $this->success([
            'message' => "Updated workspace \"{$organization->name}\".",
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'billing_plan' => $organization->billing_plan,
                'serp_quota' => $this->serpCreditService->getQuotaStatus($organization->fresh()),
                'serp_quota_override' => $organization->settings['serp_quota_override'] ?? null,
            ],
        ]);
    }

    /**
     * Delete a workspace (blocked for the admin's own active agency_internal workspace).
     */
    public function deleteOrganization(Request $request, Organization $organization): JsonResponse
    {
        $isOwnInternal = $organization->billing_plan === 'agency_internal'
            && $organization->members()->where('user_id', $request->user()->id)->exists();

        if ($isOwnInternal) {
            return $this->error('Cannot delete your own primary platform internal workspace.', 'PRIMARY_ORG_PROTECTED', null, 422);
        }

        $name = $organization->name;
        $organization->delete();

        return $this->success([
            'message' => "Deleted workspace \"{$name}\" and all associated websites/data.",
        ]);
    }

    /**
     * List all websites across all organizations on the platform.
     */
    public function listWebsites(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $query = Website::with('organization')->latest();
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('domain', 'LIKE', "%{$search}%")
                    ->orWhere('name', 'LIKE', "%{$search}%");
            });
        }

        $websites = $query->limit(250)->get()->map(function (Website $w) {
            $gscConnected = WebsiteIntegration::where('website_id', $w->id)
                ->where('provider', WebsiteIntegration::PROVIDER_GSC)
                ->where('status', WebsiteIntegration::STATUS_CONNECTED)
                ->exists();
            $ga4Connected = WebsiteIntegration::where('website_id', $w->id)
                ->where('provider', WebsiteIntegration::PROVIDER_GA4)
                ->where('status', WebsiteIntegration::STATUS_CONNECTED)
                ->exists();

            return [
                'id' => $w->id,
                'name' => $w->name,
                'domain' => $w->domain,
                'platform' => $w->platform,
                'verification_status' => $w->verification_status,
                'health_score' => $w->health_score,
                'organization_id' => $w->organization_id,
                'organization_name' => $w->organization?->name ?? 'Unknown',
                'gsc_connected' => $gscConnected,
                'ga4_connected' => $ga4Connected,
                'created_at' => $w->created_at?->toIso8601String(),
            ];
        });

        return $this->success(['websites' => $websites]);
    }
}
