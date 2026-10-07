<?php

namespace App\Listeners;

use App\Events\PettyCashRequisitionReturnedToPending;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\Services\FinanceEventPoster;
use Illuminate\Support\Facades\Log;

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
 * Not queued (Report 76A): run in the editing request, after it commits,
 * through FinanceEventPoster. A cost-ledger problem still cannot stop somebody
 * editing a requisition — it is recorded as a failed posting to retry.
 */
class ReleasePettyCashCommitment
{
    public function __construct(
        private PettyCashCostProducer $producer,
        private FinanceEventPoster $postings,
    ) {}

    public function handle(PettyCashRequisitionReturnedToPending $event): void
    {
        $this->postings->record(FinanceEventPoster::REQUISITION_COMMITMENT_RELEASE, $event->requisitionId);
    }

    public function post(array $payload, int $requisitionId): string
    {
        $requisition = PettyCashRequisition::find($requisitionId);

        if (! $requisition) {
            Log::info('Fund requisition gone before its commitment could be released', [
                'requisition_id' => $requisitionId,
            ]);

            return 'skipped_requisition_missing';
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

        return $outcome;
    }
}
