<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A workflow task moved to completed.
 *
 * Carries ids rather than the model: listeners run out-of-band, and by the time
 * one is handled the record may have moved on. Re-reading it is the honest thing
 * to do.
 */
class EnquiryTaskCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $taskId,
        public readonly string $taskType,
        public readonly ?int $enquiryId,
        public readonly ?int $completedByUserId,
    ) {}
}
