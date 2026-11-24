<?php

namespace App\Domain\Auth;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleAuthService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->clientId = trim((string) (env('GOOGLE_AUTH_CLIENT_ID') ?: config('search_console.client_id', '')));
        $this->clientSecret = trim((string) (env('GOOGLE_AUTH_CLIENT_SECRET') ?: config('search_console.client_secret', '')));

        // Reuse the already-whitelisted Google OAuth redirect URI so 1-click Google Sign-In works
        // immediately in production without requiring extra Google Cloud Console URI changes.
        $configuredRedirect = trim((string) (env('GOOGLE_AUTH_REDIRECT_URI') ?: config('search_console.redirect_uri', '')));
        $this->redirectUri = $configuredRedirect !== ''
            ? $configuredRedirect
            : url('/api/v1/integrations/google-search-console/callback');
    }

    public function isLiveConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    /**
     * Build the Google OAuth 2.0 Sign-In authorization URL with a stateless HMAC-signed state token.
     */
    public function getAuthorizationUrl(): string
    {
        $state = $this->createSignedState();

        if (!$this->isLiveConfigured()) {
            return url('/api/v1/auth/google/callback') . '?' . http_build_query([
                'code' => 'sandbox_google_login_code',
                'state' => $state,
            ]);
        }

        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'prompt' => 'select_account',
            'state' => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    /**
     * Exchange Google OAuth code for Google profile (name, email, picture) and sign in or create account.
     *
     * @return array{user: User, token: string, organizations: array, current_organization: array, is_new: bool}
     */
    public function handleCallback(string $code, string $state, Request $request): array
    {
        $this->verifySignedState($state);

        if (str_starts_with($code, 'sandbox_') || !$this->isLiveConfigured()) {
            $googleProfile = [
                'sub' => 'sandbox_google_user_001',
                'email' => 'google.demo@webseoai.app',
                'name' => 'Alex Rivera',
                'picture' => 'https://lh3.googleusercontent.com/a/default-user=s256-c',
                'email_verified' => true,
            ];
        } else {
            $googleProfile = $this->exchangeCodeForGoogleProfile($code);
        }

        $googleId = trim((string) ($googleProfile['sub'] ?? ''));
        $email = strtolower(trim((string) ($googleProfile['email'] ?? '')));
        $name = trim((string) ($googleProfile['name'] ?? ''));
        $picture = trim((string) ($googleProfile['picture'] ?? ''));

        if ($googleId === '' || $email === '') {
            throw new Exception('Google did not return a valid email address for this account.');
        }

        if ($name === '') {
            $name = ucfirst(strtok($email, '@') ?: 'User');
        }

        return DB::transaction(function () use ($googleId, $email, $name, $picture, $request) {
            $user = User::where('google_id', $googleId)
                ->orWhereRaw('LOWER(email) = ?', [$email])
                ->first();

            $isNew = false;

            if (!$user) {
                $existingUsersCount = User::count();
                $regSettings = \App\Models\PlatformSetting::getValue('registration');
                $isPublicRegistrationAllowed = (bool) ($regSettings['allow_public_registration'] ?? config('app.allow_public_registration', true));
                if ($existingUsersCount > 0 && !$isPublicRegistrationAllowed) {
                    throw new Exception('Public registration is currently disabled for this deployment.');
                }

                $isNew = true;
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'google_id' => $googleId,
                    'avatar_url' => $picture !== '' ? $picture : null,
                    'auth_provider' => 'google',
                    'is_super_admin' => $existingUsersCount === 0,
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'password' => Hash::make(Str::random(40)),
                ]);

                $orgName = $existingUsersCount === 0 ? 'Techboloy Agency' : "{$name}'s Workspace";
                $baseSlug = Str::slug($orgName) ?: 'workspace';
                $billingPlan = $existingUsersCount === 0
                    ? 'agency_internal'
                    : (string) ($regSettings['default_signup_plan'] ?? config('saas.default_signup_plan', 'agency_pro'));

                $organization = Organization::create([
                    'name' => $orgName,
                    'slug' => $baseSlug . '-' . strtolower(Str::random(6)),
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
                    'user.registered_google',
                    $user,
                    null,
                    ['email' => $user->email, 'google_id' => $googleId, 'role' => OrganizationMember::ROLE_OWNER],
                    $request
                );
            } else {
                if ($user->isSuspended()) {
                    throw new Exception('Your account has been suspended by an administrator. Please contact support.');
                }

                // Link Google ID & keep Google profile photo synced unless user uploaded a custom photo
                $updates = [];
                if (empty($user->google_id)) {
                    $updates['google_id'] = $googleId;
                }
                if (empty($user->email_verified_at)) {
                    $updates['email_verified_at'] = now();
                }
                $currentAvatar = (string) ($user->avatar_url ?? '');
                $isCustomUploadedAvatar = str_starts_with($currentAvatar, 'data:image/');
                if ($picture !== '' && ($currentAvatar === '' || (!$isCustomUploadedAvatar && str_contains($currentAvatar, 'googleusercontent.com')))) {
                    $updates['avatar_url'] = $picture;
                }
                if (!empty($updates)) {
                    $user->update($updates);
                }

                // Ensure user has at least one workspace
                if ($user->memberships()->count() === 0) {
                    $orgName = "{$user->name}'s Workspace";
                    $organization = Organization::create([
                        'name' => $orgName,
                        'slug' => (Str::slug($orgName) ?: 'workspace') . '-' . strtolower(Str::random(6)),
                        'billing_plan' => (string) config('saas.default_signup_plan', 'agency_pro'),
                    ]);

                    OrganizationMember::create([
                        'organization_id' => $organization->id,
                        'user_id' => $user->id,
                        'role' => OrganizationMember::ROLE_OWNER,
                    ]);
                }
            }

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
                ->values()
                ->toArray();

            $defaultOrg = $organizations[0] ?? null;

            if ($defaultOrg && !$isNew) {
                AuditLog::record(
                    $defaultOrg['id'],
                    $user->id,
                    'user.logged_in_google',
                    $user,
                    null,
                    ['email' => $user->email],
                    $request
                );
            }

            $token = $user->createToken('google-oauth-token')->plainTextToken;

            return [
                'user' => $user->fresh(),
                'token' => $token,
                'organizations' => $organizations,
                'current_organization' => $defaultOrg,
                'is_new' => $isNew,
            ];
        });
    }

    /**
     * Exchange OAuth authorization code for Google OpenID user profile.
     */
    private function exchangeCodeForGoogleProfile(string $code): array
    {
        $tokenResp = Http::asForm()
            ->timeout(15)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri,
            ]);

        if (!$tokenResp->successful()) {
            $err = $tokenResp->json('error_description') ?? $tokenResp->json('error') ?? 'Failed to exchange Google sign-in code.';
            Log::error('Google Sign-In token exchange failed', ['body' => $tokenResp->body()]);
            throw new Exception("Google Sign-In failed: {$err}");
        }

        $accessToken = (string) ($tokenResp->json('access_token') ?? '');
        if ($accessToken === '') {
            throw new Exception('Google did not return an access token.');
        }

        $profileResp = Http::withToken($accessToken)
            ->timeout(12)
            ->get('https://www.googleapis.com/oauth2/v3/userinfo');

        if (!$profileResp->successful()) {
            Log::error('Google Sign-In userinfo fetch failed', ['body' => $profileResp->body()]);
            throw new Exception('Could not retrieve your Google profile details. Please try again.');
        }

        return $profileResp->json() ?? [];
    }

    /**
     * Build a stateless HMAC-SHA256 signed state parameter compatible with the shared Google callback.
     */
    private function createSignedState(): string
    {
        $payload = json_encode([
            'intent' => 'google_login',
            'nonce' => Str::random(20),
            'ts' => now()->timestamp,
        ]);
        $payloadB64 = base64_encode((string) $payload);
        $sig = hash_hmac('sha256', $payloadB64, (string) config('app.key'));

        return base64_encode(json_encode([
            'intent' => 'google_login',
            'p' => $payloadB64,
            'sig' => $sig,
        ]));
    }

    /**
     * Verify HMAC signature and 15-minute expiration on Google Sign-In state.
     */
    private function verifySignedState(string $state): void
    {
        $envelope = json_decode(base64_decode($state, true) ?: '', true);
        if (!is_array($envelope) || ($envelope['intent'] ?? '') !== 'google_login' || empty($envelope['p']) || empty($envelope['sig'])) {
            throw new Exception('Invalid Google Sign-In state parameter.');
        }

        $expected = hash_hmac('sha256', (string) $envelope['p'], (string) config('app.key'));
        if (!hash_equals($expected, (string) $envelope['sig'])) {
            throw new Exception('Google Sign-In security verification failed. Please try again.');
        }

        $payload = json_decode(base64_decode((string) $envelope['p'], true) ?: '', true);
        $ts = (int) ($payload['ts'] ?? 0);
        if ($ts <= 0 || abs(now()->timestamp - $ts) > 900) {
            throw new Exception('Google Sign-In session expired. Please click Continue with Google again.');
        }
    }
}
