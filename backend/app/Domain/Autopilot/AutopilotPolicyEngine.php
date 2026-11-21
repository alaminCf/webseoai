<?php

namespace App\Domain\Autopilot;

use App\Domain\Autopilot\Exceptions\AutomationPolicyViolationException;
use App\Domain\Integrations\CmsAdapterManager;
use App\Models\AutomationRule;
use App\Models\Organization;
use App\Models\Website;
use App\Models\WorkflowRun;

class AutopilotPolicyEngine
{
    private CmsAdapterManager $adapterManager;

    public function __construct(CmsAdapterManager $adapterManager)
    {
        $this->adapterManager = $adapterManager;
    }

    /**
     * Evaluate rule before triggering a workflow run.
     * Evaluates:
     * - Rule status
     * - Website verification & active status
     * - Write capabilities and platform driver
     * - Daily execution limits
     * - Monthly budget limit
     */
    public function evaluateRule(AutomationRule $rule, Website $website): void
    {
        // 1. Rule status check
        if ($rule->status !== AutomationRule::STATUS_ACTIVE) {
            throw new AutomationPolicyViolationException(
                $rule->name,
                "Rule is currently in '{$rule->status}' state and cannot execute."
            );
        }

        // 2. Website status check
        if ($website->is_archived) {
            throw new AutomationPolicyViolationException(
                $rule->name,
                "Website [{$website->domain}] is archived."
            );
        }

        if (!$website->isVerified()) {
            throw new AutomationPolicyViolationException(
                $rule->name,
                "Website [{$website->domain}] ownership is unverified. Autopilot execution is blocked."
            );
        }

        // 3. Write policy & CMS adapter capabilities
        if ($rule->is_write_capable) {
            $adapter = $this->adapterManager->resolveAdapter($website);
            $caps = $adapter->getCapabilities();

            if (!empty($caps['read_only']) || (empty($caps['metadata_updating']) && empty($caps['content_updating']) && !in_array('update_metadata', $caps) && !in_array('update_content', $caps))) {
                throw new AutomationPolicyViolationException(
                    $rule->name,
                    "Platform driver for [{$website->platform}] is strictly read-only. Write-capable automation rules cannot execute on this website."
                );
            }
        }

        // 4. Daily execution frequency limit
        $runsToday = WorkflowRun::where('automation_rule_id', $rule->id)
            ->whereDate('created_at', today())
            ->count();

        if ($runsToday >= $rule->max_runs_per_day) {
            throw new AutomationPolicyViolationException(
                $rule->name,
                "Daily execution limit reached ({$runsToday}/{$rule->max_runs_per_day} runs today)."
            );
        }

        // 5. Monthly cost budget limit
        if ($rule->current_month_cost >= $rule->monthly_cost_budget) {
            throw new AutomationPolicyViolationException(
                $rule->name,
                "Monthly budget limit exhausted (\${$rule->current_month_cost} / \${$rule->monthly_cost_budget})."
            );
        }
    }

    /**
     * Determine if an action within a workflow run requires human approval.
     * Hard rule: AI may recommend an action, but cannot grant itself permission to execute external writes.
     */
    public function requiresHumanApproval(AutomationRule $rule, string $actionType, array $payload = []): bool
    {
        // Dry-run mode never writes live, so no approval gate needed
        if ($rule->dry_run_mode) {
            return false;
        }

        // Any write-capable rule with require_human_approval=true MUST be placed in approval queue
        if ($rule->is_write_capable && $rule->require_human_approval) {
            return true;
        }

        // High-risk action detection
        if (in_array($actionType, [
            AutomationRule::ACTION_PUBLISH_APPROVED_CONTENT,
            AutomationRule::ACTION_AUTO_STAGE_METADATA_FIX,
        ])) {
            return $rule->require_human_approval;
        }

        return false;
    }
}
