<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\BillingManager;
use App\Domain\Billing\QuotaEnforcementService;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    use ApiResponseTrait;

    protected BillingManager $billingManager;
    protected QuotaEnforcementService $quotaService;

    public function __construct(BillingManager $billingManager, QuotaEnforcementService $quotaService)
    {
        $this->billingManager = $billingManager;
        $this->quotaService = $quotaService;
    }

    /**
     * List available SaaS subscription plans.
     */
    public function indexPlans(Request $request): JsonResponse
    {
        $plans = config('saas.plans', []);
        /** @var Organization|null $org */
        $org = $request->attributes->get('organization');

        return $this->success([
            'plans' => array_values($plans),
            'current_plan' => $org ? $org->getEffectivePlan() : null,
        ]);
    }

    /**
     * Get real-time usage and quota metrics for current organization.
     */
    public function getUsage(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        $usage = $this->quotaService->getUsageSummary($org);

        return $this->success($usage);
    }

    /**
     * Create Checkout Session for upgrade or subscription creation.
     */
    public function createCheckoutSession(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->isAdmin()) {
            return $this->error('Only owners or admins can manage subscription billing.', 'UNAUTHORIZED_BILLING', null, 403);
        }

        $validated = $request->validate([
            'plan_id' => ['required', 'string', Rule::in(array_keys(config('saas.plans', [])))],
            'billing_cycle' => ['nullable', 'string', Rule::in(['monthly', 'yearly'])],
            'success_url' => ['nullable', 'url'],
            'cancel_url' => ['nullable', 'url'],
        ]);

        $session = $this->billingManager->createCheckoutSession(
            $org,
            $validated['plan_id'],
            $validated['billing_cycle'] ?? 'monthly',
            $validated['success_url'] ?? null,
            $validated['cancel_url'] ?? null
        );

        return $this->success($session);
    }

    /**
     * Create Stripe Customer Billing Portal Session.
     */
    public function createPortalSession(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->isAdmin()) {
            return $this->error('Only owners or admins can access the billing portal.', 'UNAUTHORIZED_BILLING', null, 403);
        }

        $validated = $request->validate([
            'return_url' => ['nullable', 'url'],
        ]);

        $session = $this->billingManager->createCustomerPortalSession($org, $validated['return_url'] ?? null);

        return $this->success($session);
    }

    /**
     * Cancel subscription.
     */
    public function cancelSubscription(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->isOwner()) {
            return $this->error('Only organization owners can cancel subscriptions.', 'UNAUTHORIZED_BILLING', null, 403);
        }

        $validated = $request->validate([
            'immediate' => ['nullable', 'boolean'],
        ]);

        $sub = $this->billingManager->cancelSubscription($org, (bool) ($validated['immediate'] ?? false));

        return $this->success([
            'status' => $sub->status,
            'cancel_at_period_end' => $sub->cancel_at_period_end,
            'message' => $sub->cancel_at_period_end
                ? 'Subscription will be canceled at the end of the current billing cycle.'
                : 'Subscription canceled immediately.',
        ]);
    }

    /**
     * Resume a canceled subscription before end of period.
     */
    public function resumeSubscription(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->isAdmin()) {
            return $this->error('Only owners or admins can resume subscriptions.', 'UNAUTHORIZED_BILLING', null, 403);
        }

        $sub = $this->billingManager->resumeSubscription($org);

        return $this->success([
            'status' => $sub->status,
            'cancel_at_period_end' => $sub->cancel_at_period_end,
            'message' => 'Subscription resumed successfully.',
        ]);
    }

    /**
     * Public verified webhook receiver from Stripe.
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        $result = $this->billingManager->handleWebhook($payload, $signature);

        if (!$result['success']) {
            return response()->json($result, $result['status'] ?? 400);
        }

        return response()->json($result, 200);
    }

    /**
     * Administrative manual plan override (internal/owner testing).
     */
    public function adminOverride(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');
        /** @var OrganizationMember $membership */
        $membership = $request->attributes->get('membership');

        if (!$membership->isOwner()) {
            return $this->error('Only owners can execute administrative subscription overrides.', 'FORBIDDEN', null, 403);
        }

        $validated = $request->validate([
            'plan_id' => ['required', 'string', Rule::in(array_keys(config('saas.plans', [])))],
            'status' => ['nullable', 'string'],
        ]);

        $sub = $this->billingManager->adminSetPlan($org, $validated['plan_id'], $validated['status'] ?? 'active');

        return $this->success([
            'plan_id' => $sub->plan_id,
            'status' => $sub->status,
            'message' => "Plan successfully set to {$sub->plan_id}",
        ]);
    }
}
