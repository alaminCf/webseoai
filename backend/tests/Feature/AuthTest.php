<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
