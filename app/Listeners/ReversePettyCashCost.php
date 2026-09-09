<?php

namespace App\Listeners;

use App\Events\PettyCashDisbursementVoided;
use App\Models\User;
use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostVerificationService;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backs out the project cost behind a voided petty cash payment.
 *
 * The producer refuses to cost a voided payment, but that guard only covers
 * lines created *after* the void. A payment costed while active and voided
 * afterwards keeps its cost line and overstates the project indefinitely —
 * which is the state the ledger lands in the moment costing becomes live rather
 * than a backfill.
 *
 * QUEUED for the same reason as {@see RecordPettyCashCost}: voiding a payment
 * must not fail because the cost ledger is unhappy.
 */
class ReversePettyCashCost implements ShouldQueue
{
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        private CostVerificationService $verification,
        private JournalPostingService $journalPosting,
    ) {}

    public function handle(PettyCashDisbursementVoided $event): void
    {
        // The transfer charge, backed out first and unconditionally.
        //
        // It has to come before the no-cost-lines return below: a voided
        // supplier settlement never had a cost line — the goods were costed by
        // the procurement relay — but it may well have carried a fee, and that
        // fee is a journal of its own rather than part of a cost line. While it
        // was posted under OE-FIN-001 the cost-line reversal took it with the
        // rest; now nothing else would.
        $this->reverseFee($event);

        $lines = CostLine::where('source_type', Payment::class)
            ->where('source_id', $event->disbursementId)
            ->get();

        // Nothing was ever costed — spend with no job number, or ADM overhead.
        // The common case, and not a problem.
        if ($lines->isEmpty()) {
            return;
        }

        // reverse() records who backed the line out, so it needs a real actor.
        // Voids carry Auth::id(); a null here means the void came from somewhere
        // that had no authenticated user, which is worth seeing rather than
        // papering over with a system account.
        $actor = $event->voidedByUserId ? User::find($event->voidedByUserId) : null;

        if (! $actor) {
            Log::error('Cannot reverse petty cash cost without the voiding user', [
                'disbursement_id' => $event->disbursementId,
                'cost_line_id' => $line->id,
                'voided_by_user_id' => $event->voidedByUserId,
            ]);

            return;
        }

        foreach ($lines as $line) {
            if ($line->status === CostLine::STATUS_REVERSED) {
                continue;
            }

            try {
                $this->verification->reverse($line, $actor, 'Petty cash disbursement voided: ' . $event->reason);

                Log::info('Reversed petty cash cost after void', [
                    'disbursement_id' => $event->disbursementId,
                    'cost_line_id' => $line->id,
                ]);
            } catch (CostValidationException $e) {
                Log::error('Voided petty cash cost could not be reversed', [
                    'disbursement_id' => $event->disbursementId,
                    'cost_line_id' => $line->id,
                    'posted_at' => $line->posted_at,
                    'errors' => $e->errors,
                ]);
            }
        }
    }

    /**
     * Back out the fee journal a voided payment raised, if it raised one.
     *
     * Takes the voiding user's id as given rather than resolving a User: unlike
     * a cost-line reversal, a journal reversal records an actor id and nothing
     * more, so an unattributable void should still return the money to the
     * charges account rather than leave an expense standing against cash that
     * came back.
     */
    private function reverseFee(PettyCashDisbursementVoided $event): void
    {
        $entry = JournalEntry::where(
            'entry_no',
            'JE-PFEE-' . str_pad((string) $event->disbursementId, 7, '0', STR_PAD_LEFT),
        )->first();

        if (! $entry) {
            return;
        }

        try {
            // Idempotent on reversal_of_id, so replaying the void is a no-op.
            $this->journalPosting->reverseEntry(
                $entry,
                $event->voidedByUserId,
                'Payment voided: ' . $event->reason,
            );
        } catch (Throwable $failure) {
            Log::error('Voided payment fee could not be reversed', [
                'disbursement_id' => $event->disbursementId,
                'entry_no' => $entry->entry_no,
                'error' => $failure->getMessage(),
            ]);
        }
    }

    public function failed(PettyCashDisbursementVoided $event, Throwable $e): void
    {
        Log::error('Failed to reverse petty cash cost after void', [
            'disbursement_id' => $event->disbursementId,
            'error' => $e->getMessage(),
        ]);
    }
}
