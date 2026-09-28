<?php

namespace App\Modules\Finance\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * W2-5 / W3-5: a refusal because the transaction exactly matches one already
 * recorded. Still a ValidationException — callers and tests that read the
 * field error keep working — but it also carries the facts the match was
 * made on, so a screen can say *why* it was flagged and whether the current
 * user may override it, instead of parsing a sentence.
 */
class DuplicateTransactionException extends ValidationException
{
    /** @var array<string, mixed> */
    public array $facts = [];

    public string $duplicateCode = 'DUPLICATE_TRANSACTION';

    /** @param array<string, mixed> $facts */
    public static function because(string $code, string $field, string $message, array $facts): static
    {
        $exception = static::withMessages([$field => [$message]]);
        $exception->duplicateCode = $code;
        $exception->facts = $facts;

        return $exception;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['code' => $this->duplicateCode, 'duplicate' => $this->facts];
    }
}
