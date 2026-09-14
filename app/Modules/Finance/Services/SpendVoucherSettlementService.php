<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Payment;
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
            'transaction_cost' => '0.00',
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

        return $payment;
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
