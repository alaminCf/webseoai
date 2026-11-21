<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Clients\ClientWorkspaceService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClientApprovalRequest;
use App\Models\ClientWorkspace;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Website;
use App\Models\WebsitePermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientManagementController extends Controller
{
    use ApiResponseTrait;

    protected ClientWorkspaceService $clientService;

    public function __construct(ClientWorkspaceService $clientService)
    {
        $this->clientService = $clientService;
    }

    /**
     * List all client workspaces in the active organization.
     */
    public function indexWorkspaces(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $workspaces = ClientWorkspace::with(['websites:id,client_workspace_id,name,domain,platform,health_score'])
            ->where('organization_id', $org->id)
            ->latest()
            ->get();

        return $this->success($workspaces);
    }

    /**
     * Create a new client workspace.
     */
    public function storeWorkspace(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->isAdmin()) {
            return $this->error('Only owners or admins can create client workspaces.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $workspace = $this->clientService->createWorkspace($org, $validated);

        return $this->success($workspace, null, 201);
    }

    /**
     * Assign websites to a client workspace.
     */
    public function assignWebsites(Request $request, ClientWorkspace $workspace): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        if ($workspace->organization_id !== $org->id) {
            return $this->error('Workspace does not belong to this organization.', 'NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'website_ids' => ['required', 'array'],
            'website_ids.*' => ['integer'],
        ]);

        $this->clientService->assignWebsites($workspace, $validated['website_ids']);

        return $this->success([
            'message' => 'Websites assigned to client workspace successfully.',
            'workspace' => $workspace->fresh()->load('websites:id,client_workspace_id,name,domain'),
        ]);
    }

    /**
     * List client approval requests.
     */
    public function listApprovalRequests(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $query = ClientApprovalRequest::with(['website:id,name,domain', 'requester:id,name,email'])
            ->where('organization_id', $org->id);

        if ($request->has('website_id')) {
            $query->where('website_id', $request->query('website_id'));
        }

        $requests = $query->latest()->get();

        return $this->success($requests);
    }

    /**
     * Create an external approval request for a client sign-off.
     */
    public function createApprovalRequest(Request $request, Website $website): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        if ($website->organization_id !== $org->id) {
            return $this->error('Website does not belong to this organization.', 'NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'approver_email' => ['required', 'email'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'request_type' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $approvalReq = $this->clientService->createApprovalRequest($website, $request->user(), $validated);

        return $this->success($approvalReq, null, 201);
    }

    /**
     * Public client view of an approval request by secure token.
     */
    public function publicShowApproval(string $token): JsonResponse
    {
        $approvalReq = ClientApprovalRequest::with(['website:id,name,domain,platform', 'organization:id,name,settings'])
            ->where('token', $token)
            ->first();

        if (!$approvalReq) {
            return $this->error('Approval request not found or invalid link.', 'NOT_FOUND', null, 404);
        }

        $whiteLabel = $approvalReq->organization->getWhiteLabelSettings();

        return $this->success([
            'id' => $approvalReq->id,
            'title' => $approvalReq->title,
            'description' => $approvalReq->description,
            'request_type' => $approvalReq->request_type,
            'status' => $approvalReq->status,
            'is_pending' => $approvalReq->isPending(),
            'approver_email' => $approvalReq->approver_email,
            'metadata' => $approvalReq->metadata,
            'expires_at' => $approvalReq->expires_at?->toIso8601String(),
            'website' => [
                'name' => $approvalReq->website->name,
                'domain' => $approvalReq->website->domain,
                'platform' => $approvalReq->website->platform,
            ],
            'agency_branding' => [
                'brand_name' => $whiteLabel['brand_name'] ?? $approvalReq->organization->name,
                'logo_url' => $whiteLabel['logo_url'] ?? null,
                'primary_color' => $whiteLabel['primary_color'] ?? '#10b981',
                'contact_email' => $whiteLabel['contact_email'] ?? null,
            ],
        ]);
    }

    /**
     * Public client decision (approve or reject) by secure token.
     */
    public function publicRespondApproval(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'approved' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->clientService->resolveApprovalRequest(
                $token,
                $validated['approved'],
                $validated['comment'] ?? null
            );

            return $this->success([
                'status' => $updated->status,
                'decided_at' => $updated->decided_at?->toIso8601String(),
                'message' => $validated['approved'] ? 'Approval recorded successfully.' : 'Rejection recorded.',
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'INVALID_REQUEST', null, 422);
        }
    }

    /**
     * Get website-level permissions for a website.
     */
    public function getWebsitePermissions(Request $request, Website $website): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        if ($website->organization_id !== $org->id) {
            return $this->error('Website does not belong to this organization.', 'NOT_FOUND', null, 404);
        }

        $perms = WebsitePermission::with('user:id,name,email')
            ->where('website_id', $website->id)
            ->get();

        return $this->success($perms);
    }

    /**
     * Update website-level granular permissions for an organization user.
     */
    public function updateWebsitePermissions(Request $request, Website $website): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->isAdmin()) {
            return $this->error('Only owners or admins can manage website permissions.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        if ($website->organization_id !== $org->id) {
            return $this->error('Website does not belong to this organization.', 'NOT_FOUND', null, 404);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'can_view' => ['required', 'boolean'],
            'can_edit' => ['required', 'boolean'],
            'can_publish' => ['required', 'boolean'],
            'can_approve' => ['required', 'boolean'],
        ]);

        // Ensure user is member of organization
        $isMember = OrganizationMember::where('organization_id', $org->id)
            ->where('user_id', $validated['user_id'])
            ->exists();

        if (!$isMember) {
            return $this->error('User is not a member of this organization.', 'USER_NOT_IN_ORG', null, 422);
        }

        $permission = WebsitePermission::updateOrCreate(
            [
                'website_id' => $website->id,
                'user_id' => $validated['user_id'],
            ],
            [
                'can_view' => $validated['can_view'],
                'can_edit' => $validated['can_edit'],
                'can_publish' => $validated['can_publish'],
                'can_approve' => $validated['can_approve'],
            ]
        );

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'website_permissions.updated',
            $permission,
            null,
            $validated
        );

        return $this->success($permission->load('user:id,name,email'));
    }
}
