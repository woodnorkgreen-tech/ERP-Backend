<?php

namespace App\Listeners;

use App\Events\PettyCashDisbursementPaid;
use App\Modules\Finance\CostCollector\Services\PettyCashCostPoster;
use App\Modules\Finance\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records a paid petty cash disbursement as a project cost.
 *
 * Before this, `PettyCashCostProducer` was reachable only through
 * `php artisan finance:backfill-petty-cash`, so a payment made today did not
 * reach its project's cost account until someone remembered to run a command.
 * Every project's actuals were as stale as the last backfill.
 *
 * QUEUED deliberately. Hooking a cost-ledger write into the payment path means a
 * failure here could otherwise stop somebody paying out of the tin — and no
 * cost-reporting concern is worth blocking the workflow it observes. The cost
 * line lands a moment later instead.
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
class RecordPettyCashCost implements ShouldQueue
{
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(private PettyCashCostPoster $poster) {}

    public function handle(PettyCashDisbursementPaid $event): void
    {
        $disbursement = Payment::find($event->disbursementId);

        // Voided between payment and handling, or removed outright. The producer
        // would skip it anyway; reading it back is what makes that decision on
        // current state rather than on state at dispatch.
        if (! $disbursement) {
            Log::info('Petty cash disbursement gone before its cost could be recorded', [
                'disbursement_id' => $event->disbursementId,
            ]);

            return;
        }

        $outcome = $this->poster->attempt($disbursement);

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
    }

    /**
     * A payment that could not be costed must be visible, not silent — the
     * project would otherwise under-report with nothing to explain it.
     */
    public function failed(PettyCashDisbursementPaid $event, Throwable $e): void
    {
        Log::error('Failed to record petty cash cost', [
            'disbursement_id' => $event->disbursementId,
            'error' => $e->getMessage(),
        ]);
    }
}
