<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integrations\CmsAdapterManager;
use App\Domain\Integrations\CmsExecutionPipeline;
use App\Domain\Integrations\Exceptions\ChangeVerificationFailedException;
use App\Domain\Integrations\Exceptions\ConflictDetectedException;
use App\Domain\Integrations\Exceptions\UnsupportedCapabilityException;
use App\Http\Controllers\Controller;
use App\Models\CmsChangeRequest;
use App\Models\CmsSnapshot;
use App\Models\Organization;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CmsExecutionController extends Controller
{
    private CmsExecutionPipeline $pipeline;
    private CmsAdapterManager $adapterManager;

    public function __construct(CmsExecutionPipeline $pipeline, CmsAdapterManager $adapterManager)
    {
        $this->pipeline = $pipeline;
        $this->adapterManager = $adapterManager;
    }

    /**
     * List change execution requests for a website.
     */
    public function index(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $query = CmsChangeRequest::where('website_id', $website->id)
            ->with(['requester:id,name,email', 'approver:id,name,email', 'preChangeSnapshot', 'postChangeSnapshot']);

        if ($request->has('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('action_type') && $request->input('action_type') !== 'all') {
            $query->where('action_type', $request->input('action_type'));
        }

        $changeRequests = $query->orderByDesc('created_at')
            ->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $changeRequests->items(),
            'meta' => [
                'current_page' => $changeRequests->currentPage(),
                'last_page' => $changeRequests->lastPage(),
                'total' => $changeRequests->total(),
            ],
        ]);
    }

    /**
     * Get platform adapter capabilities.
     */
    public function capabilities(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $adapter = $this->adapterManager->resolveAdapter($website);

        return response()->json([
            'success' => true,
            'data' => [
                'platform' => $website->platform,
                'provider' => $adapter->getProviderName(),
                'capabilities' => $adapter->getCapabilities(),
                'is_read_only' => !$adapter->supports('update_metadata') && !$adapter->supports('update_content'),
            ],
        ]);
    }

    /**
     * Stage a change request (read state, save snapshot, generate diff, set to pending_approval).
     */
    public function stage(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $validated = $request->validate([
            'action_type' => 'required|string|in:update_metadata,update_content,create_draft',
            'target_type' => 'required|string|in:page,post,product,article',
            'target_identifier' => 'required|string',
            'proposed_payload' => 'required|array',
            'idempotency_key' => 'nullable|string|max:64',
            'risk_level' => 'nullable|string|in:low,medium,high',
        ]);

        try {
            $changeRequest = $this->pipeline->stageChangeRequest(
                $website,
                $request->user(),
                $validated['action_type'],
                $validated['target_type'],
                $validated['target_identifier'],
                $validated['proposed_payload'],
                $validated['idempotency_key'] ?? null,
                $validated['risk_level'] ?? 'medium'
            );

            return response()->json([
                'success' => true,
                'message' => 'Change request staged and pre-change snapshot captured. Awaiting approval.',
                'data' => $changeRequest->load(['preChangeSnapshot']),
            ]);
        } catch (UnsupportedCapabilityException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CAPABILITY_NOT_SUPPORTED',
                    'message' => $e->getMessage(),
                    'provider' => $e->getProvider(),
                    'capability' => $e->getCapability(),
                ],
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'STAGE_CHANGE_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        }
    }

    /**
     * Approve change request (Role restricted to seo_manager, admin, or owner).
     */
    public function approve(Request $request, Website $website, CmsChangeRequest $changeRequest): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        if ($changeRequest->website_id !== $website->id) {
            abort(404, 'Change request not found.');
        }

        try {
            $approved = $this->pipeline->approveChangeRequest($changeRequest, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Change request approved. Ready for execution.',
                'data' => $approved,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'APPROVAL_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        }
    }

    /**
     * Reject change request.
     */
    public function reject(Request $request, Website $website, CmsChangeRequest $changeRequest): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        if ($changeRequest->website_id !== $website->id) {
            abort(404, 'Change request not found.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $rejected = $this->pipeline->rejectChangeRequest($changeRequest, $request->user(), $validated['reason']);

        return response()->json([
            'success' => true,
            'message' => 'Change request rejected.',
            'data' => $rejected,
        ]);
    }

    /**
     * Execute approved change with full verification and conflict detection.
     */
    public function execute(Request $request, Website $website, CmsChangeRequest $changeRequest): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        if ($changeRequest->website_id !== $website->id) {
            abort(404, 'Change request not found.');
        }

        // Quota check: live CMS write permission for plan
        try {
            app(\App\Domain\Billing\QuotaEnforcementService::class)->assertCanUseCmsWrite($website->organization);
        } catch (\App\Domain\Billing\Exceptions\QuotaExceededException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'QUOTA_EXCEEDED',
                    'message' => $e->getMessage(),
                    'required_plan' => 'agency_pro',
                ],
            ], 403);
        }

        try {
            $executed = $this->pipeline->executeApprovedChange($changeRequest);

            return response()->json([
                'success' => true,
                'message' => 'Change executed and verified successfully on remote CMS.',
                'data' => $executed->load(['postChangeSnapshot', 'preChangeSnapshot']),
            ]);
        } catch (ConflictDetectedException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CONFLICT_DETECTED',
                    'message' => $e->getMessage(),
                    'target_identifier' => $e->getTargetIdentifier(),
                ],
            ], 409);
        } catch (ChangeVerificationFailedException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VERIFICATION_FAILED',
                    'message' => $e->getMessage(),
                    'details' => $e->getVerificationDetails(),
                ],
            ], 422);
        } catch (\InvalidArgumentException | \UnexpectedValueException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_STATE',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'Cannot execute unapproved') ? 422 : 500;
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EXECUTION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], $status);
        }
    }

    /**
     * Rollback a change to its pre-change snapshot.
     */
    public function rollback(Request $request, Website $website, CmsChangeRequest $changeRequest): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        if ($changeRequest->website_id !== $website->id) {
            abort(404, 'Change request not found.');
        }

        try {
            $rolledBack = $this->pipeline->rollbackToSnapshot($changeRequest);

            return response()->json([
                'success' => true,
                'message' => 'Page successfully rolled back to pre-change snapshot state.',
                'data' => $rolledBack,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ROLLBACK_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * List historical snapshots for a website.
     */
    public function snapshots(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $snapshots = CmsSnapshot::where('website_id', $website->id)
            ->with(['creator:id,name,email'])
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $snapshots->items(),
            'meta' => [
                'current_page' => $snapshots->currentPage(),
                'last_page' => $snapshots->lastPage(),
                'total' => $snapshots->total(),
            ],
        ]);
    }

    private function authorizeWebsite(Request $request, Website $website): void
    {
        $orgId = $request->header('X-Organization-Id');
        if ($orgId && (int) $website->organization_id !== (int) $orgId) {
            abort(403, 'Unauthorized access to website.');
        }
    }
}
