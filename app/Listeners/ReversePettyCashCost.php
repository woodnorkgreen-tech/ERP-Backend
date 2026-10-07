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
use App\Modules\Finance\Services\FinanceEventPoster;
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
 * Not queued (Report 76A): run in the voiding request through
 * FinanceEventPoster. Voiding a payment still does not fail because of
 * anything here — a problem becomes a failed posting to retry.
 */
class ReversePettyCashCost
{
    public function __construct(
        private CostVerificationService $verification,
        private JournalPostingService $journalPosting,
        private FinanceEventPoster $postings,
    ) {}

    public function handle(PettyCashDisbursementVoided $event): void
    {
        $this->postings->record(FinanceEventPoster::PAYMENT_COST_REVERSAL, $event->disbursementId, [
            'voided_by_user_id' => $event->voidedByUserId,
            'reason' => $event->reason,
        ]);
    }

    public function post(array $payload, int $disbursementId): string
    {
        $event = new PettyCashDisbursementVoided(
            $disbursementId,
            isset($payload['voided_by_user_id']) ? (int) $payload['voided_by_user_id'] : null,
            (string) ($payload['reason'] ?? ''),
        );

        return $this->reverseFor($event);
    }

    private function reverseFor(PettyCashDisbursementVoided $event): string
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
        $this->reverseDirectPayment($event);

        $lines = CostLine::where('source_type', Payment::class)
            ->where('source_id', $event->disbursementId)
            ->get();

        // Nothing was ever costed — spend with no job number, or ADM overhead.
        // The common case, and not a problem.
        if ($lines->isEmpty()) {
            return 'no project cost to reverse';
        }

        // reverse() records who backed the line out, so it needs a real actor.
        // Voids carry Auth::id(); a null here means the void came from somewhere
        // that had no authenticated user, which is worth seeing rather than
        // papering over with a system account.
        // Since Report 76A the payment reversal itself reverses these lines, in
        // its own transaction (PaymentReversalService::reverseDirectCost), so
        // on the normal path every line here is already reversed and this is a
        // check that finds nothing to do. It stays as the net under any void
        // that reached the event without going through that service.
        $outstanding = $lines->filter(fn (CostLine $line) => $line->status !== CostLine::STATUS_REVERSED);

        if ($outstanding->isEmpty()) {
            return 'project cost already reversed with the payment';
        }

        $actor = $event->voidedByUserId ? User::find($event->voidedByUserId) : null;

        if (! $actor) {
            // Stated as what it is: costs still standing against a voided
            // payment, and nobody to attribute the reversal to. No system user
            // is invented — a cost reversal names its actor (Report 76 P1-11:
            // this branch used to read `$line->id` before any line was in
            // scope, so the job died on the log call instead of reporting).
            Log::error('Voided payment still has project cost standing; it cannot be reversed without the voiding user', [
                'disbursement_id' => $event->disbursementId,
                'cost_line_ids' => $outstanding->pluck('id')->all(),
                'cost_line_refs' => $outstanding->pluck('ref')->all(),
                'voided_by_user_id' => $event->voidedByUserId,
            ]);

            throw new \RuntimeException(sprintf(
                'Payment %d was voided with no identified user, so its project cost (%s) is still standing and must be reversed by a named Finance user.',
                $event->disbursementId,
                $outstanding->pluck('ref')->implode(', '),
            ));
        }

        $failures = [];

        foreach ($outstanding as $line) {

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
                $failures[] = "{$line->ref}: {$e->getMessage()}";
            }
        }

        // Raised after every line has had its turn, so one refusal does not
        // hide the others — and raised at all, so the posting record behind
        // this listener reads FAILED and can be retried, instead of a log line
        // being the only trace that a voided payment still charges a job.
        if ($failures !== []) {
            throw new \RuntimeException('Project cost of a voided payment could not be reversed — '.implode('; ', $failures));
        }

        return 'reversed '.$outstanding->count().' cost line(s)';
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

    private function reverseDirectPayment(PettyCashDisbursementVoided $event): void
    {
        $entry = JournalEntry::where(
            'entry_no',
            'JE-PAY-' . str_pad((string) $event->disbursementId, 7, '0', STR_PAD_LEFT),
        )->first();

        if (! $entry) {
            return;
        }

        try {
            $this->journalPosting->reverseEntry(
                $entry,
                $event->voidedByUserId,
                'Payment voided: ' . $event->reason,
            );
        } catch (Throwable $failure) {
            Log::error('Direct payment journal could not be reversed', [
                'disbursement_id' => $event->disbursementId,
                'entry_no' => $entry->entry_no,
                'error' => $failure->getMessage(),
            ]);
        }
    }

}
