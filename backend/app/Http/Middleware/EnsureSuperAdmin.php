<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required.',
                ],
            ], 401);
        }

        if ($user->isSuspended()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ACCOUNT_SUSPENDED',
                    'message' => 'Your account has been suspended by an administrator.',
                ],
            ], 403);
        }

        if (!$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SUPER_ADMIN_REQUIRED',
                    'message' => 'Super Administrator privileges are required to access this control panel.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
