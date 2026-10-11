<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class WebsiteManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'name' => 'SEO Admin',
            'email' => 'admin@techboloy.com',
            'password' => bcrypt('Password123!'),
        ]);

        $this->organization = Organization::create([
            'name' => 'Techboloy Agency',
            'slug' => 'techboloy-agency',
        ]);

        OrganizationMember::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->adminUser->id,
            'role' => OrganizationMember::ROLE_ADMIN,
        ]);
    }

    public function test_can_create_website_with_platform_and_localization_settings(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson('/api/v1/websites', [
                'name' => 'Client WordPress Portal',
                'domain' => 'clientportal.com',
                'target_url' => 'https://clientportal.com',
                'platform' => 'wordpress',
                'timezone' => 'America/New_York',
                'preferred_language' => 'en',
                'target_country' => 'US',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Client WordPress Portal',
                    'domain' => 'clientportal.com',
                    'platform' => 'wordpress',
                    'connection_status' => 'disconnected',
                    'verification_status' => 'unverified',
                ],
            ]);

        $this->assertDatabaseHas('websites', [
            'domain' => 'clientportal.com',
            'platform' => 'wordpress',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_duplicate_domain_in_same_organization_is_rejected(): void
    {
        Website::create([
            'organization_id' => $this->organization->id,
            'name' => 'Site 1',
            'domain' => 'example.com',
            'target_url' => 'https://example.com',
            'platform' => 'custom',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson('/api/v1/websites', [
                'name' => 'Duplicate Site',
                'domain' => 'example.com',
                'target_url' => 'https://example.com',
                'platform' => 'custom',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'WEBSITE_EXISTS',
                ],
            ]);
    }

    public function test_can_archive_and_restore_website(): void
    {
        $website = Website::create([
            'organization_id' => $this->organization->id,
            'name' => 'Archive Me',
            'domain' => 'archiveme.com',
            'target_url' => 'https://archiveme.com',
            'platform' => 'custom',
        ]);

        // Archive
        $archiveRes = $this->actingAs($this->adminUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson("/api/v1/websites/{$website->id}/archive");

        $archiveRes->assertStatus(200);
        $this->assertTrue($website->fresh()->is_archived);

        // Restore
        $restoreRes = $this->actingAs($this->adminUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson("/api/v1/websites/{$website->id}/restore");

        $restoreRes->assertStatus(200);
        $this->assertFalse($website->fresh()->is_archived);
    }

    public function test_ownership_verification_unlocks_write_integrations(): void
    {
        $website = Website::create([
            'organization_id' => $this->organization->id,
            'name' => 'WP Site',
            'domain' => 'wp-site.com',
            'target_url' => 'https://wp-site.com',
            'platform' => 'wordpress',
            'verification_status' => 'unverified',
        ]);

        // Attempting integration connection before verification must be rejected
        $prematureRes = $this->actingAs($this->adminUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson("/api/v1/websites/{$website->id}/integrations", [
                'provider' => 'wordpress_rest',
                'credentials' => [
                    'username' => 'seo_bot',
                    'application_password' => 'abcd 1234 efgh 5678',
                ],
            ]);

        $prematureRes->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'WEBSITE_UNVERIFIED',
                ],
            ]);

        // Verify ownership via admin override
        $verifyRes = $this->actingAs($this->adminUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson("/api/v1/websites/{$website->id}/verify", [
                'method' => 'manual_override',
            ]);

        $verifyRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'verified' => true,
                ],
            ]);

        $this->assertTrue($website->fresh()->isVerified());

        // Now integration connection succeeds
        $integrationRes = $this->actingAs($this->adminUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson("/api/v1/websites/{$website->id}/integrations", [
                'provider' => 'wordpress_rest',
                'credentials' => [
                    'username' => 'seo_bot',
                    'application_password' => 'abcd 1234 efgh 5678',
                ],
            ]);

        $integrationRes->assertStatus(201);

        // Security assertion: API response must NOT expose the encrypted or plaintext password!
        $this->assertArrayNotHasKey('encrypted_credentials', $integrationRes->json('data'));
        $this->assertArrayNotHasKey('credentials', $integrationRes->json('data'));

        // Database assertion: stored in database as encrypted payload
        $integration = WebsiteIntegration::where('website_id', $website->id)->firstOrFail();
        $rawDbValue = $integration->getRawOriginal('encrypted_credentials');
        $this->assertNotEquals('abcd 1234 efgh 5678', $rawDbValue);

        // Decryption helper successfully recovers the credentials server-side only
        $decrypted = $integration->getDecryptedCredentials();
        $this->assertEquals('abcd 1234 efgh 5678', $decrypted['application_password']);
    }
}
