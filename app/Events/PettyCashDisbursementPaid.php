<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Petty cash went out of the tin against a job.
 *
 * Carries the id rather than the model, for the same reason
 * {@see EnquiryTaskCompleted} does: the listener runs out-of-band, and the
 * record may have moved on — been voided, most obviously — by the time it is
 * handled. Re-reading it is the honest thing to do.
 */
class PettyCashDisbursementPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $disbursementId,
    ) {}
}
