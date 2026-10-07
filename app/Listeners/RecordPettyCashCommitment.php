<?php

namespace App\Listeners;

use App\Events\PettyCashRequisitionApproved;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\Services\FinanceEventPoster;
use Illuminate\Support\Facades\Log;

/**
 * Records an approved fund requisition as a committed project cost.
 *
 * Without this, a project's cost account was blind to approved-but-unpaid
 * petty cash: an approved purchase order showed as committed spend, while an
 * approved requisition for the same job showed nothing until somebody paid it
 * out. Budget-versus-actual understated what the project had already promised.
 *
 * Not queued (Report 76A): posted in the approving request, after it commits,
 * through FinanceEventPoster. A cost-ledger problem still cannot stop an
 * approval going through — it becomes a failed posting Finance can see and
 * retry, rather than a job waiting on a worker that may not exist.
 *
 * Re-running is safe. The producer posts through `postFromSource()`, which is
 * idempotent on `(source_type, source_id)`, so re-approval cannot commit twice.
 */
class RecordPettyCashCommitment
{
    public function __construct(
        private PettyCashCostProducer $producer,
        private FinanceEventPoster $postings,
    ) {}

    public function handle(PettyCashRequisitionApproved $event): void
    {
        $this->postings->record(FinanceEventPoster::REQUISITION_COMMITMENT, $event->requisitionId);
    }

    public function post(array $payload, int $requisitionId): string
    {
        $requisition = PettyCashRequisition::find($requisitionId);

        // Read back rather than trust the dispatch: by the time this runs the
        // requisition may already have been paid out, which the producer treats
        // as "no longer a commitment" on current state.
        if (! $requisition) {
            Log::info('Fund requisition gone before its commitment could be recorded', [
                'requisition_id' => $requisitionId,
            ]);

            return 'skipped_requisition_missing';
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

        return $outcome;
    }
}
