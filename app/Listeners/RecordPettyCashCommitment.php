<?php

namespace App\Listeners;

use App\Events\PettyCashRequisitionApproved;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records an approved fund requisition as a committed project cost.
 *
 * Without this, a project's cost account was blind to approved-but-unpaid
 * petty cash: an approved purchase order showed as committed spend, while an
 * approved requisition for the same job showed nothing until somebody paid it
 * out. Budget-versus-actual understated what the project had already promised.
 *
 * QUEUED, and for the same reason as {@see RecordPettyCashCost}: a cost-ledger
 * write must never be able to stop an approval going through. The commitment
 * lands a moment later instead.
 *
 * Re-running is safe. The producer posts through `postFromSource()`, which is
 * idempotent on `(source_type, source_id)`, so re-approval cannot commit twice.
 */
class RecordPettyCashCommitment implements ShouldQueue
{
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(private PettyCashCostProducer $producer) {}

    public function handle(PettyCashRequisitionApproved $event): void
    {
        $requisition = PettyCashRequisition::find($event->requisitionId);

        // Read back rather than trust the dispatch: by the time this runs the
        // requisition may already have been paid out, which the producer treats
        // as "no longer a commitment" on current state.
        if (! $requisition) {
            Log::info('Fund requisition gone before its commitment could be recorded', [
                'requisition_id' => $event->requisitionId,
            ]);

            return;
        }

        $outcome = $this->producer->commitFor($requisition);

        // The skips are ordinary rather than failures: office spend carries no
        // project to commit against. Logged so a light project cost account can
        // still be explained.
        Log::info('Fund requisition commitment recorded', [
            'requisition_id' => $requisition->id,
            'requisition_number' => $requisition->requisition_number,
            'outcome' => $outcome,
        ]);
    }

    /**
     * A promise that could not be committed must be visible, not silent — the
     * project would otherwise under-report with nothing to explain it.
     */
    public function failed(PettyCashRequisitionApproved $event, Throwable $e): void
    {
        Log::error('Failed to record fund requisition commitment', [
            'requisition_id' => $event->requisitionId,
            'error' => $e->getMessage(),
        ]);
    }
}
