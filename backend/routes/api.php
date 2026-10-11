<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\WebsiteController;
use App\Http\Controllers\Api\V1\WebsiteIntegrationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Web SEO AI (Version 1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // System Health Check
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'status' => 'healthy',
            'platform' => 'Web SEO AI',
            'version' => '1.0.0',
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    // Public / Rate-limited Auth Endpoints
    Route::prefix('auth')->middleware('throttle:10,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    });

    // Authenticated Endpoints
    Route::middleware('auth:sanctum')->group(function () {
        // User Profile & Session
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Organizations (Global user list & creation)
        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::post('/organizations', [OrganizationController::class, 'store']);

        // Organization-Scoped Domain Endpoints
        Route::middleware('org.context')->group(function () {
            // Organization Details & Membership
            Route::get('/organizations/current', [OrganizationController::class, 'show']);
            Route::get('/organizations/current/members', [OrganizationController::class, 'members']);
            Route::post('/organizations/current/members', [OrganizationController::class, 'addMember'])
                ->middleware('org.role:admin');
            Route::patch('/organizations/current/members/{member}', [OrganizationController::class, 'updateMemberRole'])
                ->middleware('org.role:admin');
            Route::delete('/organizations/current/members/{member}', [OrganizationController::class, 'removeMember'])
                ->middleware('org.role:admin');

            // Website Management
            Route::get('/websites', [WebsiteController::class, 'index']);
            Route::post('/websites', [WebsiteController::class, 'store'])
                ->middleware('org.role:editor');
            Route::get('/websites/{id}', [WebsiteController::class, 'show']);
            Route::patch('/websites/{id}', [WebsiteController::class, 'update'])
                ->middleware('org.role:editor');
            Route::post('/websites/{id}/verify', [WebsiteController::class, 'verifyOwnership'])
                ->middleware('org.role:editor');
            Route::post('/websites/{id}/archive', [WebsiteController::class, 'archive'])
                ->middleware('org.role:admin');
            Route::post('/websites/{id}/restore', [WebsiteController::class, 'restore'])
                ->middleware('org.role:admin');
            Route::delete('/websites/{id}', [WebsiteController::class, 'destroy'])
                ->middleware('org.role:admin');

            // Website Integrations
            Route::get('/websites/{id}/integrations', [WebsiteIntegrationController::class, 'index']);
            Route::post('/websites/{id}/integrations', [WebsiteIntegrationController::class, 'store'])
                ->middleware('org.role:seo_manager');
            Route::post('/websites/{id}/integrations/{provider}/test', [WebsiteIntegrationController::class, 'testConnection'])
                ->middleware('org.role:seo_manager');
            Route::delete('/websites/{id}/integrations/{provider}', [WebsiteIntegrationController::class, 'disconnect'])
                ->middleware('org.role:seo_manager');

            // Audit Trail
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
        });
    });
});
