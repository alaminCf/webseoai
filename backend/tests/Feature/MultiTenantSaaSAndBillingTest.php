<?php

namespace Tests\Feature;

use App\Models\AuditIssue;
use App\Models\AuditRun;
use App\Models\BillingWebhookEvent;
use App\Models\ClientApprovalRequest;
use App\Models\ClientWorkspace;
use App\Models\CmsChangeRequest;
use App\Models\CrawlJob;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenantSaaSAndBillingTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Organization $org;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'name' => 'Agency Director',
            'email' => 'director@agency.test',
        ]);

        $this->org = Organization::create([
            'name' => 'Apex SEO Agency',
            'slug' => 'apex-seo-agency',
            'billing_plan' => 'agency_pro',
        ]);

        OrganizationMember::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        $this->token = $this->owner->createToken('test-token')->plainTextToken;
    }

    protected function authHeaders(array $extra = []): array
    {
        return array_merge([
            'Authorization' => "Bearer {$this->token}",
            'X-Organization-Id' => (string) $this->org->id,
            'Accept' => 'application/json',
        ], $extra);
    }

    /**
     * 1. Quota Enforcement: Blocks website creation when plan limit reached.
     */
    public function test_quota_enforcement_blocks_website_creation_when_limit_reached(): void
    {
        // Set organization to Starter plan (limit: 3 websites)
        $this->org->update(['billing_plan' => 'starter']);

        // Create 3 existing websites
        for ($i = 1; $i <= 3; $i++) {
            Website::create([
                'organization_id' => $this->org->id,
                'name' => "Client Site {$i}",
                'domain' => "client{$i}.com",
                'target_url' => "https://client{$i}.com",
                'platform' => Website::PLATFORM_WORDPRESS,
            ]);
        }

        // Attempt to create 4th website
        $response = $this->postJson('/api/v1/websites', [
            'name' => 'Client Site 4',
            'domain' => 'client4.com',
            'target_url' => 'https://client4.com',
            'platform' => Website::PLATFORM_WORDPRESS,
        ], $this->authHeaders());

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'QUOTA_EXCEEDED');
        $this->assertStringContainsString('Website limit of 3 reached', $response->json('error.message'));
    }

    /**
     * 2. Quota Enforcement: Blocks team invitations when seats limit reached.
     */
    public function test_quota_enforcement_blocks_team_invitations_when_seats_reached(): void
    {
        // Set organization to Starter plan (limit: 2 seats)
        $this->org->update(['billing_plan' => 'starter']);

        // Already has 1 member (owner). Add 2nd member
        $secondUser = User::factory()->create(['email' => 'second@agency.test']);
        OrganizationMember::create([
            'organization_id' => $this->org->id,
            'user_id' => $secondUser->id,
            'role' => OrganizationMember::ROLE_EDITOR,
        ]);

        // Attempt to invite 3rd member
        $response = $this->postJson('/api/v1/organizations/current/members', [
            'email' => 'third@agency.test',
            'name' => 'Third Specialist',
            'role' => OrganizationMember::ROLE_EDITOR,
        ], $this->authHeaders());

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'QUOTA_EXCEEDED');
        $this->assertStringContainsString('Team seat limit of 2 reached', $response->json('error.message'));
    }

    /**
     * 3. Quota Enforcement: Blocks live CMS execution on plans without CMS write support.
     */
    public function test_quota_enforcement_blocks_live_cms_execution_for_unsupported_plans(): void
    {
        // Set organization to Starter plan (supports_cms_write = false)
        $this->org->update(['billing_plan' => 'starter']);

        $website = Website::create([
            'organization_id' => $this->org->id,
            'name' => 'Store Front',
            'domain' => 'storefront.com',
            'target_url' => 'https://storefront.com',
            'platform' => Website::PLATFORM_WORDPRESS,
        ]);

        $changeRequest = CmsChangeRequest::create([
            'organization_id' => $this->org->id,
            'website_id' => $website->id,
            'user_id' => $this->owner->id,
            'approved_by' => $this->owner->id,
            'cms_provider' => 'wordpress',
            'action_type' => 'update_metadata',
            'target_type' => 'post',
            'target_identifier' => '101',
            'status' => CmsChangeRequest::STATUS_APPROVED,
            'risk_level' => 'low',
            'idempotency_key' => 'idemp_quota_test_123',
            'approved_at' => now(),
            'proposed_payload' => ['title' => 'Optimized Title'],
        ]);

        $response = $this->postJson(
            "/api/v1/websites/{$website->id}/cms/change-requests/{$changeRequest->id}/execute",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'QUOTA_EXCEEDED');
        $this->assertStringContainsString('Live CMS write execution is not included', $response->json('error.message'));
    }

    /**
     * 4. Billing: Plan listing, usage metrics, checkout session and portal generation.
     */
    public function test_billing_checkout_and_usage_summary(): void
    {
        // Test plans list
        $response = $this->getJson('/api/v1/billing/plans', $this->authHeaders());
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.plans'));

        // Test usage summary
        $usageRes = $this->getJson('/api/v1/billing/usage', $this->authHeaders());
        $usageRes->assertStatus(200);
        $usageRes->assertJsonPath('data.plan.id', 'agency_pro');
        $this->assertArrayHasKey('websites', $usageRes->json('data.quotas'));

        // Test checkout session generation
        $checkoutRes = $this->postJson('/api/v1/billing/checkout-session', [
            'plan_id' => 'enterprise',
            'billing_cycle' => 'yearly',
        ], $this->authHeaders());

        $checkoutRes->assertStatus(200);
        $this->assertStringContainsString('checkout.stripe.com', $checkoutRes->json('data.checkout_url'));
        $this->assertEquals(5990, $checkoutRes->json('data.price'));

        // Test portal session generation
        $portalRes = $this->postJson('/api/v1/billing/portal-session', [], $this->authHeaders());
        $portalRes->assertStatus(200);
        $this->assertStringContainsString('billing.stripe.com', $portalRes->json('data.portal_url'));
    }

    /**
     * 5. Webhook: Idempotent processing and subscription status transitions.
     */
    public function test_billing_webhook_is_idempotent_and_updates_subscription(): void
    {
        $eventId = 'evt_test_checkout_12345';
        $payload = json_encode([
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_12345',
                    'client_reference_id' => $this->org->id,
                    'customer' => 'cus_apex_999',
                    'subscription' => 'sub_apex_888',
                    'metadata' => [
                        'plan_id' => 'enterprise',
                        'billing_cycle' => 'monthly',
                        'organization_id' => $this->org->id,
                    ],
                ],
            ],
        ]);

        // First webhook delivery
        $res1 = $this->call(
            'POST',
            '/api/v1/billing/webhook',
            [],
            [],
            [],
            [
                'HTTP_STRIPE_SIGNATURE' => 'test_mock_valid_signature',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $payload
        );

        $res1->assertStatus(200);
        $res1->assertJsonPath('success', true);
        $res1->assertJsonPath('idempotent', false);

        // Verify organization subscription updated to enterprise
        $this->assertEquals('enterprise', $this->org->fresh()->billing_plan);
        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => $this->org->id,
            'plan_id' => 'enterprise',
            'status' => 'active',
            'stripe_customer_id' => 'cus_apex_999',
        ]);

        // Duplicate delivery with same event ID (Idempotency test)
        $res2 = $this->call(
            'POST',
            '/api/v1/billing/webhook',
            [],
            [],
            [],
            [
                'HTTP_STRIPE_SIGNATURE' => 'test_mock_valid_signature',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $payload
        );

        $res2->assertStatus(200);
        $res2->assertJsonPath('idempotent', true);
        $this->assertEquals(1, BillingWebhookEvent::where('event_id', $eventId)->count());

        // Invoice payment failed transition
        $failPayload = json_encode([
            'id' => 'evt_test_fail_67890',
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'customer' => 'cus_apex_999',
                    'attempt_count' => 1,
                ],
            ],
        ]);

        $failRes = $this->call(
            'POST',
            '/api/v1/billing/webhook',
            [],
            [],
            [],
            [
                'HTTP_STRIPE_SIGNATURE' => 'test_mock_valid_signature',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $failPayload
        );

        $failRes->assertStatus(200);
        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => $this->org->id,
            'status' => 'past_due',
        ]);
    }

    /**
     * 6. White-Label Reports: Custom agency branding and verified audit report generation.
     */
    public function test_white_label_reports_and_branding(): void
    {
        // 1. Update agency branding
        $brandRes = $this->putJson('/api/v1/white-label/branding', [
            'brand_name' => 'Apex Growth Partners',
            'logo_url' => 'https://apexagency.test/logo.png',
            'primary_color' => '#059669',
            'contact_email' => 'reports@apexagency.test',
            'footer_text' => 'Prepared specifically for Executive Leadership',
            'remove_platform_branding' => true,
        ], $this->authHeaders());

        $brandRes->assertStatus(200);
        $this->assertEquals('Apex Growth Partners', $brandRes->json('data.brand_name'));

        // 2. Setup website with audit data
        $website = Website::create([
            'organization_id' => $this->org->id,
            'name' => 'Alpha Client',
            'domain' => 'alphaclient.com',
            'target_url' => 'https://alphaclient.com',
            'platform' => Website::PLATFORM_WORDPRESS,
            'health_score' => 92,
        ]);

        $crawlJob = CrawlJob::create([
            'organization_id' => $this->org->id,
            'website_id' => $website->id,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $auditRun = AuditRun::create([
            'organization_id' => $this->org->id,
            'website_id' => $website->id,
            'crawl_job_id' => $crawlJob->id,
            'overall_score' => 92,
            'technical_score' => 90,
            'content_score' => 95,
            'link_score' => 90,
            'critical_count' => 1,
            'warning_count' => 0,
            'info_count' => 0,
            'created_at' => now(),
        ]);

        AuditIssue::create([
            'website_id' => $website->id,
            'audit_run_id' => $auditRun->id,
            'affected_url' => 'https://alphaclient.com',
            'severity' => 'critical',
            'category' => 'technical',
            'issue_type' => 'missing_title',
            'explanation' => 'Target page lacks HTML title tag.',
            'recommended_fix' => 'Add unique meta title.',
            'current_status' => AuditIssue::STATUS_OPEN,
        ]);

        // 3. Generate white-label report
        $reportRes = $this->getJson("/api/v1/websites/{$website->id}/reports/white-label", $this->authHeaders());

        $reportRes->assertStatus(200);
        $reportRes->assertJsonPath('data.branding.agency_name', 'Apex Growth Partners');
        $reportRes->assertJsonPath('data.branding.theme_color', '#059669');
        $reportRes->assertJsonPath('data.audit_summary.critical_issues', 1);
        $this->assertNotEmpty($reportRes->json('data.report_id'));
    }

    /**
     * 7. Strict Multi-Tenant Isolation: Cross-organization access rejected on all resources.
     */
    public function test_strict_tenant_isolation_cross_organization_access_rejected(): void
    {
        // Create Foreign Organization B with a website and user
        $foreignOrg = Organization::create([
            'name' => 'Rival Marketing Corp',
            'slug' => 'rival-marketing',
            'billing_plan' => 'agency_pro',
        ]);

        $foreignWebsite = Website::create([
            'organization_id' => $foreignOrg->id,
            'name' => 'Foreign Website',
            'domain' => 'foreign.test',
            'target_url' => 'https://foreign.test',
            'platform' => Website::PLATFORM_WORDPRESS,
        ]);

        // Attempt to access Foreign Website from Apex SEO Agency context
        $res1 = $this->getJson("/api/v1/websites/{$foreignWebsite->id}", $this->authHeaders());
        $res1->assertStatus(404);

        // Attempt to crawl Foreign Website
        $res2 = $this->postJson("/api/v1/websites/{$foreignWebsite->id}/crawl", [
            'max_pages' => 10,
        ], $this->authHeaders());
        $res2->assertStatus(404);

        // Attempt to access Foreign Website white-label report
        $res3 = $this->getJson("/api/v1/websites/{$foreignWebsite->id}/reports/white-label", $this->authHeaders());
        $res3->assertStatus(404);

        // Attempt to stage CMS change on Foreign Website
        $res4 = $this->postJson("/api/v1/websites/{$foreignWebsite->id}/cms/change-requests/stage", [
            'action_type' => 'update_metadata',
            'target_type' => 'post',
            'target_identifier' => '1',
            'proposed_payload' => ['title' => 'Hacked Title'],
        ], $this->authHeaders());
        $this->assertTrue(in_array($res4->status(), [403, 404]), "Expected 403 or 404, got {$res4->status()}");
    }

    /**
     * 8. Client Workspaces & Public Sign-off Approval Workflow.
     */
    public function test_client_workspaces_and_public_approval_workflow(): void
    {
        // 1. Create Client Workspace
        $wsRes = $this->postJson('/api/v1/clients/workspaces', [
            'name' => 'Acme Corporation',
            'company_name' => 'Acme Holdings Ltd',
            'client_email' => 'ceo@acme.test',
            'contact_person' => 'Jane Doe',
        ], $this->authHeaders());

        $wsRes->assertStatus(201);
        $workspaceId = $wsRes->json('data.id');

        // 2. Assign website to client workspace
        $website = Website::create([
            'organization_id' => $this->org->id,
            'name' => 'Acme Store',
            'domain' => 'acmestore.test',
            'target_url' => 'https://acmestore.test',
            'platform' => Website::PLATFORM_SHOPIFY,
        ]);

        $assignRes = $this->postJson("/api/v1/clients/workspaces/{$workspaceId}/websites", [
            'website_ids' => [$website->id],
        ], $this->authHeaders());

        $assignRes->assertStatus(200);
        $this->assertEquals($workspaceId, $website->fresh()->client_workspace_id);

        // 3. Create client approval request for metadata strategy
        $reqRes = $this->postJson("/api/v1/websites/{$website->id}/client-approvals", [
            'approver_email' => 'ceo@acme.test',
            'title' => 'Q4 Homepage SEO Title & Meta Redesign',
            'description' => 'Optimizing homepage CTR from 1.4% to 3.8%.',
            'request_type' => 'seo_strategy',
        ], $this->authHeaders());

        $reqRes->assertStatus(201);
        $token = $reqRes->json('data.token');
        $this->assertNotEmpty($token);

        // 4. Client views approval via public link (No auth headers needed)
        $publicView = $this->getJson("/api/v1/client-approvals/{$token}");
        $publicView->assertStatus(200);
        $publicView->assertJsonPath('data.title', 'Q4 Homepage SEO Title & Meta Redesign');
        $publicView->assertJsonPath('data.is_pending', true);

        // 5. Client approves via public token
        $publicAction = $this->postJson("/api/v1/client-approvals/{$token}/respond", [
            'approved' => true,
            'comment' => 'Looks phenomenal. Approved for live publishing.',
        ]);

        $publicAction->assertStatus(200);
        $publicAction->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('client_approval_requests', [
            'token' => $token,
            'status' => 'approved',
            'client_comment' => 'Looks phenomenal. Approved for live publishing.',
        ]);
    }

    /**
     * 9. Organization Deletion Workflow: GDPR compliance and complete data purge.
     */
    public function test_organization_deletion_workflow_permanently_wipes_tenant_data(): void
    {
        $site = Website::create([
            'organization_id' => $this->org->id,
            'name' => 'Target To Delete',
            'domain' => 'todelete.test',
            'target_url' => 'https://todelete.test',
            'platform' => Website::PLATFORM_WORDPRESS,
        ]);

        // Attempt deletion with mismatched confirmation phrase
        $failDel = $this->postJson('/api/v1/organizations/current/delete', [
            'confirmation' => 'Wrong Name',
        ], $this->authHeaders());

        $failDel->assertStatus(422);

        // Execute deletion with exact organization name
        $successDel = $this->postJson('/api/v1/organizations/current/delete', [
            'confirmation' => $this->org->name,
        ], $this->authHeaders());

        $successDel->assertStatus(200);

        // Assert organization and child records wiped
        $this->assertDatabaseMissing('organizations', ['id' => $this->org->id]);
        $this->assertDatabaseMissing('websites', ['id' => $site->id]);
        $this->assertDatabaseMissing('organization_members', ['organization_id' => $this->org->id]);
    }
}
