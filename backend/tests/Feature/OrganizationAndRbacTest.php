<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationAndRbacTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerUser;
    private User $viewerUser;
    private User $externalUser;
    private Organization $orgA;
    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerUser = User::create([
            'name' => 'Org Owner',
            'email' => 'owner@techboloy.com',
            'password' => bcrypt('Password123!'),
        ]);

        $this->viewerUser = User::create([
            'name' => 'Org Viewer',
            'email' => 'viewer@techboloy.com',
            'password' => bcrypt('Password123!'),
        ]);

        $this->externalUser = User::create([
            'name' => 'External User',
            'email' => 'external@client.com',
            'password' => bcrypt('Password123!'),
        ]);

        $this->orgA = Organization::create([
            'name' => 'Agency Org A',
            'slug' => 'agency-org-a',
        ]);

        $this->orgB = Organization::create([
            'name' => 'Client Org B',
            'slug' => 'client-org-b',
        ]);

        OrganizationMember::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->ownerUser->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        OrganizationMember::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->viewerUser->id,
            'role' => OrganizationMember::ROLE_VIEWER,
        ]);

        OrganizationMember::create([
            'organization_id' => $this->orgB->id,
            'user_id' => $this->externalUser->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);
    }

    public function test_tenant_isolation_prevents_access_to_foreign_organization(): void
    {
        // External user attempts to access Org A via header
        $response = $this->actingAs($this->externalUser)
            ->withHeader('X-Organization-Id', (string) $this->orgA->id)
            ->getJson('/api/v1/websites');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'ORGANIZATION_ACCESS_DENIED',
                ],
            ]);
    }

    public function test_viewer_role_cannot_add_new_websites(): void
    {
        $response = $this->actingAs($this->viewerUser)
            ->withHeader('X-Organization-Id', (string) $this->orgA->id)
            ->postJson('/api/v1/websites', [
                'name' => 'Disallowed Website',
                'domain' => 'disallowed.com',
                'target_url' => 'https://disallowed.com',
                'platform' => 'wordpress',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                ],
            ]);
    }

    public function test_viewer_role_cannot_invite_organization_members(): void
    {
        $response = $this->actingAs($this->viewerUser)
            ->withHeader('X-Organization-Id', (string) $this->orgA->id)
            ->postJson('/api/v1/organizations/current/members', [
                'email' => 'newuser@techboloy.com',
                'role' => 'editor',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                ],
            ]);
    }

    public function test_owner_can_invite_member_and_update_role(): void
    {
        $inviteRes = $this->actingAs($this->ownerUser)
            ->withHeader('X-Organization-Id', (string) $this->orgA->id)
            ->postJson('/api/v1/organizations/current/members', [
                'email' => 'specialist@techboloy.com',
                'role' => OrganizationMember::ROLE_SEO_MANAGER,
                'name' => 'SEO Specialist',
            ]);

        $inviteRes->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'email' => 'specialist@techboloy.com',
                    'role' => 'seo_manager',
                ],
            ]);

        $memberId = $inviteRes->json('data.id');

        // Update role to editor
        $updateRes = $this->actingAs($this->ownerUser)
            ->withHeader('X-Organization-Id', (string) $this->orgA->id)
            ->patchJson("/api/v1/organizations/current/members/{$memberId}", [
                'role' => OrganizationMember::ROLE_EDITOR,
            ]);

        $updateRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'role' => 'editor',
                ],
            ]);
    }
}
