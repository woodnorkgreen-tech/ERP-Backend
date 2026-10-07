<?php

namespace App\Listeners;

use App\Events\PettyCashDisbursementPaid;
use App\Modules\Finance\CostCollector\Services\PettyCashCostPoster;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\FinanceEventPoster;
use Illuminate\Support\Facades\Log;

/**
 * Records a paid petty cash disbursement as a project cost.
 *
 * Before this, `PettyCashCostProducer` was reachable only through
 * `php artisan finance:backfill-petty-cash`, so a payment made today did not
 * reach its project's cost account until someone remembered to run a command.
 * Every project's actuals were as stale as the last backfill.
 *
 * Not queued (Report 76A). It was, so that a cost-ledger failure could never
 * stop somebody paying out of the tin — and on a host with no queue worker
 * that meant the cost never posted at all, silently. The cost is now posted in
 * the paying request, after the payment commits, through FinanceEventPoster:
 * the payment still cannot be blocked, and a failure is a visible posting to
 * retry.
 *
 * Re-running is safe: the producer posts through `postFromSource()`, which is
 * idempotent on `(source_type, source_id)`, so a retry is a no-op rather than a
 * double charge.
 *
 * Posting is delegated to `PettyCashCostPoster` (Wave 1 Closure Gate §3.D)
 * rather than calling the producer directly: the cash already left the float
 * by the time this queued job runs, so a posting failure here must be
 * recorded as Finance-visible state and become retryable — not just logged —
 * the same STAB-4 principle already applied to requisition advances.
 */
class RecordPettyCashCost
{
    public function __construct(
        private PettyCashCostPoster $poster,
        private FinanceEventPoster $postings,
    ) {}

    public function handle(PettyCashDisbursementPaid $event): void
    {
        $this->postings->record(FinanceEventPoster::PAYMENT_COST, $event->disbursementId);
    }

    public function post(array $payload, int $disbursementId): string
    {
        $disbursement = Payment::find($disbursementId);

        // Voided between payment and handling, or removed outright. The producer
        // would skip it anyway; reading it back is what makes that decision on
        // current state rather than on state at dispatch.
        if (! $disbursement) {
            Log::info('Petty cash disbursement gone before its cost could be recorded', [
                'disbursement_id' => $disbursementId,
            ]);

            return 'skipped_payment_missing';
        }

        $outcome = $this->poster->attempt($disbursement);

        // The poster has already flagged the payment and alerted Finance. The
        // posting record must say the same thing, or the two would disagree
        // about whether this payment reached the books.
        if ($outcome === 'gl_posting_failed') {
            throw new \RuntimeException(
                $disbursement->fresh()?->cost_gl_posting_error ?: 'The payment\'s cost/GL entry could not be posted.'
            );
        }

        // The skips are ordinary, not failures: spend with no job number and
        // ADM-coded overhead have no cost object to attach to. Logged at info so
        // an unattributed payment is still traceable when a project looks light.
        // 'gl_posting_failed' is not ordinary — the poster has already flagged
        // the disbursement and alerted Finance for that outcome.
        Log::info('Petty cash cost recorded', [
            'disbursement_id' => $disbursement->id,
            'job_number' => $disbursement->job_number,
            'outcome' => $outcome,
        ]);

        return $outcome;
    }
}
