<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A fund requisition was approved: the company has promised a project's money
 * to someone, but no cash has moved yet.
 *
 * This is the petty-cash counterpart of {@see PurchaseOrderApproved}. Both say
 * the same thing about a project — money is spoken for — and both exist so the
 * cost account can show a promise before it becomes a payment.
 *
 * Carries the id rather than the model, for the same reason
 * {@see PettyCashDisbursementPaid} does: the listener runs out-of-band and the
 * record may have moved on by the time it is handled.
 */
class PettyCashRequisitionApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $requisitionId,
    ) {}
}
