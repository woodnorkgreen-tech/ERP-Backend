<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursementAllocation;
use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use App\Modules\Finance\PettyCash\Services\LedgerEntry;
use App\Modules\Finance\PettyCash\Services\LedgerService;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use App\Modules\Finance\PettyCash\Services\TopUpAllocator;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillPayment;
use App\Modules\ProcurementStores\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierPaymentService
{
    public function record(Bill $bill, array $data): BillPayment
    {
        return DB::transaction(function () use ($bill, $data) {
            // Share the balance lock with requisition payouts and cash reversals.
            $balance = PettyCashBalance::current();
            $balance = PettyCashBalance::whereKey($balance->id)->lockForUpdate()->firstOrFail();
            $bill = Bill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
            app(SupplierPaymentGuard::class)->assertPayable($bill, (string) $data['amount_paid']);

            $method = ! empty($data['payment_method_id']) ? PaymentMethod::settleable()->find($data['payment_method_id']) : null;
            $source = PaymentSource::where('is_active', true)->find($data['payment_source_id'] ?? $method?->payment_source_id);
            if (! $source || (! empty($data['payment_method_id']) && (! $method || $method->payment_source_id != $source->id))) {
                throw ValidationException::withMessages(['payment_source_id' => 'Select an active payment source matching the payment method.']);
            }
            $method ??= PaymentMethod::firstOrCreate(
                ['method_name' => $source->name, 'payment_source_id' => $source->id],
                ['is_active' => true],
            );
            $disbursement = null;
            if ($source->type === 'petty_cash') {
                if (bccomp((string) $balance->current_balance, (string) $data['amount_paid'], 2) < 0) {
                    throw ValidationException::withMessages(['amount_paid' => 'Insufficient petty cash balance.']);
                }
                try {
                    $allocations = (new TopUpAllocator(app(PettyCashRepository::class)))->plan((float) $data['amount_paid']);
                } catch (\Exception $e) {
                    throw ValidationException::withMessages(['amount_paid' => $e->getMessage()]);
                }
                $requisition = $bill->purchaseOrder?->requisition;
                $disbursement = PettyCashDisbursement::create([
                    'top_up_id' => $allocations[0]['top_up_id'],
                    'payment_source_id' => $source->id,
                    'receiver' => $bill->supplier->supplier_name,
                    'account' => 'Supplier invoice payment',
                    'amount' => $data['amount_paid'],
                    'transaction_cost' => 0,
                    'description' => "Payment for invoice {$bill->bill_number}",
                    'date_disbursed' => $data['payment_date'],
                    'transaction_code' => $data['reference_number'],
                    'payment_method' => 'cash',
                    'classification' => $requisition?->project_id ? 'operations' : 'admin',
                    'project_id' => $requisition?->project_id,
                    'project_enquiry_id' => $requisition?->project_enquiry_id,
                    'job_number' => $requisition?->job_number,
                    'status' => 'active',
                    'tax' => 'no_etr',
                    'receipt_type' => 'none',
                    'created_by' => $data['user_id'],
                ]);
                if (count($allocations) > 1) {
                    foreach ($allocations as $allocation) {
                        PettyCashDisbursementAllocation::create(['disbursement_id' => $disbursement->id] + $allocation);
                    }
                }
                app(LedgerService::class)->post(LedgerEntry::debitForDisbursement($disbursement));
                app(PettyCashService::class)->logActivity('created', 'disbursement', $disbursement->id, "Supplier payment for {$bill->bill_number}");
                // Procurement already records the invoice's expense. This is its
                // cash settlement, so do not emit a second expense-paid event.
            }

            return BillPayment::create(array_merge($data, [
                'bill_id' => $bill->id,
                'payment_method_id' => $method->id,
                'payment_source_id' => $source->id,
                'disbursement_id' => $disbursement?->id,
            ]));
        });
    }
}
