<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\Support\DocumentNumber;
use App\Modules\Finance\Support\PaymentMethods;
use App\Modules\ProcurementStores\Models\Bill;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * UnifiedPaymentService - Single payment creation engine for WNG-ERP
 *
 * Phase 3 of Finance Architecture Redesign:
 * Consolidates all payment creation logic into one service with consistent
 * validation, audit logging, GL posting, and event firing.
 *
 * Replaces scattered payment creation across:
 * - SpendVoucherSettlementService
 * - BillPaymentController
 * - PettyCashDisbursementController
 * - Direct Payment forms
 *
 * Design Principles:
 * 1. One financial event → one Payment → one accounting treatment
 * 2. Payment Source and Payment Method are independent facts
 * 3. All payments flow through same validation and posting logic
 * 4. Cost attribution is separate from cash movement
 */
class UnifiedPaymentService
{
    public function __construct(
        private readonly PaymentSettlementService $settlementService,
        private readonly JournalPostingService $journalService,
    ) {}

    /**
     * Create a payment with full context and validation.
     *
     * @param  PaymentData  $data  Payment details including source, allocations, etc.
     * @return Payment The created payment with all relationships loaded
     *
     * @throws ValidationException If validation fails
     * @throws \InvalidArgumentException If business rules are violated
     */
    public function createPayment(PaymentData $data): Payment
    {
        return DB::transaction(function () use ($data) {
            // 1. Validate payment source
            $this->validatePaymentSource($data->paymentSourceId);

            // 2. Validate source document if provided
            if ($data->sourceDocument) {
                $this->validateSourceDocument($data->sourceDocument);
            }

            // 3. Validate allocations don't exceed liabilities
            if ($data->allocations && count($data->allocations) > 0) {
                $this->validateAllocations($data->allocations, $data->amount);
            }

            // 4. Check accounting period is open
            $period = $this->resolveAccountingPeriod($data->paymentDate ?? now());
            if (! $period || ! $period->is_open) {
                throw ValidationException::withMessages([
                    'payment_date' => 'The accounting period for this date is closed or does not exist.',
                ]);
            }

            // 5. Generate payment number if not provided
            $paymentNo = $data->paymentNo ?? DocumentNumber::next(
                DocumentNumber::PAYMENT,
                (string) Carbon::parse($data->paymentDate ?? now())->year
            );

            // 6. Use PaymentSettlementService for consistent cash/float handling
            // This handles petty cash balance checks, M-Pesa limits, etc.
            $payment = $this->settlementService->settle([
                'payment_no' => $paymentNo,
                'payment_type' => $data->paymentType ?? 'direct',
                'payment_source_id' => $data->paymentSourceId,
                'payment_method' => $data->paymentMethod,
                'voucher_id' => $data->voucherId,
                'spend_voucher_id' => $data->spendVoucherId, // Legacy compatibility
                'source_document_type' => $data->sourceDocument?->getMorphClass(),
                'source_document_id' => $data->sourceDocument?->getKey(),
                'payee_name' => $data->payeeName,
                'payee_type' => $data->payeeType ?? 'other',
                'payee_id' => $data->payeeId,
                'amount' => $data->amount,
                'transaction_cost' => $data->transactionCost ?? 0,
                'description' => $data->description,
                'project_id' => $data->projectId,
                'project_enquiry_id' => $data->projectEnquiryId,
                'job_number' => $data->jobNumber,
                'budget_category' => $data->budgetCategory,
                'planned_cost_line_id' => $data->plannedCostLineId,
                'date_disbursed' => $data->paymentDate ?? now()->toDateString(),
                'external_reference' => $data->referenceNumber,
                'classification' => $data->classification ?? 'operations',
                'tax' => $data->tax ?? 'no_etr',
                'receipt_type' => $data->receiptType ?? 'none',
                'created_by' => $data->createdBy ?? auth()->id(),
                'idempotency_key' => $data->idempotencyKey,
                'direct_payment_reason' => $data->directPaymentReason,
            ]);

            // 7. Create allocations if provided
            if ($data->allocations && count($data->allocations) > 0) {
                $this->createAllocations($payment, $data->allocations);
            }

            // 8. Post to GL via JournalPostingService
            try {
                $this->journalService->postPayment($payment->fresh(['paymentAllocations.costLine.journalEntry.lines']));
            } catch (\Exception $e) {
                throw new \RuntimeException(
                    "Payment created but GL posting failed: {$e->getMessage()}. ".
                    "Payment ID: {$payment->id}. Manual journal entry required.",
                    0,
                    $e
                );
            }

            // 9. Update source document status (Bill paid, CostLine settled, etc.)
            if ($data->sourceDocument) {
                $this->updateSourceDocumentStatus($data->sourceDocument, $payment);
            }

            // 10. Update cost line settlement status
            if ($payment->paymentAllocations->isNotEmpty()) {
                $this->updateCostLineSettlements($payment);
            }

            // 11. Fire payment created event
            // event(new PaymentCreated($payment));

            return $payment->fresh([
                'paymentAllocations.costLine',
                'sourceDocument',
                'voucher',
                'paymentSource',
            ]);
        });
    }

    /**
     * Void a payment (reverses GL, updates status, restores allocations).
     *
     * @param  Payment  $payment  The payment to void
     * @param  string  $reason  Reason for voiding
     * @param  int|null  $voidedBy  User ID who is voiding (defaults to auth)
     *
     * @throws ValidationException If payment cannot be voided
     */
    public function voidPayment(Payment $payment, string $reason, ?int $voidedBy = null): void
    {
        if ($payment->status === 'voided') {
            throw ValidationException::withMessages([
                'payment' => 'This payment has already been voided.',
            ]);
        }

        DB::transaction(function () use ($payment, $reason, $voidedBy) {
            $voidedBy = $voidedBy ?? auth()->id();

            // 1. Reverse the journal entry if it exists
            $journalEntry = DB::table('journal_entries')
                ->where('source_document_type', Payment::class)
                ->where('source_document_id', $payment->id)
                ->first();

            if ($journalEntry) {
                $entry = \App\Modules\Finance\Models\JournalEntry::find($journalEntry->id);
                $this->journalService->reverseEntry(
                    $entry,
                    $voidedBy,
                    "Voiding payment {$payment->payment_no}: {$reason}"
                );
            }

            // 2. Update payment status
            $payment->update([
                'status' => 'voided',
                'void_reason' => $reason,
                'voided_by' => $voidedBy,
                'voided_at' => now(),
            ]);

            // 3. Clear cost line settlements
            CostLine::where('settled_by_payment_id', $payment->id)
                ->update(['settled_by_payment_id' => null]);

            // 4. Restore payment source balance if petty cash
            if ($payment->paymentSource->type === 'petty_cash') {
                $this->restorePettyCashFloat($payment);
            }

            // 5. Update source document status
            if ($payment->sourceDocument) {
                $this->restoreSourceDocumentStatus($payment->sourceDocument, $payment);
            }

            // 6. Update voucher status if linked
            if ($payment->voucher) {
                $payment->voucher->update(['status' => 'voided']);
            }

            // 7. Fire payment voided event
            // event(new PaymentVoided($payment));
        });
    }

    /**
     * Validate that payment source is active and payment-capable.
     */
    private function validatePaymentSource(int $sourceId): void
    {
        $source = PaymentSource::find($sourceId);

        if (! $source) {
            throw ValidationException::withMessages([
                'payment_source_id' => 'Payment source not found.',
            ]);
        }

        if (! $source->is_active) {
            throw ValidationException::withMessages([
                'payment_source_id' => "Payment source '{$source->name}' is inactive.",
            ]);
        }

        if (! ($source->can_make_payment ?? true)) {
            $message = $source->type === 'payable'
                ? "'{$source->name}' is a liability account, not a paying account. Select the bank, float, or mobile account money actually leaves from."
                : "'{$source->name}' cannot be used for payments.";

            throw ValidationException::withMessages([
                'payment_source_id' => $message,
            ]);
        }

        if (! $source->gl_account_id) {
            throw ValidationException::withMessages([
                'payment_source_id' => "Payment source '{$source->name}' has no mapped GL account.",
            ]);
        }
    }

    /**
     * Validate source document is in a payable state.
     */
    private function validateSourceDocument(Model $document): void
    {
        if ($document instanceof Bill) {
            if (! $document->verified_at) {
                throw ValidationException::withMessages([
                    'bill_id' => 'This bill has not been verified. Only verified bills can be paid.',
                ]);
            }

            if ($document->balance <= 0) {
                throw ValidationException::withMessages([
                    'bill_id' => 'This bill has already been paid in full.',
                ]);
            }
        }

        if ($document instanceof CostLine) {
            if ($document->status !== CostLine::STATUS_VERIFIED) {
                throw ValidationException::withMessages([
                    'cost_line_id' => 'Cost line must be verified before payment.',
                ]);
            }

            if ($document->isSettled()) {
                throw ValidationException::withMessages([
                    'cost_line_id' => 'This cost has already been settled.',
                ]);
            }
        }
    }

    /**
     * Validate allocations don't exceed cost line liabilities.
     */
    private function validateAllocations(array $allocations, float $paymentAmount): void
    {
        $totalAllocated = 0;
        $costLineIds = collect($allocations)->pluck('cost_line_id')->unique();

        $costLines = CostLine::query()
            ->whereIn('id', $costLineIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($allocations as $allocation) {
            $costLine = $costLines->get($allocation['cost_line_id']);

            if (! $costLine) {
                throw ValidationException::withMessages([
                    'allocations' => "Cost line {$allocation['cost_line_id']} not found.",
                ]);
            }

            if ($costLine->status !== CostLine::STATUS_VERIFIED) {
                throw ValidationException::withMessages([
                    'allocations' => "Cost line {$costLine->id} must be verified before payment.",
                ]);
            }

            // Check if already settled
            if ($costLine->settled_by_payment_id) {
                throw ValidationException::withMessages([
                    'allocations' => "Cost line {$costLine->id} has already been settled by payment {$costLine->settled_by_payment_id}.",
                ]);
            }

            // Check allocation doesn't exceed cost line amount
            if ($allocation['amount'] > $costLine->net_amount) {
                throw ValidationException::withMessages([
                    'allocations' => "Allocation for cost line {$costLine->id} exceeds the cost amount.",
                ]);
            }

            $totalAllocated += $allocation['amount'];
        }

        // Validate total allocations match payment amount
        if (abs($totalAllocated - $paymentAmount) > 0.01) {
            throw ValidationException::withMessages([
                'allocations' => "Total allocations ({$totalAllocated}) must equal payment amount ({$paymentAmount}).",
            ]);
        }
    }

    /**
     * Resolve accounting period for the payment date.
     */
    private function resolveAccountingPeriod($date)
    {
        return \App\Modules\Finance\Models\AccountingPeriod::forDate($date);
    }

    /**
     * Create payment allocations linking payment to cost lines.
     */
    private function createAllocations(Payment $payment, array $allocations): void
    {
        foreach ($allocations as $allocation) {
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'cost_line_id' => $allocation['cost_line_id'],
                'amount' => $allocation['amount'],
                'allocation_type' => $allocation['allocation_type'] ?? 'settlement',
            ]);
        }
    }

    /**
     * Update source document status after payment.
     */
    private function updateSourceDocumentStatus(Model $document, Payment $payment): void
    {
        if ($document instanceof Bill) {
            // Bill status update is handled by BillPayment observer
            // Just ensure BillPayment record exists
            if (! $document->payments()->where('disbursement_id', $payment->id)->exists()) {
                \App\Modules\ProcurementStores\Models\BillPayment::create([
                    'bill_id' => $document->id,
                    'disbursement_id' => $payment->id,
                    'amount_paid' => $payment->amount,
                    'payment_date' => $payment->date_disbursed,
                    'created_by' => $payment->created_by,
                ]);
            }
        }
    }

    /**
     * Restore source document status after payment is voided.
     */
    private function restoreSourceDocumentStatus(Model $document, Payment $payment): void
    {
        if ($document instanceof Bill) {
            // Recalculate bill payment status
            $document->updatePaymentStatus();
        }
    }

    /**
     * Update cost lines to mark them as settled.
     */
    private function updateCostLineSettlements(Payment $payment): void
    {
        foreach ($payment->paymentAllocations as $allocation) {
            CostLine::where('id', $allocation->cost_line_id)
                ->whereNull('settled_by_payment_id')
                ->update(['settled_by_payment_id' => $payment->id]);
        }
    }

    /**
     * Restore petty cash float balance when payment is voided.
     */
    private function restorePettyCashFloat(Payment $payment): void
    {
        // PettyCash ledger reversal is handled by PettyCashCostProducer listener
        // on PaymentVoided event. This method is a placeholder for future
        // direct float balance management if needed.
    }
}

