<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashSurrender;
use App\Modules\Finance\PettyCash\Models\PettyCashSurrenderAllocation;
use Illuminate\Validation\ValidationException;

/**
 * Report 75R-B: where every shilling of a requisition stands.
 *
 * The one calculation behind the screen, the reports, the advance-exposure
 * figures, the commitment and the closure test. Nothing is stored: each figure
 * is summed from the records that are the facts —
 *
 *   approved      the requisition lines, once approved
 *   disbursed     active Payments, through their line allocations
 *   released      recorded releases of approved money that will not be paid
 *   confirmed     Payments with a live receipt confirmation
 *   accepted      spend in reconciled surrenders
 *   returned      money returned in reconciled surrenders
 *   under review  amounts in surrenders awaiting Finance
 *
 * and the two balances everything else follows from:
 *
 *   to disburse = approved − disbursed − released
 *   to account  = disbursed − accepted − returned
 *
 * The smallest unit is a *slice*: one requisition line as funded by one
 * Payment. A surrender clears slices, so it is always known which advance,
 * from which paying account, an amount accounts for.
 */
final class RequisitionPosition
{
    public const BY_RECEIVER = 'by_receiver';
    public const SINGLE_PAYMENT = 'single_payment';
    public const NOT_PAID = 'not_paid';

    public function for(PettyCashRequisition $r): array
    {
        $r->loadMissing(['items.payee', 'items.supplier', 'payee', 'disbursements.paymentSource',
            'disbursements.requisitionAllocations', 'receiptConfirmations', 'balanceReleases',
            'receiverSurrenders.allocations', 'receiverSurrenders.items']);

        $active = $r->disbursements->where('status', 'active');
        $legacy = $active->contains(fn (Payment $p) => ! $p->requisition_child_reference);
        $mode = $legacy ? self::SINGLE_PAYMENT : ($active->isNotEmpty() ? self::BY_RECEIVER : self::NOT_PAID);
        $approvedFlag = (bool) $r->approved_at;

        $confirmedPayments = $r->receiptConfirmations->whereNull('invalidated_at')->pluck('payment_id')->flip();
        $surrenderStatus = $r->receiverSurrenders->pluck('status', 'id');

        // Slices: a line as funded by one active Payment.
        $slicesByLine = [];
        foreach ($active->sortBy('id') as $payment) {
            foreach ($payment->requisitionAllocations as $slice) {
                $slicesByLine[$slice->requisition_item_id][$slice->id] = [
                    'id' => $slice->id, 'payment_id' => $payment->id,
                    'child_reference' => $payment->requisition_child_reference, 'payment_reference' => $payment->payment_no,
                    'payment_source_id' => $payment->payment_source_id, 'paying_account' => $payment->paymentSource?->name,
                    'funded' => (string) $slice->allocated_amount, 'confirmed' => isset($confirmedPayments[$payment->id]),
                    'accepted' => '0.00', 'returned' => '0.00', 'under_review' => '0.00',
                ];
            }
        }
        foreach ($r->receiverSurrenders as $surrender) {
            if (! in_array($surrender->status, PettyCashSurrender::LIVE, true)) {
                continue;
            }
            foreach ($surrender->allocations as $allocation) {
                if (! isset($slicesByLine[$allocation->requisition_item_id][$allocation->requisition_payment_allocation_id])) {
                    continue;   // its Payment is no longer active; reversal rules keep this from mattering
                }
                $slice = &$slicesByLine[$allocation->requisition_item_id][$allocation->requisition_payment_allocation_id];
                $field = $surrender->status === PettyCashSurrender::SUBMITTED ? 'under_review'
                    : ($allocation->kind === PettyCashSurrenderAllocation::RETURN ? 'returned' : 'accepted');
                $slice[$field] = bcadd($slice[$field], (string) $allocation->amount, 2);
                unset($slice);
            }
        }

        $releasedByLine = [];
        foreach ($r->balanceReleases as $release) {
            $releasedByLine[$release->requisition_item_id] = bcadd($releasedByLine[$release->requisition_item_id] ?? '0.00', (string) $release->amount, 2);
        }

        $receivers = $this->receivers($r, $slicesByLine, $releasedByLine, $approvedFlag, $legacy);
        $this->attachSurrenders($r, $receivers);

        $totals = array_fill_keys(['approved', 'disbursed', 'released', 'to_disburse', 'confirmed', 'awaiting_confirmation',
            'accepted', 'returned', 'under_review', 'to_account'], '0.00');
        if (! $legacy) {
            foreach ($receivers as $receiver) {
                foreach (array_keys($totals) as $field) {
                    $totals[$field] = bcadd($totals[$field], $receiver[$field], 2);
                }
            }
        } else {
            // A requisition paid as one payment is accounted for on the requisition itself.
            $paid = $active->reduce(fn (string $sum, Payment $p) => bcadd($sum, (string) $p->amount, 2), '0.00');
            $reconciled = $r->status === 'surrendered';
            $accepted = $reconciled ? bcadd((string) ($r->actual_spent_amount ?? '0'), '0', 2) : '0.00';
            $returned = $reconciled ? bcadd((string) ($r->cash_returned_amount ?? '0'), '0', 2) : '0.00';
            $approved = $approvedFlag ? (string) $r->total_amount : '0.00';
            $confirmed = $r->received_at ? $paid : '0.00';
            $totals = ['approved' => $approved, 'disbursed' => $paid, 'released' => '0.00',
                'to_disburse' => bcsub($approved, $paid, 2), 'confirmed' => $confirmed,
                'awaiting_confirmation' => bcsub($paid, $confirmed, 2), 'accepted' => $accepted, 'returned' => $returned,
                'under_review' => $r->status === 'surrender_pending' ? bcadd((string) ($r->actual_spent_amount ?? '0'), (string) ($r->cash_returned_amount ?? '0'), 2) : '0.00',
                // The whole advance stays open until its one surrender is reconciled.
                'to_account' => $reconciled ? '0.00' : $paid];
        }

        return ['mode' => $mode, 'legacy_single_payment' => $legacy, 'receivers' => $receivers] + $totals;
    }

    /**
     * One receiver's position, or a refusal if that receiver is not on the requisition.
     */
    public function receiver(PettyCashRequisition $r, string $receiverKey): array
    {
        $receiver = collect($this->for($r)['receivers'])->firstWhere('key', $receiverKey);
        if (! $receiver || $receiver['receiver_type'] === 'unresolved') {
            throw ValidationException::withMessages(['receiver_key' => 'That receiver is not on this requisition.']);
        }

        return $receiver;
    }

    /** Lines grouped by receiver identity, each with its slices and balances. */
    private function receivers(PettyCashRequisition $r, array $slicesByLine, array $releasedByLine, bool $approved, bool $legacy): array
    {
        $identityService = app(RequisitionReceiverIdentity::class);
        $groups = [];
        $otherNames = [];
        foreach ($r->items as $item) {
            $identity = null;
            $problem = null;
            try {
                $identity = $identityService->forLine($r, $item);
                if ($identity['type'] === RequisitionReceiverIdentity::OTHER) {
                    $otherNames[$identity['key']] ??= mb_strtolower($identity['name']);
                    if ($otherNames[$identity['key']] !== mb_strtolower($identity['name'])) {
                        $problem = 'This recipient reference is used for two different names.';
                    }
                }
            } catch (ValidationException $e) {
                $problem = collect($e->errors())->flatten()->first();
            }

            $employee = $item->payee ?? $r->payee;
            $fallbackName = $employee ? trim($employee->first_name.' '.$employee->last_name) : ($item->payee_name ?: $r->payee_name);
            // A name alone is not an identity, so an unidentified line stands on its own.
            $key = $identity && ! $problem ? $identity['key'] : 'unresolved-line:'.$item->id;
            $groups[$key] ??= [
                'key' => $key,
                'receiver_type' => $identity && ! $problem ? $identity['type'] : 'unresolved',
                'receiver_id' => $identity['id'] ?? null,
                'receiver_identity' => $identity['identity'] ?? null,
                'reference' => $identity && $identity['type'] === RequisitionReceiverIdentity::OTHER ? $item->other_recipient_reference : null,
                'name' => ($identity['name'] ?? $fallbackName) ?: 'Receiver not identified',
                'receiver_problem' => $problem,
                'requested' => '0.00', 'lines' => [],
            ];
            $groups[$key]['requested'] = bcadd($groups[$key]['requested'], (string) $item->amount, 2);
            $groups[$key]['lines'][] = $this->line($item, array_values($slicesByLine[$item->id] ?? []), $releasedByLine[$item->id] ?? '0.00', $approved, $legacy);
        }

        foreach ($groups as &$group) {
            $group['approved'] = $approved ? $group['requested'] : '0.00';
            foreach (['released', 'confirmed', 'accepted', 'returned', 'under_review'] as $field) {
                $group[$field] = $this->sum($group['lines'], $field);
            }
            if ($legacy) {
                // Not apportioned between receivers: the one payment says nothing about who it was for.
                $group += ['paid' => null, 'outstanding' => null, 'payment_allocation_status' => 'not_linked',
                    'disbursed' => '0.00', 'to_disburse' => '0.00', 'awaiting_confirmation' => '0.00', 'to_account' => '0.00',
                    'available_to_account' => '0.00'];

                continue;
            }
            $group['paid'] = $this->sum($group['lines'], 'paid');
            $group['disbursed'] = $group['paid'];
            $group['to_disburse'] = bcsub(bcsub($group['approved'], $group['paid'], 2), $group['released'], 2);
            // `outstanding` is the name Report 75R-A's callers use for what is still to pay.
            $group['outstanding'] = $group['to_disburse'];
            $group['awaiting_confirmation'] = bcsub($group['paid'], $group['confirmed'], 2);
            $group['to_account'] = bcsub(bcsub($group['paid'], $group['accepted'], 2), $group['returned'], 2);
            $group['available_to_account'] = $this->sum($group['lines'], 'available_to_account');
            $group['payment_allocation_status'] = RequisitionControlProjection::disbursementStatus(
                bcsub($group['approved'], $group['released'], 2), $group['paid']);
        }
        unset($group);

        return array_values($groups);
    }

    private function line($item, array $slices, string $released, bool $approved, bool $legacy): array
    {
        foreach ($slices as &$slice) {
            // Only confirmed money can be accounted for, and only what no surrender already claims.
            $slice['available'] = $slice['confirmed']
                ? bcsub(bcsub(bcsub($slice['funded'], $slice['accepted'], 2), $slice['returned'], 2), $slice['under_review'], 2)
                : '0.00';
        }
        unset($slice);

        $paid = $this->sum($slices, 'funded');
        $accepted = $this->sum($slices, 'accepted');
        $returned = $this->sum($slices, 'returned');
        $amount = (string) $item->amount;
        $lineApproved = $approved ? $amount : '0.00';

        return [
            'id' => $item->id, 'purpose' => $item->description, 'amount' => $amount,
            'paid' => $legacy ? null : $paid,
            'released' => $released,
            'outstanding' => $legacy ? null : bcsub(bcsub($lineApproved, $paid, 2), $released, 2),
            'confirmed' => collect($slices)->where('confirmed', true)->reduce(fn (string $sum, array $s) => bcadd($sum, $s['funded'], 2), '0.00'),
            'accepted' => $accepted, 'returned' => $returned,
            'under_review' => $this->sum($slices, 'under_review'),
            'to_account' => bcsub(bcsub($paid, $accepted, 2), $returned, 2),
            'available_to_account' => $this->sum($slices, 'available'),
            'slices' => $slices,
        ];
    }

    /** Each receiver's surrender stages, newest last, and whether one is open. */
    private function attachSurrenders(PettyCashRequisition $r, array &$receivers): void
    {
        $purposes = $r->items->pluck('description', 'id');
        foreach ($receivers as &$receiver) {
            $stages = $r->receiverSurrenders
                ->filter(fn (PettyCashSurrender $s) => $s->receiver_type.':'.$s->receiver_identity === $receiver['key'])
                ->sortBy('id')->values();
            $receiver['surrenders'] = $stages->map(fn (PettyCashSurrender $s) => [
                'id' => $s->id, 'reference' => $s->reference, 'status' => $s->status,
                'spent' => (string) $s->spent_amount, 'returned' => (string) $s->returned_amount,
                'overspend' => (string) $s->overspend_amount, 'notes' => $s->notes,
                'submitted_at' => $s->submitted_at?->toIso8601String(), 'submitted_by_id' => $s->submitted_by,
                'reconciled_at' => $s->reconciled_at?->toIso8601String(), 'reconciled_by_id' => $s->reconciled_by,
                'returned_at' => $s->returned_at?->toIso8601String(), 'return_reason' => $s->return_reason,
                'reversed_at' => $s->reversed_at?->toIso8601String(), 'reversal_reason' => $s->reversal_reason,
                'journal_entry_id' => $s->journal_entry_id,
                'items' => ($s->status === PettyCashSurrender::REVERSED ? $s->allItems : $s->items)->map(fn ($item) => [
                    'id' => $item->id, 'requisition_item_id' => $item->requisition_item_id,
                    'purpose' => $purposes[$item->requisition_item_id] ?? null, 'description' => $item->description,
                    'expense_code_id' => $item->expense_code_id, 'amount' => (string) $item->amount,
                    'tax_amount' => (string) $item->tax_amount, 'receipt_type' => $item->receipt_type,
                    'receipt_number' => $item->receipt_number, 'supplier_name' => $item->supplier_name,
                    'supplier_kra_pin' => $item->supplier_kra_pin, 'cost_line_id' => $item->cost_line_id,
                ])->values()->all(),
                'returns' => $s->allocations->where('kind', PettyCashSurrenderAllocation::RETURN)->map(fn ($a) => [
                    'requisition_item_id' => $a->requisition_item_id, 'purpose' => $purposes[$a->requisition_item_id] ?? null,
                    'payment_id' => $a->payment_id, 'amount' => (string) $a->amount, 'reference' => $a->reference,
                    'payment_source_id' => $a->payment_source_id, 'cash_movement_id' => $a->cash_movement_id,
                ])->values()->all(),
            ])->all();
            $receiver['open_surrender_id'] = $stages
                ->first(fn (PettyCashSurrender $s) => in_array($s->status, [PettyCashSurrender::SUBMITTED, PettyCashSurrender::RETURNED], true))?->id;
            $receiver['overspend'] = $stages->whereIn('status', [PettyCashSurrender::SUBMITTED, PettyCashSurrender::RETURNED])
                ->reduce(fn (string $sum, PettyCashSurrender $s) => bcadd($sum, (string) $s->overspend_amount, 2), '0.00');
            $receiver['accountability_state'] = $this->receiverState($receiver, $stages);
        }
        unset($receiver);
    }

    private function receiverState(array $receiver, $stages): string
    {
        if ($receiver['receiver_problem']) {
            return 'receiver_not_identified';
        }
        if ($receiver['paid'] === null) {
            return 'not_linked';
        }
        $zero = fn (string $amount) => bccomp($amount, '0.00', 2) === 0;

        return match (true) {
            $zero($receiver['paid']) => $zero($receiver['to_disburse']) ? 'released' : 'awaiting_payment',
            ! $zero($receiver['awaiting_confirmation']) => 'awaiting_receipt_confirmation',
            ! $zero($receiver['overspend']) => 'overspend_requires_resolution',
            ! $zero($receiver['under_review']) => 'accountability_review',
            $stages->contains('status', PettyCashSurrender::RETURNED) => 'accountability_returned',
            ! $zero($receiver['to_account']) => $zero(bcadd($receiver['accepted'], $receiver['returned'], 2)) ? 'awaiting_accountability' : 'partially_accounted',
            ! $zero($receiver['to_disburse']) => 'balance_to_pay_or_release',
            default => 'complete',
        };
    }

    private function sum(array $rows, string $field): string
    {
        return array_reduce($rows, fn (string $sum, array $row) => bcadd($sum, (string) ($row[$field] ?? '0.00'), 2), '0.00');
    }
}
