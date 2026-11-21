<?php

namespace App\Domain\Billing\Exceptions;

use Exception;

class QuotaExceededException extends Exception
{
    protected string $quotaType;
    protected int|float $currentUsage;
    protected int|float $limit;

    public function __construct(string $message, string $quotaType, int|float $currentUsage, int|float $limit)
    {
        parent::__construct($message);
        $this->quotaType = $quotaType;
        $this->currentUsage = $currentUsage;
        $this->limit = $limit;
    }

    public function getQuotaType(): string
    {
        return $this->quotaType;
    }

    public function getCurrentUsage(): int|float
    {
        return $this->currentUsage;
    }

    public function getLimit(): int|float
    {
        return $this->limit;
    }
}
