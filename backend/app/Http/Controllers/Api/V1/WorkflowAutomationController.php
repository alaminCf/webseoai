<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Autopilot\Exceptions\AutomationPolicyViolationException;
use App\Domain\Autopilot\WorkflowAutomationService;
use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\Website;
use App\Models\WorkflowApproval;
use App\Models\WorkflowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WorkflowAutomationController extends Controller
{
    private WorkflowAutomationService $automationService;

    public function __construct(WorkflowAutomationService $automationService)
    {
        $this->automationService = $automationService;
    }

    /**
     * List automation rules for a website.
     */
    public function indexRules(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $rules = AutomationRule::where('website_id', $website->id)
            ->with(['creator:id,name,email'])
            ->withCount('runs')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rules,
        ]);
    }

    /**
     * Create an automation rule.
     */
    public function storeRule(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'trigger_type' => 'required|string|in:schedule,crawl_completed,issue_detected,performance_threshold,content_approved,action_failed',
            'trigger_config' => 'nullable|array',
            'action_type' => 'required|string|in:full_audit_and_recommend,auto_stage_metadata_fix,publish_approved_content,sync_gsc_and_report,recover_failed_execution',
            'action_config' => 'nullable|array',
            'is_write_capable' => 'boolean',
            'require_human_approval' => 'boolean',
            'dry_run_mode' => 'boolean',
            'max_runs_per_day' => 'nullable|integer|min:1|max:100',
            'monthly_cost_budget' => 'nullable|numeric|min:1|max:1000',
        ]);

        $rule = AutomationRule::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'created_by' => $request->user()->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'trigger_type' => $validated['trigger_type'],
            'trigger_config' => $validated['trigger_config'] ?? [],
            'action_type' => $validated['action_type'],
            'action_config' => $validated['action_config'] ?? [],
            'is_write_capable' => $validated['is_write_capable'] ?? false,
            'require_human_approval' => $validated['require_human_approval'] ?? true,
            'dry_run_mode' => $validated['dry_run_mode'] ?? false,
            'max_runs_per_day' => $validated['max_runs_per_day'] ?? 10,
            'monthly_cost_budget' => $validated['monthly_cost_budget'] ?? 50.00,
            'status' => AutomationRule::STATUS_ACTIVE,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Automation rule created successfully.',
            'data' => $rule->load('creator'),
        ], 201);
    }

    /**
     * Pause, resume or disable a rule.
     */
    public function updateRuleStatus(Request $request, Website $website, AutomationRule $rule): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        if ($rule->website_id !== $website->id) abort(404);

        $validated = $request->validate([
            'status' => 'required|string|in:active,paused,disabled',
        ]);

        $rule->update([
            'status' => $validated['status'],
            'version' => $rule->version + 1,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Automation rule status updated to {$validated['status']}.",
            'data' => $rule,
        ]);
    }

    /**
     * Test / Trigger a rule on-demand.
     */
    public function triggerRule(Request $request, Website $website, AutomationRule $rule): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        if ($rule->website_id !== $website->id) abort(404);

        try {
            $run = $this->automationService->triggerRule(
                $rule,
                triggerSource: 'manual_test',
                triggerPayload: $request->input('payload', [])
            );

            return response()->json([
                'success' => true,
                'message' => 'Automation rule triggered.',
                'data' => $run->load(['approvals']),
            ]);
        } catch (AutomationPolicyViolationException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'POLICY_VIOLATION',
                    'message' => $e->getMessage(),
                    'reason' => $e->getViolationReason(),
                ],
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TRIGGER_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * List workflow execution history runs.
     */
    public function indexRuns(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $query = WorkflowRun::where('website_id', $website->id)
            ->with(['rule:id,name,action_type', 'triggeredBy:id,name,email']);

        if ($request->has('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        $runs = $query->orderByDesc('created_at')->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $runs->items(),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'total' => $runs->total(),
            ],
        ]);
    }

    /**
     * Get single workflow run with step-level results.
     */
    public function showRun(Request $request, Website $website, WorkflowRun $run): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        if ($run->website_id !== $website->id) abort(404);

        return response()->json([
            'success' => true,
            'data' => $run->load(['rule', 'triggeredBy', 'approvals']),
        ]);
    }

    /**
     * Cancel an active or queued workflow run.
     */
    public function cancelRun(Request $request, Website $website, WorkflowRun $run): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        if ($run->website_id !== $website->id) abort(404);

        $cancelled = $this->automationService->cancelRun(
            $run,
            $request->user(),
            $request->input('reason', 'Cancelled by user')
        );

        return response()->json([
            'success' => true,
            'message' => 'Workflow run cancelled.',
            'data' => $cancelled,
        ]);
    }

    /**
     * List items in the approval queue.
     */
    public function indexApprovals(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $query = WorkflowApproval::where('website_id', $website->id)
            ->with(['workflowRun.rule', 'cmsChangeRequest', 'reviewer:id,name,email']);

        if ($request->has('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        $approvals = $query->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => $approvals,
        ]);
    }

    /**
     * Approve a pending workflow action.
     */
    public function approveAction(Request $request, Website $website, WorkflowApproval $approval): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        if ($approval->website_id !== $website->id) abort(404);

        $run = $this->automationService->approveWorkflowItem(
            $approval,
            $request->user(),
            $request->input('notes')
        );

        return response()->json([
            'success' => true,
            'message' => 'Workflow action approved. Execution resumed.',
            'data' => $run,
        ]);
    }

    /**
     * Reject a pending workflow action.
     */
    public function rejectAction(Request $request, Website $website, WorkflowApproval $approval): JsonResponse
    {
        $this->authorizeWebsite($request, $website);
        if ($approval->website_id !== $website->id) abort(404);

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $run = $this->automationService->rejectWorkflowItem(
            $approval,
            $request->user(),
            $validated['reason']
        );

        return response()->json([
            'success' => true,
            'message' => 'Workflow action rejected.',
            'data' => $run,
        ]);
    }

    private function authorizeWebsite(Request $request, Website $website): void
    {
        $orgId = $request->header('X-Organization-Id');
        if (!$orgId || (int) $website->organization_id !== (int) $orgId) {
            abort(403, 'Unauthorized access to website across organization boundary.');
        }
    }
}
