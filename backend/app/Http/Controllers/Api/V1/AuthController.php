<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\GoogleAuthService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AuthController extends Controller
{
    use ApiResponseTrait;

    /**
     * Register a new user and provision their own isolated multi-tenant workspace.
     * Can be locked for internal-only deployments via ALLOW_PUBLIC_REGISTRATION=false.
     */
    public function register(Request $request): JsonResponse
    {
        $existingUsersCount = User::count();
        $regSettings = \App\Models\PlatformSetting::getValue('registration');
        $isPublicRegistrationAllowed = (bool) ($regSettings['allow_public_registration'] ?? config('app.allow_public_registration', true));

        // Disallow self-registration only if users already exist AND operator explicitly disabled it
        if ($existingUsersCount > 0 && !$isPublicRegistrationAllowed) {
            return $this->error(
                'Public registration is currently disabled for this deployment. Please contact an organization administrator for an invitation.',
                'REGISTRATION_DISABLED',
                null,
                403
            );
        }

        // Normalize email & optional workspace name before validation
        $request->merge([
            'name' => trim((string) $request->input('name', '')),
            'email' => strtolower(trim((string) $request->input('email', ''))),
            'organization_name' => $request->filled('organization_name')
                ? trim((string) $request->input('organization_name'))
                : null,
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'confirmed'],
            'organization_name' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($validated, $existingUsersCount, $regSettings, $request) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'is_super_admin' => $existingUsersCount === 0,
                'status' => 'active',
            ]);

            // Create a dedicated isolated organization/workspace for every registered user
            $orgName = !empty($validated['organization_name'])
                ? $validated['organization_name']
                : ($existingUsersCount === 0 ? 'Techboloy Agency' : $validated['name'] . "'s Workspace");

            $baseSlug = Str::slug($orgName) ?: 'workspace';
            $orgSlug = $baseSlug . '-' . strtolower(Str::random(6));
            $billingPlan = $existingUsersCount === 0
                ? 'agency_internal'
                : (string) ($regSettings['default_signup_plan'] ?? config('saas.default_signup_plan', 'agency_pro'));

            $organization = Organization::create([
                'name' => $orgName,
                'slug' => $orgSlug,
                'billing_plan' => $billingPlan,
            ]);

            OrganizationMember::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => OrganizationMember::ROLE_OWNER,
            ]);

            AuditLog::record(
                $organization->id,
                $user->id,
                'user.registered',
                $user,
                null,
                ['email' => $user->email, 'role' => OrganizationMember::ROLE_OWNER, 'plan' => $billingPlan],
                $request
            );

            $token = $user->createToken('auth-token')->plainTextToken;

            $orgPayload = [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'role' => OrganizationMember::ROLE_OWNER,
            ];

            return $this->success([
                'user' => $user->toProfileArray(),
                'token' => $token,
                'organizations' => [$orgPayload],
                'current_organization' => $orgPayload,
            ], null, 201);
        });
    }

    /**
     * User login with rate limiting and secure session token generation.
     */
    public function login(Request $request): JsonResponse
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::whereRaw('LOWER(email) = ?', [$validated['email']])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return $this->error(
                'Invalid email address or password.',
                'INVALID_CREDENTIALS',
                null,
                401
            );
        }

        if ($user->isSuspended()) {
            return $this->error(
                'Your account has been suspended by an administrator. Please contact support.',
                'ACCOUNT_SUSPENDED',
                null,
                403
            );
        }

        // Generate Sanctum Personal Access Token
        $token = $user->createToken('auth-token')->plainTextToken;

        $memberships = OrganizationMember::with('organization')
            ->where('user_id', $user->id)
            ->get();

        $organizations = $memberships->map(fn($m) => [
            'id' => $m->organization->id,
            'name' => $m->organization->name,
            'slug' => $m->organization->slug,
            'role' => $m->role,
        ]);

        $defaultOrg = $organizations->first();

        if ($defaultOrg) {
            AuditLog::record(
                $defaultOrg['id'],
                $user->id,
                'user.logged_in',
                $user,
                null,
                null,
                $request
            );
        }

        return $this->success([
            'user' => $user->toProfileArray(),
            'token' => $token,
            'organizations' => $organizations,
            'current_organization' => $defaultOrg,
        ]);
    }

    /**
     * Generate 1-click Google Sign-In OAuth URL.
     */
    public function googleUrl(GoogleAuthService $googleAuth): JsonResponse
    {
        return $this->success([
            'authorization_url' => $googleAuth->getAuthorizationUrl(),
            'oauth_mode' => $googleAuth->isLiveConfigured() ? 'live' : 'sandbox',
            'redirect_uri' => $googleAuth->getRedirectUri(),
        ]);
    }

    /**
     * Handle Google Sign-In OAuth callback (browser redirect or JSON sandbox).
     */
    public function googleCallback(Request $request, GoogleAuthService $googleAuth): JsonResponse|RedirectResponse
    {
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        $oauthError = (string) $request->query('error', '');
        $isApiCall = $request->expectsJson() || str_starts_with($code, 'sandbox_');
        $frontendBase = rtrim((string) (config('search_console.frontend_url') ?: url('/')), '/');

        if ($oauthError !== '') {
            $reason = $request->query('error_description') ?: 'Google Sign-In was cancelled.';
            if ($isApiCall) {
                return $this->error($reason, 'GOOGLE_AUTH_DENIED', null, 400);
            }
            return redirect()->away($frontendBase . '/?' . http_build_query(['auth_error' => $reason]));
        }

        if ($code === '' || $state === '') {
            $reason = 'Missing required Google Sign-In parameters.';
            if ($isApiCall) {
                return $this->error($reason, 'VALIDATION_FAILED', null, 422);
            }
            return redirect()->away($frontendBase . '/?' . http_build_query(['auth_error' => $reason]));
        }

        try {
            $result = $googleAuth->handleCallback($code, $state, $request);

            if ($isApiCall) {
                return $this->success([
                    'user' => $result['user']->toProfileArray(),
                    'token' => $result['token'],
                    'organizations' => $result['organizations'],
                    'current_organization' => $result['current_organization'],
                    'is_new' => $result['is_new'],
                ]);
            }

            // Deliver Sanctum token cleanly via URL fragment (#) so it is never leaked in HTTP logs or Referer headers
            $fragment = http_build_query([
                'google_auth_token' => $result['token'],
                'google_auth_org' => $result['current_organization']['id'] ?? '',
                'google_auth_new' => $result['is_new'] ? '1' : '0',
            ]);

            return redirect()->away($frontendBase . '/#' . $fragment);
        } catch (Throwable $e) {
            if ($isApiCall) {
                return $this->error($e->getMessage(), 'GOOGLE_AUTH_FAILED', null, 400);
            }
            return redirect()->away($frontendBase . '/?' . http_build_query([
                'auth_error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Logout and invalidate session/token.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $user->currentAccessToken()->delete();
        }

        return $this->success([
            'message' => 'Successfully logged out.',
        ]);
    }

    /**
     * Get authenticated user profile and organizations.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $memberships = OrganizationMember::with('organization')
            ->where('user_id', $user->id)
            ->get();

        $organizations = $memberships
            ->filter(fn ($m) => $m->organization !== null)
            ->map(fn ($m) => [
                'id' => $m->organization->id,
                'name' => $m->organization->name,
                'slug' => $m->organization->slug,
                'role' => $m->role,
            ])
            ->values();

        return $this->success([
            'user' => $user->toProfileArray(),
            'organizations' => $organizations,
        ]);
    }

    /**
     * Update authenticated user's profile (name, job title, phone, bio, avatar).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->merge([
            'name' => trim((string) $request->input('name', $user->name)),
            'job_title' => $request->has('job_title') ? trim((string) $request->input('job_title', '')) : $user->job_title,
            'phone' => $request->has('phone') ? trim((string) $request->input('phone', '')) : $user->phone,
            'bio' => $request->has('bio') ? trim((string) $request->input('bio', '')) : $user->bio,
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'bio' => ['nullable', 'string', 'max:500'],
            'avatar_url' => [
                'nullable',
                'string',
                'max:650000',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $str = (string) $value;
                    $isHttpUrl = (bool) preg_match('/^https?:\/\/.+/i', $str) && strlen($str) <= 2048;
                    $isRasterDataUri = (bool) preg_match('/^data:image\/(png|jpe?g|webp|gif);base64,[A-Za-z0-9+\/=]+$/i', $str);
                    if (!$isHttpUrl && !$isRasterDataUri) {
                        $fail('Profile picture must be a valid image upload (PNG, JPG, WebP) or HTTPS image URL.');
                    }
                },
            ],
        ]);

        $updates = [
            'name' => $validated['name'],
            'job_title' => $validated['job_title'] !== '' ? $validated['job_title'] : null,
            'phone' => $validated['phone'] !== '' ? $validated['phone'] : null,
            'bio' => $validated['bio'] !== '' ? $validated['bio'] : null,
        ];

        if ($request->has('avatar_url')) {
            $avatarVal = trim((string) ($validated['avatar_url'] ?? ''));
            $updates['avatar_url'] = $avatarVal !== '' ? $avatarVal : null;
        }

        $user->update($updates);

        $orgId = $request->header('X-Organization-Id') ?: $user->memberships()->value('organization_id');
        if ($orgId) {
            AuditLog::record(
                (int) $orgId,
                $user->id,
                'user.profile_updated',
                $user,
                null,
                ['name' => $user->name, 'job_title' => $user->job_title],
                $request
            );
        }

        return $this->success([
            'message' => 'Profile updated successfully.',
            'user' => $user->fresh()->toProfileArray(),
        ]);
    }

    /**
     * Change password (or set an initial password for Google Sign-In users).
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $isGoogleOnly = ($user->auth_provider === 'google');

        $rules = [
            'password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'confirmed'],
        ];
        if (!$isGoogleOnly) {
            $rules['current_password'] = ['required', 'string'];
        }

        $validated = $request->validate($rules);

        if (!$isGoogleOnly && !Hash::check($validated['current_password'], $user->password)) {
            return $this->error(
                'Your current password is incorrect.',
                'INVALID_CURRENT_PASSWORD',
                null,
                422
            );
        }

        $updates = [
            'password' => Hash::make($validated['password']),
        ];
        if ($isGoogleOnly) {
            $updates['auth_provider'] = 'google+email';
        }

        $user->update($updates);

        return $this->success([
            'message' => $isGoogleOnly
                ? 'Password set successfully. You can now sign in with either Google or email/password.'
                : 'Password updated successfully.',
            'user' => $user->fresh()->toProfileArray(),
        ]);
    }

    /**
     * Initiate password reset workflow.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $token = Str::random(60);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $validated['email']],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        return $this->success([
            'message' => 'Password reset instructions have been created.',
            // Development helper: exposes token for local environments
            'reset_token' => app()->isLocal() ? $token : null,
        ]);
    }

    /**
     * Complete password reset.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'confirmed'],
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $validated['email'])->first();

        if (!$record || !Hash::check($validated['token'], $record->token)) {
            return $this->error('Invalid or expired password reset token.', 'INVALID_RESET_TOKEN', null, 422);
        }

        // Check expiration (60 minutes)
        if (now()->subMinutes(60)->gt($record->created_at)) {
            return $this->error('Password reset token has expired.', 'EXPIRED_RESET_TOKEN', null, 422);
        }

        $user = User::where('email', $validated['email'])->firstOrFail();
        $user->update(['password' => Hash::make($validated['password'])]);

        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();
        $user->tokens()->delete(); // Invalidate all existing active sessions

        return $this->success([
            'message' => 'Password has been successfully updated. Please log in with your new password.',
        ]);
    }
}
