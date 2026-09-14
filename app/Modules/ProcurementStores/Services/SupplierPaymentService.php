<?php

namespace App\Modules\ProcurementStores\Services;

use App\Events\PettyCashDisbursementPaid;
use App\Modules\Finance\Services\PaymentSettlementService;
use App\Modules\Finance\Support\DocumentNumber;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paying a supplier: one movement of money, one or more invoices settled.
 *
 * The unit here is the transfer, not the invoice. A single M-Pesa payment
 * clearing three invoices is one `Payment` with three `BillPayment` allocations
 * hanging off it — the same shape petty cash already uses for a disbursement
 * split across top-ups. It used to be three `Payment` rows for one transfer,
 * which left the transaction fee with nowhere to sit: one real charge, three
 * documents, and no honest way to divide it.
 */
class SupplierPaymentService
{
    public function __construct(private readonly PaymentSettlementService $payments) {}

    /**
     * @param  array<int, array{bill: Bill, amount: string|float}>  $allocations
     *                                                                            what this one movement settles, and how much against each
     * @return array<int, BillPayment>
     */
    public function recordBatch(array $allocations, array $data): array
    {
        if ($allocations === []) {
            throw ValidationException::withMessages(['bill_ids' => 'Name at least one invoice to pay.']);
        }

        $result = DB::transaction(function () use ($allocations, $data) {
            $guard = app(SupplierPaymentGuard::class);
            $locked = [];
            $total = '0.00';

            foreach ($allocations as $allocation) {
                $bill = Bill::whereKey($allocation['bill']->id)->lockForUpdate()->firstOrFail();
                $amount = number_format((float) $allocation['amount'], 2, '.', '');
                $guard->assertPayable($bill, $amount);

                $locked[] = ['bill' => $bill, 'amount' => $amount];
                $total = bcadd($total, $amount, 2);
            }

            // What the provider charged us to move it. Carried on the payment,
            // never folded into what the supplier was credited — the supplier
            // received the invoice amount, not the fee.
            $fee = number_format((float) ($data['transaction_cost'] ?? 0), 2, '.', '');
            $paymentCode = $data['payment_code'] ?? DocumentNumber::next(
                DocumentNumber::PAYMENT,
                (string) Carbon::parse($data['payment_date'])->year,
            );

            // Project identity is read from the first invoice's order. A batch
            // spanning jobs carries the first one's, which is why the fee is
            // never charged to it: see JournalPostingService::postPaymentFee().
            $requisition = $locked[0]['bill']->purchaseOrder?->requisition;

            $disbursement = $this->payments->settle([
                'payment_no' => $paymentCode,
                'payment_type' => 'direct',
                'payment_source_id' => $data['payment_source_id'] ?? null,
                'payee_name' => $locked[0]['bill']->supplier->supplier_name,
                'payee_type' => 'supplier',
                'payee_id' => $locked[0]['bill']->supplier_id,
                'account' => 'Supplier invoice payment',
                'amount' => $total,
                'transaction_cost' => $fee,
                'description' => $this->describe($locked),
                'date_disbursed' => $data['payment_date'],
                'external_reference' => $data['reference_number'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'classification' => $requisition?->project_id ? 'operations' : 'admin',
                'project_id' => $requisition?->project_id,
                'project_enquiry_id' => $requisition?->project_enquiry_id,
                'job_number' => $requisition?->job_number,
                'tax' => 'no_etr',
                'receipt_type' => 'none',
                'created_by' => $data['user_id'],
            ], 'amount_paid');

            $method = $disbursement->payment_method;
            $sourceId = $disbursement->payment_source_id;

            $payments = [];
            foreach ($locked as $index => $entry) {
                // Listed field by field rather than merged from $data: the
                // caller's array now carries a transaction_cost that belongs to
                // the movement and must never reach the invoice, and relying on
                // $fillable to drop it silently is not a control.
                $payments[] = BillPayment::create([
                    // One movement, so one number — suffixed per invoice only
                    // when it settles more than one, which keeps every existing
                    // single-invoice payment code exactly as it was.
                    'payment_code' => count($locked) === 1 ? $paymentCode : $paymentCode.'/'.($index + 1),
                    'bill_id' => $entry['bill']->id,
                    'amount_paid' => $entry['amount'],
                    'payment_date' => $data['payment_date'],
                    'payment_method' => $method,
                    'payment_source_id' => $sourceId,
                    'disbursement_id' => $disbursement->id,
                    'reference_number' => $data['reference_number'] ?? null,
                    'user_id' => $data['user_id'] ?? null,
                ]);

                $entry['bill']->updatePaymentStatus();
            }

            return ['payments' => $payments, 'disbursement_id' => $disbursement->id];
        });

        // After the transaction, never inside it: the fee journal must not exist
        // for a payment that rolled back. The listener is queued, and its
        // producer skips the goods — they were costed when Stores accepted them
        // — so what this records is the transfer charge alone.
        DB::afterCommit(fn () => PettyCashDisbursementPaid::dispatch($result['disbursement_id']));

        return $result['payments'];
    }

    /** The single-invoice case, which is most of them. */
    public function record(Bill $bill, array $data): BillPayment
    {
        $payments = $this->recordBatch(
            [['bill' => $bill, 'amount' => $data['amount_paid']]],
            $data,
        );

        return $payments[0];
    }

    /** @param array<int, array{bill: Bill, amount: string}> $locked */
    private function describe(array $locked): string
    {
        $numbers = array_map(fn ($entry) => $entry['bill']->bill_number, $locked);

        return count($numbers) === 1
            ? "Payment for invoice {$numbers[0]}"
            : 'Payment for invoices '.implode(', ', $numbers);
    }
}
