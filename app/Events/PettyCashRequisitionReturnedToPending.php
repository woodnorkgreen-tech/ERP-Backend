<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An approved fund requisition was edited, so it went back to pending.
 *
 * The counterpart of {@see PettyCashRequisitionApproved}: that one says a
 * project's money is spoken for, this one says it no longer is. Editing an
 * approved requisition withdraws the approval — the amount, the payee or the
 * project may all have changed — and the commitment recorded against the
 * project was a promise about the version that was approved.
 *
 * It matters more than an undo. Rejection is only reachable from pending, so
 * this is also the doorway to "approved, then edited, then rejected" — the one
 * path where a commitment would otherwise never be released by anything,
 * because release is otherwise the job of a payment that is no longer coming.
 *
 * Carries the id rather than the model, as the other requisition events do:
 * the listener runs out-of-band and the record may have moved on.
 */
class PettyCashRequisitionReturnedToPending
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $requisitionId,
    ) {}
}
