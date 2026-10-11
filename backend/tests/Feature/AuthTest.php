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

    public function test_subsequent_public_registration_is_blocked_when_disabled(): void
    {
        // First user registers
        User::create([
            'name' => 'Existing User',
            'email' => 'existing@techboloy.com',
            'password' => bcrypt('Password123!'),
        ]);

        // Second registration attempt should be rejected with 403
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
