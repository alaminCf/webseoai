<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    use ApiResponseTrait;

    /**
     * List all organizations the user belongs to.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $memberships = OrganizationMember::with('organization')
            ->where('user_id', $user->id)
            ->get();

        $data = $memberships->map(function ($m) {
            return [
                'id' => $m->organization->id,
                'name' => $m->organization->name,
                'slug' => $m->organization->slug,
                'billing_plan' => $m->organization->billing_plan,
                'role' => $m->role,
                'websites_count' => $m->organization->websites()->where('is_archived', false)->count(),
                'members_count' => $m->organization->members()->count(),
                'created_at' => $m->organization->created_at->toIso8601String(),
            ];
        });

        return $this->success($data);
    }

    /**
     * Create a new organization.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($validated, $user, $request) {
            $slug = Str::slug($validated['name']) . '-' . Str::random(5);

            $organization = Organization::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'billing_plan' => 'agency_internal',
            ]);

            OrganizationMember::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => OrganizationMember::ROLE_OWNER,
            ]);

            AuditLog::record(
                $organization->id,
                $user->id,
                'organization.created',
                $organization,
                null,
                ['name' => $organization->name],
                $request
            );

            return $this->success([
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'billing_plan' => $organization->billing_plan,
                'role' => OrganizationMember::ROLE_OWNER,
            ], null, 201);
        });
    }

    /**
     * Get organization details.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        return $this->success([
            'id' => $org->id,
            'name' => $org->name,
            'slug' => $org->slug,
            'billing_plan' => $org->billing_plan,
            'current_user_role' => $membership->role,
            'websites_count' => $org->websites()->where('is_archived', false)->count(),
            'members_count' => $org->members()->count(),
            'created_at' => $org->created_at->toIso8601String(),
        ]);
    }

    /**
     * List members of the active organization.
     */
    public function members(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $members = OrganizationMember::with('user')
            ->where('organization_id', $org->id)
            ->get()
            ->map(function ($m) {
                return [
                    'id' => $m->id,
                    'user_id' => $m->user->id,
                    'name' => $m->user->name,
                    'email' => $m->user->email,
                    'role' => $m->role,
                    'joined_at' => $m->created_at->toIso8601String(),
                ];
            });

        return $this->success($members);
    }

    /**
     * Add or invite a user to the organization.
     * Requires minimum 'admin' role.
     */
    public function addMember(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $currentMembership */
        $currentMembership = $request->attributes->get('membership');

        if (!$currentMembership->isAdmin()) {
            return $this->error('Only owners or admins can invite organization members.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'string', Rule::in(OrganizationMember::ROLES)],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        // Cannot assign 'owner' role via addMember
        if ($validated['role'] === OrganizationMember::ROLE_OWNER && !$currentMembership->isOwner()) {
            return $this->error('Only the owner can assign another owner.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        return DB::transaction(function () use ($validated, $org, $request) {
            // Find or create user for the email
            $targetUser = User::where('email', $validated['email'])->first();

            if (!$targetUser) {
                // Internal invite: create pending user with temporary password
                $targetUser = User::create([
                    'name' => $validated['name'] ?? explode('@', $validated['email'])[0],
                    'email' => $validated['email'],
                    'password' => bcrypt(Str::random(24)),
                ]);
            }

            // Check if already a member
            $existing = OrganizationMember::where('organization_id', $org->id)
                ->where('user_id', $targetUser->id)
                ->first();

            if ($existing) {
                return $this->error('User is already a member of this organization.', 'MEMBER_EXISTS', null, 422);
            }

            $membership = OrganizationMember::create([
                'organization_id' => $org->id,
                'user_id' => $targetUser->id,
                'role' => $validated['role'],
            ]);

            AuditLog::record(
                $org->id,
                $request->user()->id,
                'member.added',
                $membership,
                null,
                ['email' => $targetUser->email, 'role' => $validated['role']],
                $request
            );

            return $this->success([
                'id' => $membership->id,
                'user_id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'role' => $membership->role,
                'joined_at' => $membership->created_at->toIso8601String(),
            ], null, 201);
        });
    }

    /**
     * Update an organization member's role.
     */
    public function updateMemberRole(Request $request, int $memberId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $currentMembership */
        $currentMembership = $request->attributes->get('membership');

        if (!$currentMembership->isAdmin()) {
            return $this->error('Only owners or admins can update member roles.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $targetMember = OrganizationMember::where('organization_id', $org->id)
            ->where('id', $memberId)
            ->first();

        if (!$targetMember) {
            return $this->error('Member not found in this organization.', 'MEMBER_NOT_FOUND', null, 404);
        }

        // Cannot change the owner's role unless current user is the owner
        if ($targetMember->isOwner() && !$currentMembership->isOwner()) {
            return $this->error('Cannot change the owner’s role.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(OrganizationMember::ROLES)],
        ]);

        $oldRole = $targetMember->role;
        $targetMember->update(['role' => $validated['role']]);

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'member.role_updated',
            $targetMember,
            ['role' => $oldRole],
            ['role' => $validated['role']],
            $request
        );

        return $this->success([
            'id' => $targetMember->id,
            'role' => $targetMember->role,
            'message' => 'Member role updated successfully.',
        ]);
    }

    /**
     * Remove a member from the organization.
     */
    public function removeMember(Request $request, int $memberId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $currentMembership */
        $currentMembership = $request->attributes->get('membership');

        if (!$currentMembership->isAdmin()) {
            return $this->error('Only owners or admins can remove organization members.', 'INSUFFICIENT_PERMISSIONS', null, 403);
        }

        $targetMember = OrganizationMember::where('organization_id', $org->id)
            ->where('id', $memberId)
            ->first();

        if (!$targetMember) {
            return $this->error('Member not found in this organization.', 'MEMBER_NOT_FOUND', null, 404);
        }

        // Prevent removing the sole owner
        if ($targetMember->isOwner()) {
            $ownerCount = OrganizationMember::where('organization_id', $org->id)
                ->where('role', OrganizationMember::ROLE_OWNER)
                ->count();

            if ($ownerCount <= 1) {
                return $this->error('Cannot remove the sole owner of an organization.', 'SOLE_OWNER_REMOVAL', null, 422);
            }
        }

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'member.removed',
            $targetMember,
            ['user_id' => $targetMember->user_id, 'role' => $targetMember->role],
            null,
            $request
        );

        $targetMember->delete();

        return $this->success(['message' => 'Member removed successfully.']);
    }
}
