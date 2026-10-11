<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\OrganizationMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationContext
{
    /**
     * Handle an incoming request and ensure organization boundary isolation.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication is required.',
                ],
                'timestamp' => now()->toIso8601String(),
            ], 401);
        }

        // Determine requested organization ID from Header or query/route
        $requestedOrgId = $request->header('X-Organization-Id')
            ?? $request->input('organization_id')
            ?? $request->route('organization');

        if ($requestedOrgId instanceof Organization) {
            $requestedOrgId = $requestedOrgId->id;
        }

        $membership = null;

        if ($requestedOrgId) {
            $membership = OrganizationMember::where('organization_id', $requestedOrgId)
                ->where('user_id', $user->id)
                ->with('organization')
                ->first();

            if (!$membership) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'ORGANIZATION_ACCESS_DENIED',
                        'message' => 'You do not belong to the requested organization or access is revoked.',
                    ],
                    'timestamp' => now()->toIso8601String(),
                ], 403);
            }
        } else {
            // Default to user's first available organization if none specified
            $membership = OrganizationMember::where('user_id', $user->id)
                ->with('organization')
                ->first();

            if (!$membership) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NO_ORGANIZATION_FOUND',
                        'message' => 'User does not belong to any active organization.',
                    ],
                    'timestamp' => now()->toIso8601String(),
                ], 403);
            }
        }

        // Store into request attributes for controllers and policies
        $request->attributes->set('organization', $membership->organization);
        $request->attributes->set('membership', $membership);

        return $next($request);
    }
}
