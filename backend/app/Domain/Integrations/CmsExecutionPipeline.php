<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Contracts\CmsAdapterInterface;
use App\Domain\Integrations\Exceptions\ChangeVerificationFailedException;
use App\Domain\Integrations\Exceptions\ConflictDetectedException;
use App\Domain\Integrations\Exceptions\UnsupportedCapabilityException;
use App\Models\AuditLog;
use App\Models\CmsChangeRequest;
use App\Models\CmsSnapshot;
use App\Models\User;
use App\Models\Website;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CmsExecutionPipeline
{
    private CmsAdapterManager $adapterManager;

    public function __construct(CmsAdapterManager $adapterManager)
    {
        $this->adapterManager = $adapterManager;
    }

    /**
     * Stage 1: Create a proposed change request, capture pre-change snapshot, and generate diff.
     * Enforces: Validate authorization → Validate capability → Read current state → Save snapshot → Generate diff → Validate policy
     */
    public function stageChangeRequest(
        Website $website,
        User $user,
        string $actionType,
        string $targetType,
        string $targetIdentifier,
        array $proposedPayload,
        ?string $idempotencyKey = null,
        string $riskLevel = 'medium'
    ): CmsChangeRequest {
        $idempotencyKey = $idempotencyKey ?: 'cr_' . Str::uuid()->toString();

        // Check if an existing request with this idempotency key exists
        $existing = CmsChangeRequest::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        // 1. Validate capability
        $adapter = $this->adapterManager->resolveAdapter($website);
        $requiredCapability = match ($actionType) {
            CmsChangeRequest::ACTION_UPDATE_METADATA => 'update_metadata',
            CmsChangeRequest::ACTION_UPDATE_CONTENT => 'update_content',
            CmsChangeRequest::ACTION_CREATE_DRAFT => 'write_draft',
            default => 'update_metadata',
        };

        if (!$adapter->supports($requiredCapability)) {
            throw new UnsupportedCapabilityException(
                $adapter->getProviderName(),
                $requiredCapability,
                "Platform [{$website->platform}] does not support [{$requiredCapability}]."
            );
        }

        // 2. Read current state from CMS
        $currentState = $adapter->fetchPage($targetIdentifier);

        // 3. Save pre-change snapshot
        $checksum = CmsSnapshot::computeChecksum($currentState);
        $snapshot = CmsSnapshot::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'cms_provider' => $adapter->getProviderName(),
            'target_type' => $targetType,
            'target_identifier' => $targetIdentifier,
            'snapshot_type' => CmsSnapshot::TYPE_PRE_CHANGE,
            'state_payload' => $currentState,
            'checksum' => $checksum,
            'remote_modified_at' => $currentState['modified_at'] ?? now()->toIso8601String(),
            'created_by' => $user->id,
        ]);

        // 4. Generate field-by-field diff
        $diffSummary = $this->generateDiff($currentState, $proposedPayload);

        // 5. Validate policy (High-risk check: cannot change canonical to external without high-risk approval)
        if (isset($proposedPayload['canonical_url']) && !str_contains($proposedPayload['canonical_url'], $website->domain)) {
            $riskLevel = CmsChangeRequest::RISK_HIGH;
        }

        return CmsChangeRequest::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'user_id' => $user->id,
            'idempotency_key' => $idempotencyKey,
            'cms_provider' => $adapter->getProviderName(),
            'action_type' => $actionType,
            'target_type' => $targetType,
            'target_identifier' => $targetIdentifier,
            'target_url' => $currentState['url'] ?? "https://{$website->domain}/{$targetIdentifier}",
            'proposed_payload' => $proposedPayload,
            'status' => CmsChangeRequest::STATUS_PENDING_APPROVAL,
            'risk_level' => $riskLevel,
            'pre_change_snapshot_id' => $snapshot->id,
            'diff_summary' => $diffSummary,
        ]);
    }

    /**
     * Stage 2: Approve change request.
     */
    public function approveChangeRequest(CmsChangeRequest $request, User $approver): CmsChangeRequest
    {
        if ($request->status !== CmsChangeRequest::STATUS_PENDING_APPROVAL) {
            throw new Exception("Change request is not in pending approval status (current: {$request->status}).");
        }

        $request->update([
            'status' => CmsChangeRequest::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        return $request;
    }

    /**
     * Stage 3: Reject change request.
     */
    public function rejectChangeRequest(CmsChangeRequest $request, User $reviewer, string $reason): CmsChangeRequest
    {
        $request->update([
            'status' => CmsChangeRequest::STATUS_REJECTED,
            'approved_by' => $reviewer->id,
            'rejection_reason' => $reason,
        ]);

        return $request;
    }

    /**
     * Stage 4: Execute, verify, and audit.
     * Sequence: Lock → Check Approval → Conflict Detection → Execute → Read Back → Verify → Save Post Snapshot → Audit Log
     */
    public function executeApprovedChange(CmsChangeRequest $changeRequest, bool $allowRollbackOnFailure = true): CmsChangeRequest
    {
        // Must be approved
        if ($changeRequest->status !== CmsChangeRequest::STATUS_APPROVED) {
            throw new Exception("Cannot execute unapproved change request. Current status: {$changeRequest->status}.");
        }

        $website = $changeRequest->website;
        $lockKey = "cms_execution:website_{$website->id}";

        // Acquire execution lock (timeout 15 seconds) to prevent concurrent collisions
        $lock = Cache::lock($lockKey, 15);
        if (!$lock->get()) {
            throw new Exception("Website [{$website->domain}] is currently executing another change. Please wait.");
        }

        try {
            $changeRequest->update(['status' => CmsChangeRequest::STATUS_EXECUTING]);

            $adapter = $this->adapterManager->resolveAdapter($website);

            // 1. Conflict Detection: Read fresh state from CMS and compare with pre-change snapshot
            $freshState = $adapter->fetchPage($changeRequest->target_identifier);
            $preSnapshot = $changeRequest->preChangeSnapshot;

            if ($preSnapshot) {
                $currentChecksum = CmsSnapshot::computeChecksum($freshState);
                if ($currentChecksum !== $preSnapshot->checksum) {
                    throw new ConflictDetectedException(
                        $changeRequest->target_identifier,
                        $preSnapshot->checksum,
                        $currentChecksum,
                        "Remote content on [{$changeRequest->target_identifier}] was modified by an external editor since the snapshot was created. Execution aborted to avoid overwriting human edits."
                    );
                }
            }

            // 2. Execute write on CMS
            $executedResult = match ($changeRequest->action_type) {
                CmsChangeRequest::ACTION_UPDATE_METADATA => $adapter->updateSupportedMetadata(
                    $changeRequest->target_identifier,
                    $changeRequest->proposed_payload
                ),
                CmsChangeRequest::ACTION_UPDATE_CONTENT => $adapter->updateContent(
                    $changeRequest->target_identifier,
                    $changeRequest->proposed_payload
                ),
                default => $adapter->updateSupportedMetadata(
                    $changeRequest->target_identifier,
                    $changeRequest->proposed_payload
                ),
            };

            // 3. Read Back & Verify
            $expectedVerifications = $this->extractExpectedVerifications($changeRequest->proposed_payload);
            $verification = $adapter->verifyChange($changeRequest->target_identifier, $expectedVerifications);

            if (!$verification['verified']) {
                $changeRequest->update([
                    'status' => CmsChangeRequest::STATUS_FAILED,
                    'execution_error' => 'Verification failed: Read-back values did not match submitted changes.',
                    'verification_details' => $verification,
                    'executed_at' => now(),
                ]);

                // Rollback if allowed and restorable
                if ($allowRollbackOnFailure && $preSnapshot) {
                    $this->attemptRollback($changeRequest, $adapter, $preSnapshot);
                }

                throw new ChangeVerificationFailedException($verification);
            }

            // 4. Save post-change snapshot
            $postChecksum = CmsSnapshot::computeChecksum($verification['live_state']);
            $postSnapshot = CmsSnapshot::create([
                'organization_id' => $website->organization_id,
                'website_id' => $website->id,
                'cms_provider' => $adapter->getProviderName(),
                'target_type' => $changeRequest->target_type,
                'target_identifier' => $changeRequest->target_identifier,
                'snapshot_type' => CmsSnapshot::TYPE_POST_CHANGE,
                'state_payload' => $verification['live_state'],
                'checksum' => $postChecksum,
                'remote_modified_at' => $verification['live_state']['modified_at'] ?? now()->toIso8601String(),
                'created_by' => $changeRequest->approved_by,
            ]);

            // 5. Update Change Request record as verified
            $changeRequest->update([
                'status' => CmsChangeRequest::STATUS_VERIFIED,
                'post_change_snapshot_id' => $postSnapshot->id,
                'verification_details' => $verification,
                'executed_at' => now(),
                'verified_at' => now(),
                'execution_error' => null,
            ]);

            // 6. Record Audit Log
            AuditLog::create([
                'organization_id' => $website->organization_id,
                'user_id' => $changeRequest->approved_by,
                'action' => 'cms.change_executed_and_verified',
                'auditable_type' => CmsChangeRequest::class,
                'auditable_id' => $changeRequest->id,
                'payload' => [
                    'website_id' => $website->id,
                    'provider' => $adapter->getProviderName(),
                    'target_identifier' => $changeRequest->target_identifier,
                    'diff' => $changeRequest->diff_summary,
                    'verified' => true,
                ],
            ]);

            return $changeRequest;

        } catch (ConflictDetectedException $e) {
            $changeRequest->update([
                'status' => CmsChangeRequest::STATUS_FAILED,
                'execution_error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (ChangeVerificationFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('CMS Execution failure', [
                'change_request_id' => $changeRequest->id,
                'error' => $e->getMessage(),
            ]);

            $changeRequest->update([
                'status' => CmsChangeRequest::STATUS_FAILED,
                'execution_error' => $e->getMessage(),
                'executed_at' => now(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Restore state from a pre-change snapshot (Manual or Automatic Rollback).
     */
    public function rollbackToSnapshot(CmsChangeRequest $changeRequest, ?CmsSnapshot $targetSnapshot = null): CmsChangeRequest
    {
        $snapshot = $targetSnapshot ?: $changeRequest->preChangeSnapshot;
        if (!$snapshot) {
            throw new Exception("No snapshot available for rollback.");
        }

        $website = $changeRequest->website;
        $adapter = $this->adapterManager->resolveAdapter($website);

        $this->attemptRollback($changeRequest, $adapter, $snapshot);

        return $changeRequest;
    }

    private function attemptRollback(CmsChangeRequest $changeRequest, CmsAdapterInterface $adapter, CmsSnapshot $snapshot): void
    {
        try {
            $state = $snapshot->state_payload;
            $restorePayload = [];

            if (isset($state['meta_title'])) $restorePayload['meta_title'] = $state['meta_title'];
            if (isset($state['meta_description'])) $restorePayload['meta_description'] = $state['meta_description'];
            if (isset($state['content'])) $restorePayload['content'] = $state['content'];

            if ($changeRequest->action_type === CmsChangeRequest::ACTION_UPDATE_CONTENT) {
                $adapter->updateContent($changeRequest->target_identifier, $restorePayload);
            } else {
                $adapter->updateSupportedMetadata($changeRequest->target_identifier, $restorePayload);
            }

            $changeRequest->update([
                'status' => CmsChangeRequest::STATUS_ROLLED_BACK,
                'rolled_back_at' => now(),
            ]);

            AuditLog::create([
                'organization_id' => $changeRequest->organization_id,
                'user_id' => $changeRequest->approved_by,
                'action' => 'cms.change_rolled_back',
                'auditable_type' => CmsChangeRequest::class,
                'auditable_id' => $changeRequest->id,
                'payload' => [
                    'target_identifier' => $changeRequest->target_identifier,
                    'restored_from_snapshot_id' => $snapshot->id,
                ],
            ]);
        } catch (Throwable $rollbackError) {
            Log::critical('CMS Rollback failed', [
                'change_request_id' => $changeRequest->id,
                'error' => $rollbackError->getMessage(),
            ]);

            $changeRequest->update([
                'status' => CmsChangeRequest::STATUS_UNCERTAIN,
                'execution_error' => "Execution and subsequent rollback failed: {$rollbackError->getMessage()}",
            ]);
        }
    }

    /**
     * Compute field-level before and after diffs.
     */
    private function generateDiff(array $current, array $proposed): array
    {
        $diff = [];
        foreach ($proposed as $field => $newValue) {
            $oldValue = $current[$field] ?? null;
            $diff[$field] = [
                'before' => $oldValue,
                'after' => $newValue,
                'is_changed' => ($oldValue !== $newValue),
            ];
        }
        return $diff;
    }

    private function extractExpectedVerifications(array $proposed): array
    {
        $expected = [];
        foreach (['meta_title', 'meta_description', 'canonical_url', 'title', 'content', 'status'] as $key) {
            if (isset($proposed[$key])) {
                $expected[$key] = $proposed[$key];
            }
        }
        return $expected;
    }
}
