<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A petty cash payment was backed out.
 *
 * The cash ledger already posted its own reversing credit by the time this
 * fires. This exists so the *cost* ledger hears about it too: a voided payment
 * that keeps its cost line overstates the project forever, and the two ledgers
 * are meant to answer their questions from one set of payments.
 */
class PettyCashDisbursementVoided
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $disbursementId,
        public readonly ?int $voidedByUserId,
        public readonly string $reason,
    ) {}
}
