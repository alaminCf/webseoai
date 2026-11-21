<?php

namespace App\Domain\Integrations\Exceptions;

use Exception;

class UnsupportedCapabilityException extends Exception
{
    private string $provider;
    private string $capability;

    public function __construct(string $provider, string $capability, string $message = '')
    {
        $this->provider = $provider;
        $this->capability = $capability;

        $msg = $message ?: "Operation [{$capability}] is not supported by the [{$provider}] adapter.";
        parent::__construct($msg, 422);
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getCapability(): string
    {
        return $this->capability;
    }
}
