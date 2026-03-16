<?php

namespace App\Services\Governance;

class GateResult
{
    public bool $authorized;
    public ?string $message;
    public array $context;

    public function __construct(bool $authorized, ?string $message = null, array $context = [])
    {
        $this->authorized = $authorized;
        $this->message = $message;
        $this->context = $context;
    }

    public static function authorized(array $context = []): self
    {
        return new self(true, null, $context);
    }

    public static function blocked(string $message, array $context = []): self
    {
        return new self(false, $message, $context);
    }

    public function isAuthorized(): bool
    {
        return $this->authorized;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }
}
