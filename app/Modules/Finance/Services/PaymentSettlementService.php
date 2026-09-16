<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursementAllocation;
use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use App\Modules\Finance\PettyCash\Services\LedgerEntry;
use App\Modules\Finance\PettyCash\Services\LedgerService;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use App\Modules\Finance\PettyCash\Services\TopUpAllocator;
use App\Modules\Finance\Support\PaymentMethods;
use App\Modules\Finance\Support\PettyCashCap;
use Illuminate\Validation\ValidationException;

/**
 * The single cash-side settlement engine used by every outgoing payment rail.
 *
 * Callers remain responsible for their obligation allocations and GL posting;
 * this service owns the invariant that one real transfer creates one Payment
 * and, when it leaves petty cash, exactly one matching float debit.
 */
class PaymentSettlementService
{
    /**
     * @param  array<string, mixed>  $attributes  Payment attributes, including payment_source_id
     */
    public function settle(array $attributes, string $amountField = 'amount'): Payment
    {
        $sourceId = (int) ($attributes['payment_source_id'] ?? 0);
        $source = PaymentSource::query()->paymentCapable()->find($sourceId);

        if (! $source) {
            $configured = PaymentSource::query()->find($sourceId);
            $message = $configured?->type === 'payable'
                ? 'Supplier Credit is a liability account, not a paying account. Select the bank, float, mobile money or card the money actually left from.'
                : 'Select an active paying account with a mapped ledger account.';
            throw ValidationException::withMessages(['payment_source_id' => $message]);
        }

        $amount = number_format((float) ($attributes['amount'] ?? 0), 2, '.', '');
        $fee = number_format((float) ($attributes['transaction_cost'] ?? 0), 2, '.', '');
        if (bccomp($amount, '0.00', 2) !== 1 || bccomp($fee, '0.00', 2) < 0) {
            throw ValidationException::withMessages([
                $amountField => 'The payment amount must be positive and its transaction cost cannot be negative.',
            ]);
        }

        $method = $attributes['payment_method'] ?? null;
        if ($method !== null && $method !== '' && ! in_array($method, PaymentMethods::values(), true)) {
            throw ValidationException::withMessages(['payment_method' => 'Select a valid payment method.']);
        }
        $method = $method ?: (PaymentMethods::TYPICAL_FOR_SOURCE_TYPE[$source->type][0] ?? 'bank_transfer');
        $cashOut = bcadd($amount, $fee, 2);

        if ($source->type === 'petty_cash') {
            if (PettyCashCap::exceeds($cashOut)) {
                throw ValidationException::withMessages([$amountField => PettyCashCap::message($cashOut)]);
            }

            $balance = PettyCashBalance::current();
            $balance = PettyCashBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
            if (bccomp((string) $balance->current_balance, $cashOut, 2) < 0) {
                throw ValidationException::withMessages([
                    $amountField => 'Insufficient petty cash balance for this payment and its transaction fee.',
                ]);
            }
        }

        if (($key = $attributes['idempotency_key'] ?? null)
            && ($existing = Payment::query()->where('idempotency_key', $key)->first())) {
            return $existing;
        }

        $payment = Payment::query()->create([
            ...$attributes,
            'amount' => $amount,
            'transaction_cost' => $fee,
            'payment_method' => $method,
            'status' => 'active',
        ]);

        if ($source->type === 'petty_cash') {
            try {
                $planned = (new TopUpAllocator(app(PettyCashRepository::class)))
                    ->plan((float) $amount, (float) $fee);
            } catch (\Exception $exception) {
                throw ValidationException::withMessages([$amountField => $exception->getMessage()]);
            }

            $payment->update(['top_up_id' => $planned[0]['top_up_id']]);
            if (count($planned) > 1) {
                foreach ($planned as $slice) {
                    PettyCashDisbursementAllocation::query()->create([
                        'disbursement_id' => $payment->id,
                        ...$slice,
                    ]);
                }
            }

            app(LedgerService::class)->post(LedgerEntry::debitForDisbursement($payment));
            app(PettyCashService::class)->logActivity(
                'created',
                'disbursement',
                $payment->id,
                (string) $payment->description,
            );
        }

        return $payment;
    }
}
