<?php

namespace App\Exceptions;

use Exception;

class GovernanceException extends Exception
{
    protected array $gateContext;

    public function __construct(string $message, array $gateContext = [])
    {
        parent::__construct($message, 403);
        $this->gateContext = $gateContext;
    }

    public function getGateContext(): array
    {
        return $this->gateContext;
    }
}
