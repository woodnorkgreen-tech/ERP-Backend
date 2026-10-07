<?php

namespace App\Modules\Finance\Services;

use App\Models\GovernanceAuditLog;
use App\Models\User;
use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostVerificationService;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashActivityLog;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashSurrender;
use App\Modules\Finance\PettyCash\Models\RequisitionReceiptConfirmation;
use App\Modules\Finance\PettyCash\Services\LedgerEntry;
use App\Modules\Finance\PettyCash\Services\LedgerService;
use App\Modules\ProcurementStores\Models\BillPayment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reverses one cash event and every accounting document it produced.
 *
 * The ONE payment reversal. Every entry point — the Finance "Reverse payment"
 * action, a petty-cash void, a cost reversal that reaches a payment — comes
 * here, so the same payment is undone the same way wherever the user stood.
 *
 * Report 76 P0-2: this used to stop at the journals sourced to the payment.
 * A payment that is itself a project cost (a direct petty-cash payment with a
 * job number, or the settlement of a company-paid cost) carries that cost on a
 * cost line with a journal of its own, and only the petty-cash void path
 * remembered to reverse it — through a queued listener. Reversed from Finance,
 * the payment was voided and the float restored while the job kept the cost
 * and the ledger kept the cash credit.
 */
class PaymentReversalService
{
    public function __construct(private readonly JournalPostingService $journals) {}

    public function reverse(Payment $payment, int $actorId, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $actorId, $reason): Payment {
            // Report 75R-A: the parent requisition is locked before the Payment,
            // the same order the receiver-payment path takes, so a reversal and a
            // new instalment against the same balance cannot interleave.
            $parent = null;
            if ($payment->requisition_id) {
                $parent = PettyCashRequisition::query()->lockForUpdate()->find($payment->requisition_id);
                if ($parent && in_array($parent->status, ['surrender_pending', 'surrender_returned', 'surrendered'], true)) {
                    throw new InvalidArgumentException(
                        "Requisition {$parent->requisition_number} has been accounted for. Reverse its surrender before reversing the payment that funded it."
                    );
                }
            }
            if ($parent && $payment->requisition_child_reference) {
                // Report 75R-B: money already accounted for cannot be un-paid from
                // underneath its account. The surrender is unwound first, in order.
                $accounted = PettyCashSurrender::query()
                    ->whereIn('status', PettyCashSurrender::LIVE)
                    ->whereHas('allocations', fn ($q) => $q->where('payment_id', $payment->id))
                    ->pluck('reference');
                if ($accounted->isNotEmpty()) {
                    throw new InvalidArgumentException(
                        "{$payment->requisition_child_reference} has been accounted for in ".$accounted->implode(', ')
                        .'. Reverse or return that surrender before reversing the payment that funded it.'
                    );
                }
                if ($parent->closed_at) {
                    throw new InvalidArgumentException("Requisition {$parent->requisition_number} is closed. Its payments can no longer be reversed.");
                }
            }
            $payment = Payment::query()->with(['paymentSource', 'spendVoucher'])
                ->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== 'active') {
                throw new InvalidArgumentException("Payment {$payment->payment_no} is already voided or inactive.");
            }

            // A payroll payment's journal is sourced to its payroll run, not to this
            // Payment, and the run itself says "paid". Voiding the Payment here would
            // leave the ledger showing net payroll settled and the run paid while the
            // cash record says nothing left (Report 67). Payroll reversal is an open
            // policy decision, so it is refused rather than half-done.
            if ($payment->source_document_type === \App\Modules\HR\Models\PayrollRun::class) {
                throw new InvalidArgumentException(
                    "Payment {$payment->payment_no} settled a payroll run. A payroll payment is not reversed here: "
                    .'its reversal needs a controlled payroll reversal, which WNG has not yet decided.'
                );
            }

            if (DB::table('finance_statement_matches')->where('payment_id', $payment->id)->exists()) {
                throw new InvalidArgumentException(
                    "Payment {$payment->payment_no} is reconciled. Unmatch it from the statement before reversing it."
                );
            }

            $billPaymentIds = BillPayment::query()
                ->where('disbursement_id', $payment->id)
                ->pluck('id');

            $entries = JournalEntry::query()
                ->where('status', 'posted')
                // A reversing entry carries its original's source and is itself
                // `posted`. If part of this payment was already backed out — a
                // fee reversed ahead of the rest — picking that entry up here
                // would ask the ledger to reverse a reversal, and the refusal
                // would block the whole payment reversal.
                ->whereNull('reversal_of_id')
                ->where(function ($query) use ($payment, $billPaymentIds): void {
                    $query->where(function ($paymentEntry) use ($payment): void {
                        $paymentEntry->where('source_type', Payment::class)
                            ->where('source_id', $payment->id);
                    });

                    if ($payment->spend_voucher_id) {
                        $query->orWhere('spend_voucher_id', $payment->spend_voucher_id);
                    }

                    if ($billPaymentIds->isNotEmpty()) {
                        $query->orWhere(function ($billEntry) use ($billPaymentIds): void {
                            $billEntry->where('source_type', BillPayment::class)
                                ->whereIn('source_id', $billPaymentIds);
                        });
                    }
                })
                ->lockForUpdate()
                ->get();

            foreach ($entries as $entry) {
                $this->journals->reverseEntry($entry, $actorId, "Payment {$payment->payment_no} reversed: {$reason}");
            }

            $this->reverseDirectCost($payment, $actorId, $reason);

            $payment->void($actorId, $reason);
            if ($parent && $payment->requisition_child_reference) {
                $this->reopenReceiverBalance($parent, $payment, $actorId, $reason);
            }

            // A voided payment settled nothing: a liability it had completed is
            // payable again, so the fully-settled marker must not outlive it.
            $settledLineIds = \App\Modules\Finance\Models\PaymentAllocation::query()
                ->where('payment_id', $payment->id)->pluck('cost_line_id');
            foreach (\App\Modules\Finance\CostCollector\Models\CostLine::query()->whereKey($settledLineIds)->lockForUpdate()->get() as $line) {
                SpendVoucherSettlementService::syncSettlementMarker($line);
            }

            if ($payment->spendVoucher) {
                $payment->spendVoucher->forceFill(['status' => 'reversed'])->save();
            }

            foreach (BillPayment::query()->with('bill')->whereIn('id', $billPaymentIds)->get() as $allocation) {
                $allocation->bill?->updatePaymentStatus();
            }

            $cashbookDebitExists = DB::table('petty_cash_ledger_entries')
                ->where('source_type', 'disbursement')
                ->where('source_id', $payment->id)
                ->where('type', 'debit')
                ->exists();
            $cashbookCreditExists = DB::table('petty_cash_ledger_entries')
                ->where('reference_number', $this->cashbookReference($payment))
                ->exists();

            if ($payment->paymentSource?->type === 'petty_cash' && $cashbookDebitExists && ! $cashbookCreditExists) {
                $amount = bcadd((string) $payment->amount, (string) ($payment->transaction_cost ?? '0'), 2);
                $entry = LedgerEntry::custom($this->cashbookReference($payment), 'credit', $amount, [
                    'reverses' => 'PCR-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
                    'reason' => $reason,
                    'reversed_by' => $actorId,
                ]);
                $entry->sourceType = 'disbursement';
                $entry->sourceId = $payment->id;
                app(LedgerService::class)->post($entry);
            }

            PettyCashActivityLog::query()->create([
                'user_id' => $actorId,
                'action' => 'voided',
                'transaction_type' => 'disbursement',
                'transaction_id' => $payment->id,
                'description' => "Payment reversed: {$reason}",
            ]);

            return $payment->fresh(['paymentSource', 'spendVoucher', 'billPayment']);
        });
    }

    /**
     * Report 75R-A: a reversed receiver payment stops counting the moment it is
     * voided — paid and outstanding are summed from active Payments, so nothing
     * needs recalculating. The Payment and its line allocations are kept as they
     * were; only the parent's workflow stage is brought back in step, so that a
     * requisition with no money out is not left reading "disbursed".
     */
    private function reopenReceiverBalance(PettyCashRequisition $parent, Payment $payment, int $actorId, string $reason): void
    {
        // Report 75R-B: money that came back was not received. The confirmation is
        // kept as a record of what was said, and marked as no longer standing.
        $confirmations = RequisitionReceiptConfirmation::query()->where('payment_id', $payment->id)->whereNull('invalidated_at')->get();
        foreach ($confirmations as $confirmation) {
            $confirmation->forceFill(['invalidated_at' => now(), 'invalidated_reason' => "Payment reversed: {$reason}"])->save();
        }

        $stillPaid = Payment::query()->where('requisition_id', $parent->id)->where('status', 'active')->exists();
        $before = ['status' => $parent->status, 'received_at' => $parent->received_at?->toIso8601String()];

        if (in_array($parent->status, ['disbursed', 'received'], true)) {
            // A receipt confirmation covered money that has now come back.
            $parent->forceFill(['status' => $stillPaid ? 'disbursed' : 'approved', 'received_at' => null])->save();
        }

        GovernanceAuditLog::query()->create([
            'project_enquiry_id' => $parent->enquiry_id,
            'user_id' => $actorId,
            'gate_type' => 'requisition_payment_reversed',
            'action_status' => 'recorded',
            'model_type' => PettyCashRequisition::class,
            'model_id' => $parent->id,
            'message' => "{$payment->requisition_child_reference}: KES {$payment->amount} to {$payment->payee_name} reversed ({$payment->payment_no}) — {$reason}",
            'context' => [
                'payment_id' => $payment->id,
                'payment_no' => $payment->payment_no,
                'child_reference' => $payment->requisition_child_reference,
                'amount' => (string) $payment->amount,
                'reason' => $reason,
                'parent_before' => $before,
                'receipt_confirmations_invalidated' => $confirmations->pluck('id')->all(),
            ],
        ]);
    }

    /**
     * The cost lines this payment IS the economic event for.
     *
     * Read from the two source links and nothing else — never from a
     * description or a job number:
     *
     *  - a cost line whose source document is this payment: a direct
     *    petty-cash payment charged to a job (PettyCashCostProducer::postFor);
     *  - the cost line this payment names as its own source document: a
     *    company-paid cost settled at verification
     *    (CostVerificationService::settleCompanyPaid).
     *
     * A supplier settlement, a requisition advance, a voucher payment and a
     * payroll payment have neither link, so nothing is invented for them: the
     * goods, the surrender, the voucher's liabilities and the payroll run own
     * their costs.
     *
     * @return \Illuminate\Support\Collection<int, CostLine>
     */
    public static function directCostLines(Payment $payment): \Illuminate\Support\Collection
    {
        return CostLine::query()
            ->where(function ($query) use ($payment): void {
                $query->where(function ($sourced) use ($payment): void {
                    $sourced->where('source_type', Payment::class)->where('source_id', $payment->id);
                });
                if ($payment->source_document_type === CostLine::class && $payment->source_document_id) {
                    $query->orWhere('id', $payment->source_document_id);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Back the payment's own project cost out with it, in the same transaction.
     *
     * Not best-effort: if the cost cannot be reversed — a closed period, a
     * journal that will not reverse — the whole reversal is refused, because a
     * voided payment with its cost still standing is the defect this exists to
     * close. Already-reversed lines are skipped, so a replay changes nothing.
     */
    private function reverseDirectCost(Payment $payment, int $actorId, string $reason): void
    {
        $lines = self::directCostLines($payment)
            ->filter(fn (CostLine $line) => $line->status === CostLine::STATUS_VERIFIED);

        if ($lines->isEmpty()) {
            return;
        }

        $actor = User::find($actorId);
        if (! $actor) {
            throw new InvalidArgumentException(
                "Payment {$payment->payment_no} carries a project cost, and reversing a cost needs an identified user."
            );
        }

        foreach ($lines as $line) {
            try {
                app(CostVerificationService::class)->reverse(
                    $line, $actor, "Payment {$payment->payment_no} reversed: {$reason}", viaPayment: true,
                );
            } catch (CostValidationException $exception) {
                throw new InvalidArgumentException(
                    "Payment {$payment->payment_no} was not reversed: its project cost {$line->ref} could not be reversed. "
                    .$exception->getMessage(),
                    previous: $exception,
                );
            }
        }
    }

    private function cashbookReference(Payment $payment): string
    {
        return 'PCR-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT).'-VOID';
    }
}
