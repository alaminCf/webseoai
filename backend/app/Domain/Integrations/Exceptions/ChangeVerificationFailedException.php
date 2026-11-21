<?php

namespace App\Domain\Integrations\Exceptions;

use Exception;

class ChangeVerificationFailedException extends Exception
{
    private array $verificationDetails;

    public function __construct(array $verificationDetails = [], string $message = '')
    {
        $this->verificationDetails = $verificationDetails;
        $msg = $message ?: "Change verification failed: Read-back state does not match submitted changes.";
        parent::__construct($msg, 422);
    }

    public function getVerificationDetails(): array
    {
        return $this->verificationDetails;
    }
}
