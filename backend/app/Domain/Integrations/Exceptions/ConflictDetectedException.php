<?php

namespace App\Domain\Integrations\Exceptions;

use Exception;

class ConflictDetectedException extends Exception
{
    private string $targetIdentifier;
    private string $expectedChecksum;
    private string $actualChecksum;

    public function __construct(
        string $targetIdentifier,
        string $expectedChecksum = '',
        string $actualChecksum = '',
        string $message = ''
    ) {
        $this->targetIdentifier = $targetIdentifier;
        $this->expectedChecksum = $expectedChecksum;
        $this->actualChecksum = $actualChecksum;

        $msg = $message ?: "Concurrent modification detected on [{$targetIdentifier}]. Remote content was modified since approval.";
        parent::__construct($msg, 409);
    }

    public function getTargetIdentifier(): string
    {
        return $this->targetIdentifier;
    }

    public function getExpectedChecksum(): string
    {
        return $this->expectedChecksum;
    }

    public function getActualChecksum(): string
    {
        return $this->actualChecksum;
    }
}
