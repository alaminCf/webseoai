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

    public function test_easy_verification_methods_gsc_file_cms_and_seo_manager_instant_verify(): void
    {
        // 1. Listing websites includes non-empty verification token & snippets (fixes undefined token UI bug)
        $site = Website::create([
            'organization_id' => $this->organization->id,
            'name' => 'CareMate BD',
            'domain' => 'carematebd.com',
            'target_url' => 'https://carematebd.com',
            'platform' => Website::PLATFORM_WORDPRESS,
            'verification_status' => Website::VERIFICATION_UNVERIFIED,
        ]);

        $listRes = $this->actingAs($this->adminUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->getJson('/api/v1/websites');

        $listRes->assertOk();
        $listed = collect($listRes->json('data'))->firstWhere('id', $site->id);
        $this->assertNotEmpty($listed['verification']['token'] ?? null);
        $this->assertStringContainsString($listed['verification']['token'], $listed['verification']['meta_tag']);

        // 2. HTML/TXT file upload verification works
        $fileSnippet = $listed['verification']['file_token'];
        \Illuminate\Support\Facades\Http::fake([
            'https://carematebd.com/' . $fileSnippet['filename'] => \Illuminate\Support\Facades\Http::response($fileSnippet['content'], 200),
            'https://handymansuperservices.com/wp-json/wp/v2/users/me' => \Illuminate\Support\Facades\Http::response(['id' => 1, 'name' => 'Admin'], 200),
        ]);

        $fileVerifyRes = $this->actingAs($this->adminUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson("/api/v1/websites/{$site->id}/verify", ['method' => 'file_token']);

        $fileVerifyRes->assertOk()->assertJsonPath('data.verified', true);
        $this->assertTrue($site->fresh()->isVerified());

        // 3. 1-Click Google Search Console verification works when matching property is connected
        $gscSite = Website::create([
            'organization_id' => $this->organization->id,
            'name' => 'GSC Verified Site',
            'domain' => 'gscverified.com',
            'target_url' => 'https://gscverified.com',
            'platform' => Website::PLATFORM_CUSTOM,
            'verification_status' => Website::VERIFICATION_UNVERIFIED,
        ]);

        WebsiteIntegration::create([
            'organization_id' => $this->organization->id,
            'website_id' => $gscSite->id,
            'provider' => WebsiteIntegration::PROVIDER_GSC,
            'encrypted_credentials' => ['access_token' => 'live_tok', 'is_sandbox' => false],
            'status' => WebsiteIntegration::STATUS_CONNECTED,
            'metadata' => [
                'is_sandbox' => false,
                'selected_property' => 'sc-domain:gscverified.com',
                'properties' => [
                    ['siteUrl' => 'sc-domain:gscverified.com', 'permissionLevel' => 'siteOwner'],
                ],
            ],
        ]);

        $gscVerifyRes = $this->actingAs($this->adminUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson("/api/v1/websites/{$gscSite->id}/verify", ['method' => 'google_search_console']);

        $gscVerifyRes->assertOk()->assertJsonPath('data.verified', true);
        $this->assertEquals('google_search_console', $gscSite->fresh()->verification_method);

        // 4. One-Step WordPress REST verify + connect unlocks & saves encrypted credentials in one shot
        $wpSite = Website::create([
            'organization_id' => $this->organization->id,
            'name' => 'Handyman WP',
            'domain' => 'handymansuperservices.com',
            'target_url' => 'https://handymansuperservices.com',
            'platform' => Website::PLATFORM_WORDPRESS,
            'verification_status' => Website::VERIFICATION_UNVERIFIED,
        ]);

        $wpVerifyRes = $this->actingAs($this->adminUser)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson("/api/v1/websites/{$wpSite->id}/verify", [
                'method' => 'cms_credentials',
                'provider' => 'wordpress_rest',
                'credentials' => [
                    'username' => 'wp_admin',
                    'application_password' => '1111 2222 3333 4444',
                ],
            ]);

        $wpVerifyRes->assertOk()->assertJsonPath('data.verified', true);
        $this->assertTrue($wpSite->fresh()->isVerified());
        $this->assertEquals(Website::STATUS_CONNECTED, $wpSite->fresh()->connection_status);

        // 5. SEO Manager role can use Instant Agency Verify (manual_override)
        $seoManager = User::factory()->create();
        OrganizationMember::create([
            'organization_id' => $this->organization->id,
            'user_id' => $seoManager->id,
            'role' => OrganizationMember::ROLE_SEO_MANAGER,
        ]);

        $agencySite = Website::create([
            'organization_id' => $this->organization->id,
            'name' => 'Agency Client Site',
            'domain' => 'agencyclient.com',
            'target_url' => 'https://agencyclient.com',
            'platform' => Website::PLATFORM_CUSTOM,
            'verification_status' => Website::VERIFICATION_UNVERIFIED,
        ]);

        $overrideRes = $this->actingAs($seoManager)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson("/api/v1/websites/{$agencySite->id}/verify", ['method' => 'manual_override']);

        $overrideRes->assertOk()->assertJsonPath('data.verified', true);
        $this->assertTrue($agencySite->fresh()->isVerified());

        // 6. Custom Website simple Admin Username & Password login saves encrypted credentials and passes connection test
        \Illuminate\Support\Facades\Http::fake([
            'https://agencyclient.com/admin' => \Illuminate\Support\Facades\Http::response('<html>Admin Panel</html>', 200),
            'https://agencyclient.com/api/seo-webhook' => \Illuminate\Support\Facades\Http::response(['ok' => true], 200),
        ]);

        $adminLoginSave = $this->actingAs($seoManager)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson("/api/v1/websites/{$agencySite->id}/integrations", [
                'provider' => 'custom_webhook',
                'credentials' => [
                    'username' => 'admin@agencyclient.com',
                    'password' => 'SuperSecret123!',
                    'login_url' => 'https://agencyclient.com/admin',
                ],
            ]);

        $adminLoginSave->assertStatus(201)
            ->assertJsonPath('data.provider', 'custom_webhook')
            ->assertJsonPath('data.metadata.auth_type', 'admin_login')
            ->assertJsonPath('data.metadata.username', 'admin@agencyclient.com');

        $adminLoginTest = $this->actingAs($seoManager)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson("/api/v1/websites/{$agencySite->id}/integrations/custom_webhook/test");

        $adminLoginTest->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.status', 'connected');

        // Also supports optional API key / webhook mode
        $customSaveRes = $this->actingAs($seoManager)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson("/api/v1/websites/{$agencySite->id}/integrations", [
                'provider' => 'custom_webhook',
                'credentials' => [
                    'endpoint_url' => 'https://agencyclient.com/api/seo-webhook',
                    'api_key' => 'sk_live_custom_secret_123',
                    'auth_header' => 'Authorization',
                    'webhook_secret' => 'whsec_test_456',
                ],
            ]);

        $customSaveRes->assertStatus(201)
            ->assertJsonPath('data.provider', 'custom_webhook')
            ->assertJsonPath('data.metadata.endpoint_url', 'https://agencyclient.com/api/seo-webhook');

        $customTestRes = $this->actingAs($seoManager)
            ->withHeader('X-Organization-Id', (string) $this->organization->id)
            ->postJson("/api/v1/websites/{$agencySite->id}/integrations/custom_webhook/test");

        $customTestRes->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.status', 'connected');
    }
}
