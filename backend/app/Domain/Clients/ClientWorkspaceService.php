<?php

namespace App\Domain\Clients;

use App\Models\AuditLog;
use App\Models\ClientApprovalRequest;
use App\Models\ClientWorkspace;
use App\Models\Organization;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ClientWorkspaceService
{
    /**
     * Create a new Client Workspace for the agency.
     */
    public function createWorkspace(Organization $org, array $data): ClientWorkspace
    {
        $workspace = ClientWorkspace::create([
            'organization_id' => $org->id,
            'name' => $data['name'],
            'company_name' => $data['company_name'] ?? null,
            'client_email' => $data['client_email'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'notes' => $data['notes'] ?? null,
            'settings' => $data['settings'] ?? [],
        ]);

        return $workspace;
    }

    /**
     * Assign websites to a client workspace.
     */
    public function assignWebsites(ClientWorkspace $workspace, array $websiteIds): void
    {
        Website::where('organization_id', $workspace->organization_id)
            ->whereIn('id', $websiteIds)
            ->update(['client_workspace_id' => $workspace->id]);
    }

    /**
     * Create an external client approval request.
     */
    public function createApprovalRequest(
        Website $website,
        User $requester,
        array $data
    ): ClientApprovalRequest {
        $token = Str::random(48);

        $request = ClientApprovalRequest::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'client_workspace_id' => $website->client_workspace_id,
            'requester_id' => $requester->id,
            'approver_email' => $data['approver_email'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'request_type' => $data['request_type'] ?? 'cms_change',
            'status' => ClientApprovalRequest::STATUS_PENDING,
            'token' => $token,
            'metadata' => $data['metadata'] ?? [],
            'expires_at' => now()->addDays(7),
        ]);

        AuditLog::record(
            $website->organization_id,
            $requester->id,
            'client_approval.created',
            $request,
            null,
            ['approver_email' => $data['approver_email'], 'request_type' => $request->request_type]
        );

        return $request;
    }

    /**
     * Client resolves an approval request.
     */
    public function resolveApprovalRequest(string $token, bool $approved, ?string $comment = null): ClientApprovalRequest
    {
        $request = ClientApprovalRequest::where('token', $token)->first();
        if (!$request) {
            throw new InvalidArgumentException("Approval request not found.");
        }

        if (!$request->isPending()) {
            throw new InvalidArgumentException("This approval request has already been resolved or expired.");
        }

        $request->update([
            'status' => $approved ? ClientApprovalRequest::STATUS_APPROVED : ClientApprovalRequest::STATUS_REJECTED,
            'client_comment' => $comment,
            'decided_at' => now(),
        ]);

        AuditLog::record(
            $request->organization_id,
            null,
            $approved ? 'client_approval.approved' : 'client_approval.rejected',
            $request,
            null,
            ['approver_email' => $request->approver_email, 'comment' => $comment]
        );

        return $request;
    }
}
