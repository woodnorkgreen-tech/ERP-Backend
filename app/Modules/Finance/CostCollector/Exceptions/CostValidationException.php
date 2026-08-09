<?php

namespace App\Modules\Finance\CostCollector\Exceptions;

use RuntimeException;

/**
 * A cost was rejected by the catalogue's own rules.
 *
 * Carries per-field errors so the mobile client can point at the field that
 * failed rather than showing a generic banner — the exact failure mode the July
 * Finance audit found in the petty-cash form, where genuine 422s degraded into
 * an unhelpful message with nothing highlighted.
 */
class CostValidationException extends RuntimeException
{
    /** @param array<string, array<int, string>> $errors */
    public function __construct(
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, array<int, string>> $errors */
    public static function withErrors(array $errors): self
    {
        $first = collect($errors)->flatten()->first() ?? 'The cost could not be recorded.';

        return new self($first, $errors);
    }
}
