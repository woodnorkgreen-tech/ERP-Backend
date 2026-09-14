<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursementAllocation;
use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use App\Modules\Finance\PettyCash\Services\LedgerEntry;
use App\Modules\Finance\PettyCash\Services\LedgerService;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use App\Modules\Finance\PettyCash\Services\TopUpAllocator;
use App\Modules\Finance\Support\DocumentNumber;
use App\Modules\Finance\Support\PaymentMethods;
use App\Modules\Finance\Support\PettyCashCap;
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

        $source = PaymentSource::query()->where('is_active', true)->find($voucher->payment_source_id);
        if (! $source) {
            throw ValidationException::withMessages([
                'payment_source_id' => 'Select an active paying account before posting this voucher.',
            ]);
        }

        // Supplier Credit (type payable) is the liability itself, not an account
        // money leaves from. The request-time validation in
        // SpendVoucherController already refuses it; this is the same rule
        // enforced again at the one place every cash-out voucher must pass
        // through, so a voucher written before that check existed — or by any
        // future caller that skips it — still cannot mint a Payment against a
        // liability account.
        if ($source->type === 'payable') {
            throw ValidationException::withMessages([
                'payment_source_id' => 'Supplier Credit is a liability account, not a paying account. Select the bank, float, mobile money or card the money actually left from.',
            ]);
        }

        $method = $voucher->payment_method;
        if ($method !== null && $method !== '' && ! in_array($method, PaymentMethods::values(), true)) {
            throw ValidationException::withMessages([
                'payment_method' => 'Select a valid payment method.',
            ]);
        }
        $method = $method ?: (PaymentMethods::TYPICAL_FOR_SOURCE_TYPE[$source->type][0] ?? 'bank_transfer');

        if ($source->type === 'petty_cash') {
            if (PettyCashCap::exceeds($amount)) {
                throw ValidationException::withMessages(['total_amount' => PettyCashCap::message($amount)]);
            }

            $balance = PettyCashBalance::current();
            $balance = PettyCashBalance::whereKey($balance->id)->lockForUpdate()->firstOrFail();
            if (bccomp((string) $balance->current_balance, $amount, 2) < 0) {
                throw ValidationException::withMessages([
                    'total_amount' => 'Insufficient petty cash balance for this voucher.',
                ]);
            }
        }

        $paymentNo = DocumentNumber::next(
            DocumentNumber::PAYMENT,
            (string) \Carbon\Carbon::parse($voucher->posting_date ?? now())->year,
        );

        $payment = Payment::create([
            'payment_no' => $paymentNo,
            'payment_type' => $this->paymentTypeFor($voucher->type),
            'payment_source_id' => $source->id,
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
            'payment_method' => $method,
            'classification' => 'operations',
            'status' => 'active',
            'tax' => 'no_etr',
            'receipt_type' => 'none',
            'created_by' => $actorId,
            'idempotency_key' => 'spend-voucher:'.$voucher->id,
        ]);

        if ($source->type === 'petty_cash') {
            try {
                $planned = (new TopUpAllocator(app(PettyCashRepository::class)))
                    ->plan((float) $amount, 0.0);
            } catch (\Exception $e) {
                throw ValidationException::withMessages(['total_amount' => $e->getMessage()]);
            }

            $payment->update(['top_up_id' => $planned[0]['top_up_id']]);
            if (count($planned) > 1) {
                foreach ($planned as $slice) {
                    PettyCashDisbursementAllocation::create(['disbursement_id' => $payment->id] + $slice);
                }
            }

            app(LedgerService::class)->post(LedgerEntry::debitForDisbursement($payment));
            app(PettyCashService::class)->logActivity(
                'created',
                'disbursement',
                $payment->id,
                $this->describe($voucher),
            );
        }

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
