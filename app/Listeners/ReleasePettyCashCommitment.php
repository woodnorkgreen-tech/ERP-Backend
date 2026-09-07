<?php

namespace App\Listeners;

use App\Events\PettyCashRequisitionReturnedToPending;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retires the commitment behind a withdrawn approval.
 *
 * Until this existed, a disbursement was the only thing that released a
 * requisition's commitment, which left two ways for a project to carry money
 * it was not going to spend:
 *
 *  - Edited and re-approved at a different amount. `postFromSource` is
 *    idempotent on the source document, so re-approval returned the original
 *    line untouched and the project kept showing the old figure.
 *  - Edited and then rejected. Rejection is only reachable from pending, so
 *    this is how an approved requisition reaches it — and with no payment
 *    coming, nothing would ever have released the commitment.
 *
 * Releasing here settles both: the promise dies with the approval, and a fresh
 * approval records a fresh commitment for what was actually approved.
 *
 * QUEUED, like {@see RecordPettyCashCommitment}, so a cost-ledger write can
 * never stop somebody editing a requisition.
 */
class ReleasePettyCashCommitment implements ShouldQueue
{
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(private PettyCashCostProducer $producer) {}

    public function handle(PettyCashRequisitionReturnedToPending $event): void
    {
        $requisition = PettyCashRequisition::find($event->requisitionId);

        if (! $requisition) {
            Log::info('Fund requisition gone before its commitment could be released', [
                'requisition_id' => $event->requisitionId,
            ]);

            return;
        }

        $outcome = $this->producer->releaseFor(
            $requisition,
            'Approval withdrawn when the requisition was edited.',
        );

        Log::info('Fund requisition commitment released', [
            'requisition_id' => $requisition->id,
            'requisition_number' => $requisition->requisition_number,
            'outcome' => $outcome,
        ]);
    }

    public function failed(PettyCashRequisitionReturnedToPending $event, Throwable $exception): void
    {
        Log::error('Could not release the commitment for an edited fund requisition', [
            'requisition_id' => $event->requisitionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
