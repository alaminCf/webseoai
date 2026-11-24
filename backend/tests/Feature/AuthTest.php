<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_user_can_register_and_becomes_organization_owner(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Alamin Architect',
            'email' => 'alamin@techboloy.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'organization_name' => 'Techboloy HQ',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'name' => 'Alamin Architect',
                        'email' => 'alamin@techboloy.com',
                    ],
                    'current_organization' => [
                        'name' => 'Techboloy HQ',
                        'role' => 'owner',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('users', ['email' => 'alamin@techboloy.com']);
        $this->assertDatabaseHas('organizations', ['name' => 'Techboloy HQ']);
        $this->assertDatabaseHas('organization_members', ['role' => 'owner']);
    }

    public function test_public_user_can_register_isolated_workspace_when_other_users_exist(): void
    {
        // Existing owner and organization
        $existingUser = User::create([
            'name' => 'Existing Owner',
            'email' => 'owner@techboloy.com',
            'password' => bcrypt('Password123!'),
        ]);
        $existingOrg = Organization::create([
            'name' => 'Techboloy Agency',
            'slug' => 'techboloy-agency',
            'billing_plan' => 'agency_internal',
        ]);
        OrganizationMember::create([
            'organization_id' => $existingOrg->id,
            'user_id' => $existingUser->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        // Another user registers publicly
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Sarah Khan',
            'email' => '  Sarah@GrowthStudio.com ',
            'password' => 'GrowthPass123',
            'password_confirmation' => 'GrowthPass123',
            'organization_name' => 'Growth Studio',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'name' => 'Sarah Khan',
                        'email' => 'sarah@growthstudio.com',
                    ],
                    'current_organization' => [
                        'name' => 'Growth Studio',
                        'role' => 'owner',
                    ],
                ],
            ]);

        $newOrgId = $response->json('data.current_organization.id');
        $newToken = $response->json('data.token');
        $this->assertNotEquals($existingOrg->id, $newOrgId);
        $this->assertCount(1, $response->json('data.organizations'));

        // Verify strict multi-tenant isolation: new user cannot access existing org
        $forbidden = $this->withHeader('Authorization', 'Bearer ' . $newToken)
            ->withHeader('X-Organization-Id', (string) $existingOrg->id)
            ->getJson('/api/v1/websites');
        $forbidden->assertStatus(403);

        // Verify case-insensitive duplicate email is rejected cleanly
        $dup = $this->postJson('/api/v1/auth/register', [
            'name' => 'Duplicate Sarah',
            'email' => 'SARAH@growthstudio.com',
            'password' => 'GrowthPass123',
            'password_confirmation' => 'GrowthPass123',
        ]);
        $dup->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_subsequent_public_registration_is_blocked_when_disabled(): void
    {
        config(['app.allow_public_registration' => false]);

        // First user exists
        User::create([
            'name' => 'Existing User',
            'email' => 'existing@techboloy.com',
            'password' => bcrypt('Password123!'),
        ]);

        // Second registration attempt should be rejected with 403 when explicitly disabled
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Intruder',
            'email' => 'stranger@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'REGISTRATION_DISABLED',
                ],
            ]);
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::create([
            'name' => 'Alamin',
            'email' => 'alamin@techboloy.com',
            'password' => bcrypt('Password123!'),
        ]);

        $org = Organization::create([
            'name' => 'Techboloy',
            'slug' => 'techboloy',
        ]);

        OrganizationMember::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'alamin@techboloy.com',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'user',
                    'token',
                    'organizations',
                    'current_organization',
                ],
            ]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::create([
            'name' => 'Alamin',
            'email' => 'alamin@techboloy.com',
            'password' => bcrypt('Password123!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'alamin@techboloy.com',
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                ],
            ]);
    }

    public function test_user_can_logout_and_revoke_token(): void
    {
        $user = User::create([
            'name' => 'Alamin',
            'email' => 'alamin@techboloy.com',
            'password' => bcrypt('Password123!'),
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_google_one_click_signin_creates_user_with_google_avatar_and_redirects_with_token(): void
    {
        config([
            'search_console.client_id' => 'test-google-client.apps.googleusercontent.com',
            'search_console.client_secret' => 'test-google-secret',
            'search_console.redirect_uri' => 'https://webseoai.vercel.app/api/v1/integrations/google-search-console/callback',
            'search_console.frontend_url' => 'https://webseoai.vercel.app',
        ]);

        // 1. Request Google Sign-In authorization URL
        $urlRes = $this->getJson('/api/v1/auth/google/url');
        $urlRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.oauth_mode', 'live');

        $authUrl = $urlRes->json('data.authorization_url');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $authUrl);
        parse_str(parse_url($authUrl, PHP_URL_QUERY), $queryParams);
        $this->assertArrayHasKey('state', $queryParams);
        $this->assertStringContainsString('openid email profile', $queryParams['scope']);

        // 2. Mock Google token exchange + userinfo endpoint (with Google profile picture)
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google_login_access_token_xyz',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
                'sub' => 'google_sub_987654321',
                'email' => 'Maya.Patel@Agency.io',
                'email_verified' => true,
                'name' => 'Maya Patel',
                'picture' => 'https://lh3.googleusercontent.com/a/maya-photo=s256-c',
            ], 200),
        ]);

        // 3. Simulate browser redirect hitting the shared already-authorized Google callback URI
        $cbRes = $this->get('/api/v1/integrations/google-search-console/callback?code=real_google_code&state=' . urlencode($queryParams['state']));
        $cbRes->assertStatus(302);
        $location = (string) $cbRes->headers->get('Location');
        $this->assertStringStartsWith('https://webseoai.vercel.app/#google_auth_token=', $location);
        $this->assertStringContainsString('google_auth_new=1', $location);

        // Verify user & Google profile picture & isolated workspace were created
        $user = User::where('email', 'maya.patel@agency.io')->first();
        $this->assertNotNull($user);
        $this->assertEquals('Maya Patel', $user->name);
        $this->assertEquals('google_sub_987654321', $user->google_id);
        $this->assertEquals('https://lh3.googleusercontent.com/a/maya-photo=s256-c', $user->avatar_url);
        $this->assertEquals('google', $user->auth_provider);
        $this->assertEquals(1, $user->memberships()->count());

        // 4. Verify Google user can edit their profile and upload a custom PNG avatar
        $tinyPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $updateRes = $this->actingAs($user)
            ->putJson('/api/v1/auth/profile', [
                'name' => 'Maya Patel (SEO Lead)',
                'job_title' => 'Head of SEO',
                'phone' => '+65 9876 5432',
                'bio' => 'Leading organic growth.',
                'avatar_url' => $tinyPng,
            ]);

        $updateRes->assertOk()
            ->assertJsonPath('data.user.name', 'Maya Patel (SEO Lead)')
            ->assertJsonPath('data.user.job_title', 'Head of SEO')
            ->assertJsonPath('data.user.avatar_url', $tinyPng)
            ->assertJsonPath('data.user.google_connected', true);

        // 5. Verify Google-only user can set an initial account password without current_password
        $pwdRes = $this->actingAs($user->fresh())
            ->putJson('/api/v1/auth/password', [
                'password' => 'MyNewPass123',
                'password_confirmation' => 'MyNewPass123',
            ]);

        $pwdRes->assertOk()
            ->assertJsonPath('data.user.auth_provider', 'google+email');

        // And can now log in with email/password too
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'maya.patel@agency.io',
            'password' => 'MyNewPass123',
        ]);
        $loginRes->assertOk()
            ->assertJsonPath('data.user.avatar_url', $tinyPng);
    }
}
