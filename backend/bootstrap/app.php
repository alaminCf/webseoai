<?php

use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\RequireOrganizationRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust reverse proxy headers (Vercel, Cloudflare, Load Balancers) so HTTPS URLs generate accurately
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'org.context' => EnsureOrganizationContext::class,
            'org.role' => RequireOrganizationRole::class,
            'super.admin' => EnsureSuperAdmin::class,
        ]);

        // State-less API rate limiting & JSON accepts
        $middleware->api(prepend: [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Enforce JSON envelopes for API errors
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'message' => 'Your session expired or the server restarted. Please sign in again.',
                    ],
                    'timestamp' => now()->toIso8601String(),
                ], 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                $errors = $e->errors();
                $firstMessage = collect($errors)->flatten()->first() ?: 'The given data was invalid.';

                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_FAILED',
                        'message' => $firstMessage,
                        'details' => $errors,
                    ],
                    'timestamp' => now()->toIso8601String(),
                ], 422);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RESOURCE_NOT_FOUND',
                        'message' => $e->getMessage() ?: 'The requested resource was not found.',
                    ],
                    'timestamp' => now()->toIso8601String(),
                ], 404);
            }
        });
    })->create();
