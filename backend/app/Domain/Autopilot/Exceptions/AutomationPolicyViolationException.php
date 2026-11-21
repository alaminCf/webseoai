<?php

namespace App\Domain\Autopilot\Exceptions;

use Exception;

class AutomationPolicyViolationException extends Exception
{
    private string $ruleName;
    private string $violationReason;
    private array $details;

    public function __construct(string $ruleName, string $violationReason, array $details = [])
    {
        parent::__construct("Automation policy rejected execution of rule [{$ruleName}]: {$violationReason}");
        $this->ruleName = $ruleName;
        $this->violationReason = $violationReason;
        $this->details = $details;
    }

    public function getRuleName(): string
    {
        return $this->ruleName;
    }

    public function getViolationReason(): string
    {
        return $this->violationReason;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
