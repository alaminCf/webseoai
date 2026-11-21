<?php

namespace Tests\Feature;

use App\Domain\Integrations\Adapters\GenericCustomAdapter;
use App\Domain\Integrations\Adapters\ShopifyAdapter;
use App\Domain\Integrations\Adapters\WordPressAdapter;
use App\Domain\Integrations\CmsAdapterManager;
use App\Domain\Integrations\CmsExecutionPipeline;
use App\Domain\Integrations\Exceptions\ChangeVerificationFailedException;
use App\Domain\Integrations\Exceptions\ConflictDetectedException;
use App\Domain\Integrations\Exceptions\UnsupportedCapabilityException;
use App\Models\CmsChangeRequest;
use App\Models\CmsSnapshot;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CmsIntegrationAndExecutionTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerUser;
    private User $editorUser;
    private Organization $org;
    private Website $wpWebsite;
    private Website $shopifyWebsite;
    private Website $customWebsite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerUser = User::factory()->create(['email' => 'director@techboloy.com']);
        $this->editorUser = User::factory()->create(['email' => 'writer@techboloy.com']);

        $this->org = Organization::create([
            'name' => 'Agency Production Org',
            'slug' => 'agency-prod-org',
            'owner_id' => $this->ownerUser->id,
        ]);

        OrganizationMember::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->ownerUser->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        OrganizationMember::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->editorUser->id,
            'role' => OrganizationMember::ROLE_EDITOR,
        ]);

        $this->wpWebsite = Website::create([
            'organization_id' => $this->org->id,
            'name' => 'Techboloy Blog WP',
            'domain' => 'blog.techboloy.com',
            'target_url' => 'https://blog.techboloy.com',
            'platform' => Website::PLATFORM_WORDPRESS,
            'timezone' => 'UTC',
            'preferred_language' => 'en',
            'verification_status' => Website::VERIFICATION_VERIFIED,
        ]);

        WebsiteIntegration::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->wpWebsite->id,
            'provider' => WebsiteIntegration::PROVIDER_WORDPRESS,
            'status' => WebsiteIntegration::STATUS_CONNECTED,
            'encrypted_credentials' => [
                'site_url' => 'https://blog.techboloy.com',
                'username' => 'admin_bot',
                'app_password' => 'test-abcd-efgh-1234',
                'seo_plugin' => 'yoast',
            ],
        ]);

        $this->shopifyWebsite = Website::create([
            'organization_id' => $this->org->id,
            'name' => 'Techboloy Storefront',
            'domain' => 'store.techboloy.com',
            'target_url' => 'https://store.techboloy.com',
            'platform' => Website::PLATFORM_SHOPIFY,
            'timezone' => 'UTC',
            'preferred_language' => 'en',
            'verification_status' => Website::VERIFICATION_VERIFIED,
        ]);

        WebsiteIntegration::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->shopifyWebsite->id,
            'provider' => WebsiteIntegration::PROVIDER_SHOPIFY,
            'status' => WebsiteIntegration::STATUS_CONNECTED,
            'encrypted_credentials' => [
                'shop_domain' => 'techboloy-store.myshopify.com',
                'access_token' => 'shpat_test_secret_token_12345',
            ],
        ]);

        $this->customWebsite = Website::create([
            'organization_id' => $this->org->id,
            'name' => 'Custom Portal',
            'domain' => 'custom.techboloy.com',
            'target_url' => 'https://custom.techboloy.com',
            'platform' => Website::PLATFORM_CUSTOM,
            'timezone' => 'UTC',
            'preferred_language' => 'en',
            'verification_status' => Website::VERIFICATION_VERIFIED,
        ]);
    }

    public function test_generic_custom_adapter_strictly_enforces_read_only_contract(): void
    {
        $adapter = new GenericCustomAdapter($this->customWebsite);
        $caps = $adapter->getCapabilities();

        $this->assertContains('read_pages', $caps);
        $this->assertContains('snapshots', $caps);
        $this->assertFalse($adapter->supports('update_content'));
        $this->assertFalse($adapter->supports('update_metadata'));

        $this->expectException(UnsupportedCapabilityException::class);
        $adapter->updateContent('123', ['content' => 'New body']);
    }

    public function test_wordpress_adapter_capabilities_and_yoast_metadata_updates(): void
    {
        Http::fake([
            'https://blog.techboloy.com/wp-json/wp/v2/pages/42' => Http::sequence()
                // Initial fetch
                ->push([
                    'id' => 42,
                    'title' => ['rendered' => 'Original Title'],
                    'content' => ['rendered' => '<p>Original body</p>'],
                    'slug' => 'sample-page',
                    'yoast_head_json' => [
                        'title' => 'Old SEO Title',
                        'description' => 'Old SEO Description',
                    ],
                ], 200)
                // POST update
                ->push([
                    'id' => 42,
                    'title' => ['rendered' => 'Original Title'],
                    'content' => ['rendered' => '<p>Original body</p>'],
                    'slug' => 'sample-page',
                    'yoast_head_json' => [
                        'title' => 'Optimized SEO Title | Techboloy',
                        'description' => 'Targeted meta description for 2026 search ranking.',
                    ],
                ], 200)
                // Read-back verification
                ->push([
                    'id' => 42,
                    'title' => ['rendered' => 'Original Title'],
                    'content' => ['rendered' => '<p>Original body</p>'],
                    'slug' => 'sample-page',
                    'yoast_head_json' => [
                        'title' => 'Optimized SEO Title | Techboloy',
                        'description' => 'Targeted meta description for 2026 search ranking.',
                    ],
                ], 200),
        ]);

        $adapter = new WordPressAdapter($this->wpWebsite, [
            'username' => 'admin_bot',
            'application_password' => 'test-abcd-efgh-1234',
            'seo_plugin' => 'yoast',
        ]);
        $this->assertTrue($adapter->supports('update_metadata'));
        $this->assertTrue($adapter->supports('read_pages'));

        $page = $adapter->fetchPage('42');
        $this->assertEquals('Old SEO Title', $page['meta_title']);

        $updateResult = $adapter->updateSupportedMetadata('42', [
            'meta_title' => 'Optimized SEO Title | Techboloy',
            'meta_description' => 'Targeted meta description for 2026 search ranking.',
        ]);
        $this->assertEquals('Optimized SEO Title | Techboloy', $updateResult['meta_title']);

        $verification = $adapter->verifyChange('42', [
            'meta_title' => 'Optimized SEO Title | Techboloy',
            'meta_description' => 'Targeted meta description for 2026 search ranking.',
        ]);
        $this->assertTrue($verification['verified']);
        $this->assertEquals('Optimized SEO Title | Techboloy', $verification['live_state']['meta_title']);
    }

    public function test_shopify_adapter_rate_limit_retry_and_seo_metafields(): void
    {
        Http::fake([
            'https://techboloy-store.myshopify.com/admin/api/2025-01/pages/999.json' => Http::sequence()
                // Fetch page
                ->push([
                    'page' => [
                        'id' => 999,
                        'title' => 'Smart Hub Alpha',
                        'body_html' => '<p>High tech gadget</p>',
                        'handle' => 'smart-hub-alpha',
                        'metafield_title' => 'Smart Hub Alpha - Buy Now',
                        'metafield_description' => 'Premium smart hub gadget.',
                    ]
                ], 200)
                // PUT update fails with 429 once, then succeeds
                ->push(['errors' => 'Exceeded 2 calls per second'], 429, ['Retry-After' => '0'])
                ->push([
                    'page' => [
                        'id' => 999,
                        'title' => 'Smart Hub Alpha',
                        'handle' => 'smart-hub-alpha',
                        'metafield_title' => 'Smart Hub Alpha Pro - 2026 Edition',
                    ]
                ], 200)
                // Read-back verification
                ->push([
                    'page' => [
                        'id' => 999,
                        'title' => 'Smart Hub Alpha',
                        'handle' => 'smart-hub-alpha',
                        'metafield_title' => 'Smart Hub Alpha Pro - 2026 Edition',
                        'metafield_description' => 'Premium smart hub gadget.',
                    ]
                ], 200),
        ]);

        $adapter = new ShopifyAdapter($this->shopifyWebsite, [
            'shop_domain' => 'techboloy-store.myshopify.com',
            'access_token' => 'shpat_test_secret_token_12345',
            'api_version' => '2025-01',
        ]);
        $this->assertTrue($adapter->supports('update_metadata'));

        $page = $adapter->fetchPage('999');
        $this->assertEquals('Smart Hub Alpha - Buy Now', $page['meta_title']);

        $res = $adapter->updateSupportedMetadata('999', [
            'meta_title' => 'Smart Hub Alpha Pro - 2026 Edition',
        ]);
        $this->assertEquals('Smart Hub Alpha Pro - 2026 Edition', $res['meta_title']);

        $verification = $adapter->verifyChange('999', [
            'meta_title' => 'Smart Hub Alpha Pro - 2026 Edition',
        ]);
        $this->assertTrue($verification['verified']);
    }

    public function test_full_11_step_execution_pipeline_staging_approval_and_verification(): void
    {
        Http::fake([
            'https://blog.techboloy.com/wp-json/wp/v2/pages/77' => Http::sequence()
                // 1. Initial snapshot fetch during staging
                ->push([
                    'id' => 77,
                    'title' => ['rendered' => 'Contact Page'],
                    'content' => ['rendered' => '<p>Old contact info</p>'],
                    'yoast_head_json' => [
                        'title' => 'Contact Us',
                        'description' => 'Old description',
                    ],
                ], 200)
                // 2. Pre-execution conflict detection re-read
                ->push([
                    'id' => 77,
                    'title' => ['rendered' => 'Contact Page'],
                    'content' => ['rendered' => '<p>Old contact info</p>'],
                    'yoast_head_json' => [
                        'title' => 'Contact Us',
                        'description' => 'Old description',
                    ],
                ], 200)
                // 3. Execution update POST
                ->push(['id' => 77], 200)
                // 4. Post-execution read-back verification
                ->push([
                    'id' => 77,
                    'title' => ['rendered' => 'Contact Page'],
                    'content' => ['rendered' => '<p>Old contact info</p>'],
                    'yoast_head_json' => [
                        'title' => 'Contact Techboloy Agency - Get in Touch',
                        'description' => 'Contact our SEO engineering team directly.',
                    ],
                ], 200),
        ]);

        // Stage change request via API
        $token = $this->ownerUser->createToken('test')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->postJson("/api/v1/websites/{$this->wpWebsite->id}/cms/change-requests/stage", [
                'action_type' => 'update_metadata',
                'target_type' => 'page',
                'target_identifier' => '77',
                'proposed_payload' => [
                    'meta_title' => 'Contact Techboloy Agency - Get in Touch',
                    'meta_description' => 'Contact our SEO engineering team directly.',
                ],
            ]);

        $response->assertStatus(200);
        $changeId = $response->json('data.id');
        $this->assertEquals(CmsChangeRequest::STATUS_PENDING_APPROVAL, $response->json('data.status'));
        $this->assertNotNull($response->json('data.pre_change_snapshot_id'));
        $this->assertNotEmpty($response->json('data.diff_summary'));

        // Attempt execution prior to approval -> 422
        $execPrior = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->postJson("/api/v1/websites/{$this->wpWebsite->id}/cms/change-requests/{$changeId}/execute");
        $execPrior->assertStatus(422);

        // Approve change request
        $approveRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->postJson("/api/v1/websites/{$this->wpWebsite->id}/cms/change-requests/{$changeId}/approve");
        $approveRes->assertStatus(200);
        $this->assertEquals(CmsChangeRequest::STATUS_APPROVED, $approveRes->json('data.status'));

        // Execute approved change request
        $execRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->postJson("/api/v1/websites/{$this->wpWebsite->id}/cms/change-requests/{$changeId}/execute");
        $execRes->assertStatus(200);
        $this->assertEquals(CmsChangeRequest::STATUS_VERIFIED, $execRes->json('data.status'));
        $this->assertNotNull($execRes->json('data.post_change_snapshot_id'));
        $this->assertNotNull($execRes->json('data.executed_at'));
    }

    public function test_conflict_detection_prevents_overwriting_human_edits(): void
    {
        Http::fake([
            'https://blog.techboloy.com/wp-json/wp/v2/pages/88' => Http::sequence()
                // Initial fetch when staging
                ->push([
                    'id' => 88,
                    'title' => ['rendered' => 'About Us'],
                    'content' => ['rendered' => '<p>Version A</p>'],
                    'yoast_head_json' => ['title' => 'About'],
                ], 200)
                // Pre-execution fetch detects someone changed it to Version B!
                ->push([
                    'id' => 88,
                    'title' => ['rendered' => 'About Us - Edited by Webmaster'],
                    'content' => ['rendered' => '<p>Version B (human changed this)</p>'],
                    'yoast_head_json' => ['title' => 'About Custom'],
                ], 200),
        ]);

        $pipeline = app(CmsExecutionPipeline::class);
        $cr = $pipeline->stageChangeRequest(
            website: $this->wpWebsite,
            user: $this->ownerUser,
            actionType: 'update_metadata',
            targetType: 'page',
            targetIdentifier: '88',
            proposedPayload: ['meta_title' => 'New Automated SEO Title']
        );

        $cr->update([
            'status' => CmsChangeRequest::STATUS_APPROVED,
            'approved_by' => $this->ownerUser->id,
            'approved_at' => now(),
        ]);

        $this->expectException(ConflictDetectedException::class);
        $pipeline->executeApprovedChange($cr);
    }

    public function test_verification_failure_distinguishes_submitted_request_from_live_verification(): void
    {
        Http::fake([
            'https://blog.techboloy.com/wp-json/wp/v2/pages/99' => Http::sequence()
                // 1. Initial snapshot
                ->push([
                    'id' => 99,
                    'title' => ['rendered' => 'Features'],
                    'yoast_head_json' => ['title' => 'Original Features Title'],
                ], 200)
                // 2. Pre-execution re-read (checksum matches)
                ->push([
                    'id' => 99,
                    'title' => ['rendered' => 'Features'],
                    'yoast_head_json' => ['title' => 'Original Features Title'],
                ], 200)
                // 3. API returns 200 OK (merely submitted)
                ->push(['id' => 99], 200)
                // 4. Live read-back reveals WordPress filter ignored the SEO field!
                ->push([
                    'id' => 99,
                    'title' => ['rendered' => 'Features'],
                    'yoast_head_json' => ['title' => 'Original Features Title'], // unchanged!
                ], 200),
        ]);

        $pipeline = app(CmsExecutionPipeline::class);
        $cr = $pipeline->stageChangeRequest(
            website: $this->wpWebsite,
            user: $this->ownerUser,
            actionType: 'update_metadata',
            targetType: 'page',
            targetIdentifier: '99',
            proposedPayload: ['meta_title' => 'Unsaved Desired SEO Title']
        );

        $cr->update([
            'status' => CmsChangeRequest::STATUS_APPROVED,
            'approved_by' => $this->ownerUser->id,
            'approved_at' => now(),
        ]);

        $this->expectException(ChangeVerificationFailedException::class);
        $pipeline->executeApprovedChange($cr);
    }

    public function test_safe_rollback_restores_pre_change_snapshot(): void
    {
        Http::fake([
            'https://blog.techboloy.com/wp-json/wp/v2/pages/100' => Http::sequence()
                // 1. Staging fetch
                ->push([
                    'id' => 100,
                    'title' => ['rendered' => 'Pricing'],
                    'yoast_head_json' => ['title' => 'Original Pricing'],
                ], 200)
                // 2. Pre-exec conflict check
                ->push([
                    'id' => 100,
                    'title' => ['rendered' => 'Pricing'],
                    'yoast_head_json' => ['title' => 'Original Pricing'],
                ], 200)
                // 3. Execution update
                ->push(['id' => 100], 200)
                // 4. Verification read-back
                ->push([
                    'id' => 100,
                    'title' => ['rendered' => 'Pricing'],
                    'yoast_head_json' => ['title' => 'Optimized Pricing Title'],
                ], 200)
                // 5. Rollback update POST restoring original
                ->push(['id' => 100], 200)
                // 6. Rollback verification read-back
                ->push([
                    'id' => 100,
                    'title' => ['rendered' => 'Pricing'],
                    'yoast_head_json' => ['title' => 'Original Pricing'],
                ], 200),
        ]);

        $pipeline = app(CmsExecutionPipeline::class);
        $cr = $pipeline->stageChangeRequest(
            website: $this->wpWebsite,
            user: $this->ownerUser,
            actionType: 'update_metadata',
            targetType: 'page',
            targetIdentifier: '100',
            proposedPayload: ['meta_title' => 'Optimized Pricing Title']
        );

        $cr->update([
            'status' => CmsChangeRequest::STATUS_APPROVED,
            'approved_by' => $this->ownerUser->id,
            'approved_at' => now(),
        ]);

        $executedCr = $pipeline->executeApprovedChange($cr);
        $this->assertEquals(CmsChangeRequest::STATUS_VERIFIED, $executedCr->status);

        // Perform rollback
        $rolledBackCr = $pipeline->rollbackToSnapshot($executedCr);
        $this->assertEquals(CmsChangeRequest::STATUS_ROLLED_BACK, $rolledBackCr->status);
        $this->assertNotNull($rolledBackCr->rolled_back_at);
    }
}
