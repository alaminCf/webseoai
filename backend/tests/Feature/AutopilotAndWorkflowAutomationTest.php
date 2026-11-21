<?php

namespace Tests\Feature;

use App\Domain\Autopilot\Exceptions\AutomationPolicyViolationException;
use App\Domain\Autopilot\WorkflowAutomationService;
use App\Models\AutomationRule;
use App\Models\CmsChangeRequest;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use App\Models\WorkflowApproval;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutopilotAndWorkflowAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerUser;
    private Organization $org;
    private Website $wpWebsite;
    private Website $customWebsite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerUser = User::factory()->create(['email' => 'autopilot-admin@techboloy.com']);
        $this->org = Organization::create([
            'name' => 'Autopilot Central',
            'slug' => 'autopilot-central',
            'owner_id' => $this->ownerUser->id,
        ]);

        OrganizationMember::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->ownerUser->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        $this->wpWebsite = Website::create([
            'organization_id' => $this->org->id,
            'name' => 'Agency Live Blog',
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
                'app_password' => 'test-pass-123',
                'seo_plugin' => 'yoast',
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

    public function test_can_create_pause_resume_and_disable_automation_rules(): void
    {
        $token = $this->ownerUser->createToken('test')->plainTextToken;

        // 1. Create rule
        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->postJson("/api/v1/websites/{$this->wpWebsite->id}/automation/rules", [
                'name' => 'Weekly Full SEO Health Check',
                'trigger_type' => 'schedule',
                'trigger_config' => ['schedule' => 'weekly', 'cron' => '0 0 * * 1'],
                'action_type' => 'full_audit_and_recommend',
                'action_config' => ['max_pages' => 50],
                'is_write_capable' => false,
                'require_human_approval' => true,
            ]);

        $res->assertStatus(201);
        $ruleId = $res->json('data.id');
        $this->assertEquals('active', $res->json('data.status'));

        // 2. Pause rule
        $pauseRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->patchJson("/api/v1/websites/{$this->wpWebsite->id}/automation/rules/{$ruleId}/status", [
                'status' => 'paused',
            ]);
        $pauseRes->assertStatus(200);
        $this->assertEquals('paused', $pauseRes->json('data.status'));

        // 3. Trigger paused rule -> Expect 422 Policy Violation
        $triggerRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->postJson("/api/v1/websites/{$this->wpWebsite->id}/automation/rules/{$ruleId}/trigger");
        $triggerRes->assertStatus(422)
            ->assertJsonPath('error.code', 'POLICY_VIOLATION');
    }

    public function test_policy_engine_blocks_write_rules_on_read_only_custom_platforms(): void
    {
        $rule = AutomationRule::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->customWebsite->id,
            'created_by' => $this->ownerUser->id,
            'name' => 'Illegal Write Autopilot on Custom Platform',
            'trigger_type' => 'issue_detected',
            'action_type' => 'auto_stage_metadata_fix',
            'is_write_capable' => true, // write requested on custom read-only site
            'status' => AutomationRule::STATUS_ACTIVE,
        ]);

        $service = app(WorkflowAutomationService::class);

        $this->expectException(AutomationPolicyViolationException::class);
        $service->triggerRule($rule, 'test');
    }

    public function test_workflow_2_missing_metadata_places_item_in_approval_queue(): void
    {
        Http::fake([
            // WordPress mock for pre-change snapshot during staging
            'https://blog.techboloy.com/wp-json/wp/v2/pages/42' => Http::response([
                'id' => 42,
                'title' => ['rendered' => 'Features'],
                'slug' => 'features',
                'yoast_head_json' => ['title' => 'Features'],
            ], 200),
        ]);

        $rule = AutomationRule::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->wpWebsite->id,
            'created_by' => $this->ownerUser->id,
            'name' => 'Missing Metadata Auto-Stager',
            'trigger_type' => 'issue_detected',
            'action_type' => 'auto_stage_metadata_fix',
            'is_write_capable' => true,
            'require_human_approval' => true, // Human approval strictly required
            'status' => AutomationRule::STATUS_ACTIVE,
        ]);

        $service = app(WorkflowAutomationService::class);
        $run = $service->triggerRule(
            $rule,
            triggerSource: 'issue_detected',
            triggerPayload: ['target_url' => 'https://blog.techboloy.com/features', 'target_id' => '42']
        );

        $this->assertEquals(WorkflowRun::STATUS_WAITING_FOR_APPROVAL, $run->status);

        // Verify approval queue item created
        $approval = WorkflowApproval::where('workflow_run_id', $run->id)->first();
        $this->assertNotNull($approval);
        $this->assertEquals(WorkflowApproval::STATUS_PENDING, $approval->status);
        $this->assertNotNull($approval->cms_change_request_id);

        // Verify step log exists
        $this->assertNotEmpty($run->step_results);
    }

    public function test_human_approval_resumes_workflow_and_executes_cms_change(): void
    {
        Http::fake([
            'https://blog.techboloy.com/wp-json/wp/v2/pages/42' => Http::sequence()
                // 1. Initial snapshot fetch during staging
                ->push([
                    'id' => 42,
                    'title' => ['rendered' => 'Features'],
                    'slug' => 'features',
                    'yoast_head_json' => ['title' => 'Features'],
                ], 200)
                // 2. Conflict check before execution
                ->push([
                    'id' => 42,
                    'title' => ['rendered' => 'Features'],
                    'slug' => 'features',
                    'yoast_head_json' => ['title' => 'Features'],
                ], 200)
                // 3. Execution POST update
                ->push([
                    'id' => 42,
                    'title' => ['rendered' => 'Features'],
                    'slug' => 'features',
                    'yoast_head_json' => ['title' => 'Optimized Title | Agency Live Blog'],
                ], 200)
                // 4. Verification read-back
                ->push([
                    'id' => 42,
                    'title' => ['rendered' => 'Features'],
                    'slug' => 'features',
                    'yoast_head_json' => ['title' => 'Optimized Title | Agency Live Blog'],
                ], 200),
        ]);

        $rule = AutomationRule::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->wpWebsite->id,
            'created_by' => $this->ownerUser->id,
            'name' => 'Auto-Stage Meta Fixer',
            'trigger_type' => 'issue_detected',
            'action_type' => 'auto_stage_metadata_fix',
            'is_write_capable' => true,
            'require_human_approval' => true,
            'status' => AutomationRule::STATUS_ACTIVE,
        ]);

        $service = app(WorkflowAutomationService::class);
        $run = $service->triggerRule(
            $rule,
            triggerSource: 'issue_detected',
            triggerPayload: ['target_url' => 'https://blog.techboloy.com/features', 'target_id' => '42']
        );

        $approval = WorkflowApproval::where('workflow_run_id', $run->id)->first();
        $this->assertNotNull($approval);

        // Approve action via API
        $token = $this->ownerUser->createToken('test')->plainTextToken;
        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->postJson("/api/v1/websites/{$this->wpWebsite->id}/automation/approvals/{$approval->id}/approve", [
                'notes' => 'Approved after human inspection of diff',
            ]);

        $res->assertStatus(200);
        $approval->refresh();
        $this->assertEquals(WorkflowApproval::STATUS_APPROVED, $approval->status);

        // Verify CMS change request was approved
        $cr = CmsChangeRequest::find($approval->cms_change_request_id);
        $this->assertEquals(CmsChangeRequest::STATUS_APPROVED, $cr->status);
    }

    public function test_dry_run_mode_does_not_mutate_or_queue_live_changes(): void
    {
        $rule = AutomationRule::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->wpWebsite->id,
            'created_by' => $this->ownerUser->id,
            'name' => 'Dry Run Meta Optimizer',
            'trigger_type' => 'issue_detected',
            'action_type' => 'auto_stage_metadata_fix',
            'is_write_capable' => true,
            'require_human_approval' => true,
            'dry_run_mode' => true, // DRY RUN
            'status' => AutomationRule::STATUS_ACTIVE,
        ]);

        $service = app(WorkflowAutomationService::class);
        $run = $service->triggerRule(
            $rule,
            triggerSource: 'manual_test',
            triggerPayload: ['target_url' => 'https://blog.techboloy.com/features', 'target_id' => '42']
        );

        $this->assertEquals(WorkflowRun::STATUS_SUCCEEDED, $run->status);

        // Zero approvals created because of dry-run
        $approvalsCount = WorkflowApproval::where('workflow_run_id', $run->id)->count();
        $this->assertEquals(0, $approvalsCount);

        // Confirm step result logs dry run notice
        $steps = collect($run->step_results);
        $dryRunStep = $steps->firstWhere('step', 'dry_run_evaluation');
        $this->assertNotNull($dryRunStep);
    }

    public function test_workflow_cancellation_marks_run_and_revokes_pending_approvals(): void
    {
        $rule = AutomationRule::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->wpWebsite->id,
            'created_by' => $this->ownerUser->id,
            'name' => 'To Be Cancelled',
            'trigger_type' => 'issue_detected',
            'action_type' => 'auto_stage_metadata_fix',
            'is_write_capable' => true,
            'require_human_approval' => true,
            'status' => AutomationRule::STATUS_ACTIVE,
        ]);

        $run = WorkflowRun::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->wpWebsite->id,
            'automation_rule_id' => $rule->id,
            'run_uuid' => 'test-cancel-uuid',
            'idempotency_key' => 'test-cancel-key',
            'trigger_source' => 'test',
            'status' => WorkflowRun::STATUS_WAITING_FOR_APPROVAL,
        ]);

        $approval = WorkflowApproval::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->wpWebsite->id,
            'workflow_run_id' => $run->id,
            'title' => 'Pending Action',
            'status' => WorkflowApproval::STATUS_PENDING,
        ]);

        $token = $this->ownerUser->createToken('test')->plainTextToken;
        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Organization-Id', $this->org->id)
            ->postJson("/api/v1/websites/{$this->wpWebsite->id}/automation/runs/{$run->id}/cancel", [
                'reason' => 'User aborted run',
            ]);

        $res->assertStatus(200);
        $run->refresh();
        $approval->refresh();

        $this->assertEquals(WorkflowRun::STATUS_CANCELLED, $run->status);
        $this->assertEquals(WorkflowApproval::STATUS_REVOKED, $approval->status);
    }

    public function test_idempotency_prevents_duplicate_trigger_execution(): void
    {
        $rule = AutomationRule::create([
            'organization_id' => $this->org->id,
            'website_id' => $this->wpWebsite->id,
            'created_by' => $this->ownerUser->id,
            'name' => 'Idempotent Sync',
            'trigger_type' => 'schedule',
            'action_type' => 'sync_gsc_and_report',
            'status' => AutomationRule::STATUS_ACTIVE,
        ]);

        $service = app(WorkflowAutomationService::class);

        $run1 = $service->triggerRule($rule, 'schedule', ['date' => '2026-10-12'], 'idemp_key_123');
        $run2 = $service->triggerRule($rule, 'schedule', ['date' => '2026-10-12'], 'idemp_key_123');

        $this->assertEquals($run1->id, $run2->id);
        $this->assertEquals(1, WorkflowRun::where('idempotency_key', 'idemp_key_123')->count());
    }
}
