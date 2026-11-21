<?php

use App\Http\Controllers\Api\V1\AiRecommendationController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContentStudioController;
use App\Http\Controllers\Api\V1\ContextProfileController;
use App\Http\Controllers\Api\V1\CrawlController;
use App\Http\Controllers\Api\V1\KeywordResearchController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\SearchConsoleController;
use App\Http\Controllers\Api\V1\SearchOpportunityController;
use App\Http\Controllers\Api\V1\WebsiteController;
use App\Http\Controllers\Api\V1\CmsExecutionController;
use App\Http\Controllers\Api\V1\WorkflowAutomationController;
use App\Http\Controllers\Api\V1\WebsiteIntegrationController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\ClientManagementController;
use App\Http\Controllers\Api\V1\WhiteLabelReportController;
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
            'database' => config('database.default'),
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

    // Public OAuth Callbacks
    Route::get('/integrations/google-search-console/callback', [SearchConsoleController::class, 'callback']);
    Route::get('/integrations/google-search-console/sandbox-callback', [SearchConsoleController::class, 'callback']);

    // Phase 7: Public Billing Webhook
    Route::post('/billing/webhook', [BillingController::class, 'webhook']);

    // Phase 7: Public Client Approval Sign-off Links
    Route::get('/client-approvals/{token}', [ClientManagementController::class, 'publicShowApproval']);
    Route::post('/client-approvals/{token}/respond', [ClientManagementController::class, 'publicRespondApproval']);

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

            // Phase 2: Crawler Engine Endpoints
            Route::post('/websites/{id}/crawl', [CrawlController::class, 'startCrawl'])
                ->middleware('org.role:seo_manager');
            Route::get('/websites/{id}/crawl/status', [CrawlController::class, 'getCrawlStatus']);
            Route::post('/crawl-jobs/{id}/cancel', [CrawlController::class, 'cancelCrawl'])
                ->middleware('org.role:seo_manager');
            Route::get('/crawl-jobs/{id}/pages', [CrawlController::class, 'listPages']);
            Route::get('/crawl-jobs/{id}/pages/{pageId}', [CrawlController::class, 'getPageDetails']);

            // Phase 2: Technical SEO Audit Endpoints
            Route::get('/websites/{id}/audits', [AuditController::class, 'listAudits']);
            Route::get('/websites/{id}/audits/latest', [AuditController::class, 'getLatestAudit']);
            Route::get('/websites/{id}/audits/compare', [AuditController::class, 'compareAudits']);
            Route::get('/audits/{id}/issues', [AuditController::class, 'listIssues']);
            Route::get('/audits/{id}/export', [AuditController::class, 'exportAudit']);

            // Phase 3: Website Context Profile
            Route::get('/websites/{id}/context-profile', [ContextProfileController::class, 'getProfile']);
            Route::put('/websites/{id}/context-profile', [ContextProfileController::class, 'updateProfile']);
            Route::post('/websites/{id}/context-profile/approve', [ContextProfileController::class, 'approveProfile']);

            // Phase 3: AI Recommendations (ICE prioritized)
            Route::get('/websites/{id}/recommendations', [AiRecommendationController::class, 'listRecommendations']);
            Route::post('/websites/{id}/recommendations/generate', [AiRecommendationController::class, 'generateRecommendations']);
            Route::patch('/recommendations/{id}/status', [AiRecommendationController::class, 'updateStatus']);

            // Phase 3: Content Studio
            Route::get('/websites/{id}/content/briefs', [ContentStudioController::class, 'listBriefs']);
            Route::post('/websites/{id}/content/briefs', [ContentStudioController::class, 'createBrief']);
            Route::get('/content/briefs/{id}', [ContentStudioController::class, 'getBrief']);
            Route::get('/websites/{id}/content/articles', [ContentStudioController::class, 'listArticles']);
            Route::post('/websites/{id}/content/articles', [ContentStudioController::class, 'generateArticle']);
            Route::get('/content/articles/{id}', [ContentStudioController::class, 'getArticle']);
            Route::put('/content/articles/{id}', [ContentStudioController::class, 'updateArticle']);
            Route::post('/content/articles/{id}/status', [ContentStudioController::class, 'updateArticleStatus']);
            Route::post('/websites/{id}/content/generate-titles-meta', [ContentStudioController::class, 'generateTitlesAndMeta']);
            Route::post('/websites/{id}/content/generate-faq', [ContentStudioController::class, 'generateFaq']);
            Route::post('/websites/{id}/content/gaps', [ContentStudioController::class, 'getGapAnalysis']);
            Route::get('/websites/{id}/ai/usage', [ContentStudioController::class, 'getUsageStats']);

            // Phase 4: Google Search Console
            Route::get('/websites/{website}/search-console/connect', [SearchConsoleController::class, 'connect']);
            Route::post('/websites/{website}/search-console/disconnect', [SearchConsoleController::class, 'disconnect'])
                ->middleware('org.role:seo_manager');
            Route::get('/websites/{website}/search-console/status', [SearchConsoleController::class, 'status']);
            Route::post('/websites/{website}/search-console/property', [SearchConsoleController::class, 'selectProperty'])
                ->middleware('org.role:seo_manager');
            Route::post('/websites/{website}/search-console/sync', [SearchConsoleController::class, 'sync'])
                ->middleware('org.role:seo_manager');
            Route::get('/websites/{website}/search-console/performance', [SearchConsoleController::class, 'performance']);
            Route::post('/websites/{website}/search-console/inspect', [SearchConsoleController::class, 'inspectUrl']);

            // Phase 4: Search Opportunities
            Route::get('/websites/{website}/opportunities', [SearchOpportunityController::class, 'index']);
            Route::post('/websites/{website}/opportunities/detect', [SearchOpportunityController::class, 'detect'])
                ->middleware('org.role:seo_manager');
            Route::patch('/websites/{website}/opportunities/{opportunity}/status', [SearchOpportunityController::class, 'updateStatus']);

            // Phase 4: Keyword Research Provider
            Route::get('/keywords/search', [KeywordResearchController::class, 'search']);
            Route::get('/keywords/clusters', [KeywordResearchController::class, 'clusters']);
            Route::get('/keywords/history', [KeywordResearchController::class, 'history']);

            // Phase 5: CMS Integrations and Safe SEO Execution
            Route::get('/websites/{website}/cms/capabilities', [CmsExecutionController::class, 'capabilities']);
            Route::get('/websites/{website}/cms/change-requests', [CmsExecutionController::class, 'index']);
            Route::post('/websites/{website}/cms/change-requests/stage', [CmsExecutionController::class, 'stage']);
            Route::post('/websites/{website}/cms/change-requests/{changeRequest}/approve', [CmsExecutionController::class, 'approve'])
                ->middleware('org.role:seo_manager');
            Route::post('/websites/{website}/cms/change-requests/{changeRequest}/reject', [CmsExecutionController::class, 'reject'])
                ->middleware('org.role:seo_manager');
            Route::post('/websites/{website}/cms/change-requests/{changeRequest}/execute', [CmsExecutionController::class, 'execute'])
                ->middleware('org.role:seo_manager');
            Route::post('/websites/{website}/cms/change-requests/{changeRequest}/rollback', [CmsExecutionController::class, 'rollback'])
                ->middleware('org.role:seo_manager');
            Route::get('/websites/{website}/cms/snapshots', [CmsExecutionController::class, 'snapshots']);

            // Phase 6: SEO Autopilot & Workflow Automation
            Route::get('/websites/{website}/automation/rules', [WorkflowAutomationController::class, 'indexRules']);
            Route::post('/websites/{website}/automation/rules', [WorkflowAutomationController::class, 'storeRule'])
                ->middleware('org.role:seo_manager');
            Route::patch('/websites/{website}/automation/rules/{rule}/status', [WorkflowAutomationController::class, 'updateRuleStatus'])
                ->middleware('org.role:seo_manager');
            Route::post('/websites/{website}/automation/rules/{rule}/trigger', [WorkflowAutomationController::class, 'triggerRule'])
                ->middleware('org.role:seo_manager');
            Route::get('/websites/{website}/automation/runs', [WorkflowAutomationController::class, 'indexRuns']);
            Route::get('/websites/{website}/automation/runs/{run}', [WorkflowAutomationController::class, 'showRun']);
            Route::post('/websites/{website}/automation/runs/{run}/cancel', [WorkflowAutomationController::class, 'cancelRun'])
                ->middleware('org.role:seo_manager');
            Route::get('/websites/{website}/automation/approvals', [WorkflowAutomationController::class, 'indexApprovals']);
            Route::post('/websites/{website}/automation/approvals/{approval}/approve', [WorkflowAutomationController::class, 'approveAction'])
                ->middleware('org.role:seo_manager');
            Route::post('/websites/{website}/automation/approvals/{approval}/reject', [WorkflowAutomationController::class, 'rejectAction'])
                ->middleware('org.role:seo_manager');

            // Phase 7: Subscriptions & Billing
            Route::get('/billing/plans', [BillingController::class, 'indexPlans']);
            Route::get('/billing/usage', [BillingController::class, 'getUsage']);
            Route::post('/billing/checkout-session', [BillingController::class, 'createCheckoutSession'])
                ->middleware('org.role:admin');
            Route::post('/billing/portal-session', [BillingController::class, 'createPortalSession'])
                ->middleware('org.role:admin');
            Route::post('/billing/cancel', [BillingController::class, 'cancelSubscription'])
                ->middleware('org.role:owner');
            Route::post('/billing/resume', [BillingController::class, 'resumeSubscription'])
                ->middleware('org.role:admin');
            Route::post('/billing/admin-override', [BillingController::class, 'adminOverride'])
                ->middleware('org.role:owner');

            // Phase 7: Client Workspaces & Permissions
            Route::get('/clients/workspaces', [ClientManagementController::class, 'indexWorkspaces']);
            Route::post('/clients/workspaces', [ClientManagementController::class, 'storeWorkspace'])
                ->middleware('org.role:admin');
            Route::post('/clients/workspaces/{workspace}/websites', [ClientManagementController::class, 'assignWebsites'])
                ->middleware('org.role:admin');
            Route::get('/clients/approvals', [ClientManagementController::class, 'listApprovalRequests']);
            Route::post('/websites/{website}/client-approvals', [ClientManagementController::class, 'createApprovalRequest'])
                ->middleware('org.role:seo_manager');
            Route::get('/websites/{website}/permissions', [ClientManagementController::class, 'getWebsitePermissions']);
            Route::post('/websites/{website}/permissions', [ClientManagementController::class, 'updateWebsitePermissions'])
                ->middleware('org.role:admin');

            // Phase 7: White-Label Reports & Agency Branding
            Route::get('/white-label/branding', [WhiteLabelReportController::class, 'getBranding']);
            Route::put('/white-label/branding', [WhiteLabelReportController::class, 'updateBranding'])
                ->middleware('org.role:admin');
            Route::get('/websites/{website}/reports/white-label', [WhiteLabelReportController::class, 'getReport']);

            // Phase 7: Organization & Account Deletion Workflow
            Route::post('/organizations/current/delete', [OrganizationController::class, 'destroyOrganization'])
                ->middleware('org.role:owner');

            // Audit Trail
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
        });
    });
});
