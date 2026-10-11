<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    use ApiResponseTrait;

    /**
     * Register a new user.
     * Public self-registration is disabled once an initial owner exists,
     * unless explicitly permitted via ALLOW_PUBLIC_REGISTRATION=true.
     */
    public function register(Request $request): JsonResponse
    {
        $existingUsersCount = User::count();
        $isPublicRegistrationAllowed = config('app.allow_public_registration', false);

        // Disallow arbitrary self-registration if users exist and flag is false
        if ($existingUsersCount > 0 && !$isPublicRegistrationAllowed) {
            return $this->error(
                'Public registration is currently disabled for this internal deployment. Please contact an organization administrator for an invitation.',
                'REGISTRATION_DISABLED',
                null,
                403
            );
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'confirmed'],
            'organization_name' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($validated, $existingUsersCount, $request) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            // If initial deployment, set up default organization
            $orgName = $validated['organization_name'] ?? ($existingUsersCount === 0 ? 'Techboloy Agency' : $validated['name'] . "'s Org");
            $orgSlug = Str::slug($orgName) . '-' . Str::random(5);

            $organization = Organization::create([
                'name' => $orgName,
                'slug' => $orgSlug,
                'billing_plan' => 'agency_internal',
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
                ['email' => $user->email, 'role' => 'owner'],
                $request
            );

            $token = $user->createToken('auth-token')->plainTextToken;

            return $this->success([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'token' => $token,
                'current_organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'role' => OrganizationMember::ROLE_OWNER,
                ],
            ], null, 201);
        });
    }

    /**
     * User login with rate limiting and secure session token generation.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return $this->error(
                'Invalid email address or password.',
                'INVALID_CREDENTIALS',
                null,
                401
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
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => $token,
            'organizations' => $organizations,
            'current_organization' => $defaultOrg,
        ]);
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

        $organizations = $memberships->map(fn($m) => [
            'id' => $m->organization->id,
            'name' => $m->organization->name,
            'slug' => $m->organization->slug,
            'role' => $m->role,
        ]);

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'organizations' => $organizations,
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
