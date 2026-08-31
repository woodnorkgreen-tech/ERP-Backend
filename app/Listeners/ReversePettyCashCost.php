<?php

namespace App\Listeners;

use App\Events\PettyCashDisbursementVoided;
use App\Models\User;
use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostVerificationService;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
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

    public function __construct(private CostVerificationService $verification) {}

    public function handle(PettyCashDisbursementVoided $event): void
    {
        $lines = CostLine::where('source_type', PettyCashDisbursement::class)
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

    public function failed(PettyCashDisbursementVoided $event, Throwable $e): void
    {
        Log::error('Failed to reverse petty cash cost after void', [
            'disbursement_id' => $event->disbursementId,
            'error' => $e->getMessage(),
        ]);
    }
}
