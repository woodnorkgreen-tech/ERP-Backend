<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * W3-7 (confirmed 2026-09-23): the controlled correction path for a petty-cash
 * surrender that has already been reconciled and posted.
 *
 * Original → Reversal → Corrected. Nothing posted is edited or deleted:
 *
 * - the surrender's clearing journal (JE-PCS-*) gets a compensating entry
 *   through JournalPostingService::reverseEntry(), dated today, one per entry;
 * - each surrender CostLine is retired to `reversed` so project cost follows
 *   the ledger (a legacy line that posted its own journal is reversed too);
 * - cash change credited back to the float at reconciliation is debited back
 *   out, because the corrected surrender will credit it again;
 * - the items are retired (superseded_at), not deleted — their reversed cost
 *   lines still point at them — and a snapshot is kept in the review history.
 *
 * The requisition then returns to `surrender_returned`, so the requester
 * submits corrected receipts through the ordinary W3-6 path, and the next
 * reconciliation posts a new clearing journal generation rather than being
 * short-circuited by the reversed one.
 *
 * PaymentReversalService is deliberately not reused: it is keyed to a
 * Payment row and cannot reach a requisition-sourced entry (see the register).
 */
class PettyCashSurrenderReversalService
{
    public function __construct(private JournalPostingService $journals)
    {
    }

    public function reverse(int $requisitionId, User $actor, string $reason): PettyCashRequisition
    {
        return DB::transaction(function () use ($requisitionId, $actor, $reason) {
            $requisition = PettyCashRequisition::query()
                ->with(['surrenderItems', 'disbursement.paymentSource'])
                ->lockForUpdate()
                ->findOrFail($requisitionId);

            if ($requisition->status !== 'surrendered') {
                throw ValidationException::withMessages([
                    'surrender' => 'Only a reconciled surrender can be reversed.',
                ]);
            }
            if ((int) $requisition->user_id === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'surrender' => 'The requester cannot reverse their own surrender.',
                ]);
            }

            $entry = $requisition->surrender_journal_entry_id
                ? JournalEntry::with('lines')->find($requisition->surrender_journal_entry_id)
                : null;
            if (! $entry) {
                throw ValidationException::withMessages([
                    'surrender' => 'This surrender has no posted clearing journal to reverse.',
                ]);
            }
            // reverseEntry() would quietly return an existing reversal; here a
            // second reversal of the same surrender is an error to report.
            if ($entry->status === 'reversed' || JournalEntry::where('reversal_of_id', $entry->id)->exists()) {
                throw ValidationException::withMessages([
                    'surrender' => "Clearing journal {$entry->entry_no} has already been reversed.",
                ]);
            }

            try {
                $reversal = $this->journals->reverseEntry($entry, $actor->id, $reason);
            } catch (\InvalidArgumentException $failure) {
                throw ValidationException::withMessages(['surrender' => $failure->getMessage()]);
            }

            $reversedCostLines = [];
            foreach ($requisition->surrenderItems as $item) {
                if (! $item->cost_line_id) {
                    continue;
                }
                $line = CostLine::query()->lockForUpdate()->find($item->cost_line_id);
                if (! $line || $line->status === CostLine::STATUS_REVERSED) {
                    continue;
                }
                // Since STAB-7 a surrender line points at the clearing entry
                // reversed above. A pre-STAB-7 line posted its own journal, and
                // that posting has to be compensated too, or the spend stays in the GL.
                if ($line->journal_entry_id && $line->posted_at && (int) $line->journal_entry_id !== (int) $entry->id) {
                    $this->journals->reverseCostLine($line, $actor->id, $reason);
                }
                $line->forceFill([
                    'status' => CostLine::STATUS_REVERSED,
                    'query_note' => "Surrender {$requisition->requisition_number} reversed: {$reason}",
                ])->save();
                $reversedCostLines[] = $line->id;
            }

            $generation = (int) $requisition->surrender_posting_generation;
            $cashReturned = number_format((float) ($requisition->cash_returned_amount ?? 0), 2, '.', '');
            $fromFloat = $requisition->disbursement?->paymentSource?->type === 'petty_cash'
                || ! $requisition->disbursement?->payment_source_id;
            if (bccomp($cashReturned, '0.00', 2) === 1 && $fromFloat) {
                $floatEntry = LedgerEntry::custom(
                    'SURR-REV-'.$requisition->requisition_number.'-G'.($generation + 1),
                    'debit',
                    $cashReturned,
                    [
                        'reason' => 'Reversal of cash change credited on surrender',
                        'requisition_id' => $requisition->id,
                        'requisition_number' => $requisition->requisition_number,
                        'reversal_reason' => $reason,
                        'reversed_by' => $actor->id,
                    ]
                );
                $floatEntry->sourceType = 'top_up';
                $floatEntry->sourceId = $requisition->id;
                app(LedgerService::class)->post($floatEntry);
            }

            DB::table('petty_cash_surrender_reviews')->insert([
                'petty_cash_requisition_id' => $requisition->id,
                'action' => 'reversed',
                'actor_user_id' => $actor->id,
                'reason' => $reason,
                'snapshot' => json_encode([
                    'requisition' => $requisition->only([
                        'status', 'actual_spent_amount', 'cash_returned_amount', 'surrender_notes',
                        'surrender_reconciled_at', 'surrender_reconciled_by', 'surrender_posting_generation',
                    ]),
                    'items' => $requisition->surrenderItems->toArray(),
                    'original_journal_entry_id' => $entry->id,
                    'reversal_journal_entry_id' => $reversal->id,
                    'reversed_cost_line_ids' => $reversedCostLines,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $requisition->surrenderItems()->update(['superseded_at' => now()]);

            $requisition->update([
                'status' => 'surrender_returned',
                'surrender_reversed_by' => $actor->id,
                'surrender_reversed_at' => now(),
                'surrender_reversal_reason' => $reason,
                'surrender_returned_by' => $actor->id,
                'surrender_returned_at' => now(),
                'surrender_return_reason' => "Reversed after posting: {$reason}",
                'surrender_posting_generation' => $generation + 1,
            ]);

            return $requisition->fresh();
        });
    }
}
