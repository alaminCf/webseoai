<?php

namespace App\Http\Middleware;

use App\Models\OrganizationMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOrganizationRole
{
    /**
     * Handle an incoming request and check organization role authorization.
     */
    public function handle(Request $request, Closure $next, string $minimumRole): Response
    {
        /** @var OrganizationMember|null $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ORGANIZATION_CONTEXT_MISSING',
                    'message' => 'Organization context could not be determined.',
                ],
                'timestamp' => now()->toIso8601String(),
            ], 403);
        }

        if (!$membership->hasMinimumRole($minimumRole)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => sprintf(
                        'Your role (%s) does not have sufficient permissions to perform this action. Minimum required: %s.',
                        $membership->role,
                        $minimumRole
                    ),
                ],
                'timestamp' => now()->toIso8601String(),
            ], 403);
        }

        return $next($request);
    }
}
