<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashActivityLog;
use App\Modules\Finance\PettyCash\Services\LedgerEntry;
use App\Modules\Finance\PettyCash\Services\LedgerService;
use App\Modules\ProcurementStores\Models\BillPayment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Reverses one cash event and every accounting document it produced. */
class PaymentReversalService
{
    public function __construct(private readonly JournalPostingService $journals) {}

    public function reverse(Payment $payment, int $actorId, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $actorId, $reason): Payment {
            $payment = Payment::query()->with(['paymentSource', 'spendVoucher'])
                ->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== 'active') {
                throw new InvalidArgumentException("Payment {$payment->payment_no} is already voided or inactive.");
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

            $payment->void($actorId, $reason);

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

    private function cashbookReference(Payment $payment): string
    {
        return 'PCR-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT).'-VOID';
    }
}
