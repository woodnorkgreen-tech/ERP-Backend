<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\Support\DocumentNumber;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Turns an approved spend voucher into the unified cash document.
 *
 * Architecture:
 *   SpendVoucher  = segregation-of-duties workflow (request → approve → post)
 *   Payment       = cash custody fact (what left which paying account)
 *   Journal       = tax/audit subledger (AP relief / advance / etc.)
 *
 * Paying is not costing. This service never writes cost_lines. The journal for
 * liability settlement is posted by JournalPostingService::postSpendVoucher.
 * PettyCashDisbursementPaid is deliberately not fired — that producer would
 * treat the cash movement as a new project cost.
 */
class SpendVoucherSettlementService
{
    /** Voucher types that move cash out of a paying account. */
    private const CASH_OUT_TYPES = ['payment', 'reimbursement', 'advance', 'refund'];

    public function __construct(private readonly PaymentSettlementService $payments) {}

    public function settle(SpendVoucher $voucher, int $actorId): ?Payment
    {
        if (! in_array($voucher->type, self::CASH_OUT_TYPES, true)) {
            return null;
        }

        if ($voucher->petty_cash_disbursement_id) {
            return Payment::query()->find($voucher->petty_cash_disbursement_id);
        }

        $existing = Payment::query()->where('spend_voucher_id', $voucher->id)->first();
        if ($existing) {
            return $existing;
        }

        $amount = number_format((float) ($voucher->net_cash_paid ?? $voucher->total_amount), 2, '.', '');
        if (bccomp($amount, '0.00', 2) !== 1) {
            throw ValidationException::withMessages([
                'total_amount' => 'A cash voucher must have a positive amount to settle.',
            ]);
        }

        $paymentNo = DocumentNumber::next(
            DocumentNumber::PAYMENT,
            (string) Carbon::parse($voucher->posting_date ?? now())->year,
        );

        $payment = $this->payments->settle([
            'payment_no' => $paymentNo,
            'payment_type' => $this->paymentTypeFor($voucher->type),
            'payment_source_id' => $voucher->payment_source_id,
            'spend_voucher_id' => $voucher->id,
            'payee_name' => $voucher->payee_name,
            'payee_type' => $voucher->supplier_id ? 'supplier' : 'other',
            'payee_id' => $voucher->supplier_id,
            'account' => 'Spend voucher settlement',
            'amount' => $amount,
            'transaction_cost' => $voucher->transaction_cost ?? '0.00',
            'description' => $this->describe($voucher),
            'date_disbursed' => $voucher->posting_date ?? now()->toDateString(),
            'external_reference' => $voucher->payment_reference,
            'payment_method' => $voucher->payment_method,
            'classification' => 'operations',
            'tax' => 'no_etr',
            'receipt_type' => 'none',
            'created_by' => $actorId,
            'idempotency_key' => 'spend-voucher:'.$voucher->id,
        ], 'total_amount');

        $voucher->forceFill([
            'petty_cash_disbursement_id' => $payment->id,
        ])->save();

        // Mirror the voucher allocations on the cash document. This is the
        // direct, durable settlement link used by duplicate-payment checks;
        // updateOrCreate also makes a retry harmless.
        foreach ($voucher->allocations()->get() as $allocation) {
            PaymentAllocation::query()->updateOrCreate(
                [
                    'payment_id' => $payment->id,
                    'cost_line_id' => $allocation->cost_line_id,
                ],
                [
                    'amount' => $allocation->amount,
                    'allocation_type' => 'settlement',
                ],
            );

            // The guard is on amounts, not on "some payment touched this line":
            // a liability may be settled in instalments by several vouchers
            // (W4 partial settlement), but never beyond what it is payable for.
            // Found in Wave 3: the old single-marker check refused every second
            // partial payment at posting, and — since reversal never cleared the
            // marker — every re-payment of a reversed one.
            $line = \App\Modules\Finance\CostCollector\Models\CostLine::query()
                ->lockForUpdate()->findOrFail($allocation->cost_line_id);
            $payable = self::payableAmount($line);
            $settled = self::settledAmount($line->id);

            if (bccomp($settled, $payable, 2) === 1) {
                throw ValidationException::withMessages([
                    'allocations' => "Liability {$line->ref} would be paid KES {$settled} against a payable amount of KES {$payable}.",
                ]);
            }

            self::syncSettlementMarker($line, $payment->id);
        }

        return $payment;
    }

    /** What the liability is payable for: net + VAT − withholding. */
    public static function payableAmount(\App\Modules\Finance\CostCollector\Models\CostLine $line): string
    {
        return bcsub(
            bcadd((string) ($line->net_amount ?? 0), (string) ($line->tax_amount ?? 0), 2),
            (string) ($line->wht_amount ?? 0),
            2
        );
    }

    /** Paid so far by payments that are still active (a voided one paid nothing). */
    public static function settledAmount(int $costLineId): string
    {
        return number_format((float) PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.cost_line_id', $costLineId)
            ->where('payments.status', 'active')
            ->sum('payment_allocations.amount'), 2, '.', '');
    }

    /**
     * settled_by_payment_id means FULLY settled, naming the payment that
     * completed it. Cleared again when a reversal leaves the line short.
     */
    public static function syncSettlementMarker(\App\Modules\Finance\CostCollector\Models\CostLine $line, ?int $completingPaymentId = null): void
    {
        $full = bccomp(self::settledAmount($line->id), self::payableAmount($line), 2) >= 0;

        if ($full && ! $line->settled_by_payment_id && $completingPaymentId) {
            $line->forceFill(['settled_by_payment_id' => $completingPaymentId])->save();
        } elseif (! $full && $line->settled_by_payment_id) {
            $line->forceFill(['settled_by_payment_id' => null])->save();
        }
    }

    private function paymentTypeFor(string $voucherType): string
    {
        return match ($voucherType) {
            'advance' => 'advance',
            'refund' => 'refund',
            default => 'direct',
        };
    }

    private function describe(SpendVoucher $voucher): string
    {
        return match ($voucher->type) {
            'reimbursement' => "Reimbursement voucher {$voucher->voucher_no}",
            'advance' => "Staff advance voucher {$voucher->voucher_no}",
            'refund' => "Refund voucher {$voucher->voucher_no}",
            default => "Payment voucher {$voucher->voucher_no}",
        };
    }
}
