<?php

namespace App\Modules\Finance\DataTransferObjects;

use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\ProcurementStores\Models\Bill;
use Illuminate\Database\Eloquent\Model;

/**
 * PaymentData - Data Transfer Object for payment creation
 *
 * Phase 3 of Finance Architecture Redesign:
 * Standardizes payment creation parameters across all payment flows.
 *
 * Usage:
 *   $payment = $unifiedPaymentService->createPayment(
 *       PaymentData::fromVoucher($voucher)
 *   );
 *
 *   $payment = $unifiedPaymentService->createPayment(
 *       PaymentData::fromBill($bill, $request->validated())
 *   );
 */
class PaymentData
{
    public function __construct(
        // Required
        public int $paymentSourceId,
        public float $amount,
        public string $payeeName,

        // Payment identification
        public ?string $paymentNo = null,
        public ?string $paymentType = null,
        public ?string $paymentMethod = null,
        public ?string $referenceNumber = null,

        // Relationships
        public ?int $voucherId = null,
        public ?int $spendVoucherId = null, // Legacy compatibility
        public ?Model $sourceDocument = null,

        // Payee details
        public ?string $payeeType = null,
        public ?int $payeeId = null,

        // Amounts and fees
        public float $transactionCost = 0,

        // Description and context
        public ?string $description = null,
        public ?string $classification = null,
        public ?string $directPaymentReason = null,

        // Project attribution
        public ?int $projectId = null,
        public ?int $projectEnquiryId = null,
        public ?string $jobNumber = null,
        public ?string $budgetCategory = null,
        public ?int $plannedCostLineId = null,

        // Timing
        public ?string $paymentDate = null,

        // Allocations
        public ?array $allocations = null,

        // Tax and compliance
        public ?string $tax = null,
        public ?string $receiptType = null,

        // Audit
        public ?int $createdBy = null,
        public ?string $idempotencyKey = null,
    ) {}

    /**
     * Create PaymentData from a SpendVoucher.
     */
    public static function fromVoucher(SpendVoucher $voucher): self
    {
        return new self(
            paymentSourceId: $voucher->payment_source_id,
            amount: (float) ($voucher->net_cash_paid ?? $voucher->total_amount),
            payeeName: $voucher->payee_name,
            paymentType: self::mapVoucherTypeToPaymentType($voucher->type),
            paymentMethod: $voucher->payment_method,
            referenceNumber: $voucher->payment_reference,
            voucherId: $voucher->id,
            spendVoucherId: $voucher->id, // Legacy compatibility
            payeeType: $voucher->supplier_id ? 'supplier' : 'other',
            payeeId: $voucher->supplier_id,
            description: self::describeVoucher($voucher),
            classification: 'operations',
            paymentDate: $voucher->posting_date?->toDateString(),
            allocations: self::extractVoucherAllocations($voucher),
            tax: 'no_etr',
            receiptType: 'none',
            createdBy: auth()->id(),
            idempotencyKey: "spend-voucher:{$voucher->id}",
        );
    }

    /**
     * Create PaymentData from a Bill payment request.
     */
    public static function fromBillPayment(Bill $bill, array $request): self
    {
        return new self(
            paymentSourceId: $request['payment_source_id'],
            amount: (float) $request['amount_paid'],
            payeeName: $bill->supplier->supplier_name ?? 'Unknown Supplier',
            paymentMethod: $request['payment_method'],
            referenceNumber: $request['reference_number'] ?? null,
            sourceDocument: $bill,
            payeeType: 'supplier',
            payeeId: $bill->supplier_id,
            transactionCost: (float) ($request['transaction_cost'] ?? 0),
            description: "Payment for invoice {$bill->bill_number}",
            classification: 'procurement',
            projectId: $bill->project_id,
            projectEnquiryId: $bill->project_enquiry_id,
            jobNumber: $bill->job_number,
            paymentDate: $request['payment_date'] ?? now()->toDateString(),
            tax: 'no_etr',
            receiptType: 'none',
            createdBy: $request['user_id'] ?? auth()->id(),
        );
    }

    /**
     * Create PaymentData from a PettyCash requisition.
     */
    public static function fromPettyCashRequisition(PettyCashRequisition $requisition): self
    {
        return new self(
            paymentSourceId: $requisition->payment_source_id ?? $requisition->petty_cash_source_id,
            amount: (float) $requisition->amount,
            payeeName: $requisition->requestedBy->name ?? 'Unknown',
            paymentMethod: $requisition->payment_method ?? 'cash',
            sourceDocument: $requisition,
            payeeType: 'staff',
            payeeId: $requisition->requested_by,
            description: $requisition->purpose ?? 'Petty cash disbursement',
            classification: $requisition->classification ?? 'operations',
            projectId: $requisition->project_id,
            projectEnquiryId: $requisition->project_enquiry_id,
            jobNumber: $requisition->job_number,
            paymentDate: now()->toDateString(),
            tax: $requisition->tax ?? 'no_etr',
            receiptType: $requisition->receipt_type ?? 'none',
            createdBy: auth()->id(),
            idempotencyKey: "petty-cash-req:{$requisition->id}",
        );
    }

    /**
     * Create PaymentData from direct payment request.
     */
    public static function fromRequest(array $request): self
    {
        return new self(
            paymentSourceId: $request['payment_source_id'],
            amount: (float) $request['amount'],
            payeeName: $request['payee_name'],
            paymentMethod: $request['payment_method'],
            referenceNumber: $request['reference_number'] ?? null,
            payeeType: $request['payee_type'] ?? 'other',
            payeeId: $request['payee_id'] ?? null,
            transactionCost: (float) ($request['transaction_cost'] ?? 0),
            description: $request['description'] ?? null,
            classification: $request['classification'] ?? 'operations',
            directPaymentReason: $request['direct_payment_reason'] ?? null,
            projectId: $request['project_id'] ?? null,
            projectEnquiryId: $request['project_enquiry_id'] ?? null,
            jobNumber: $request['job_number'] ?? null,
            budgetCategory: $request['budget_category'] ?? null,
            plannedCostLineId: $request['planned_cost_line_id'] ?? null,
            paymentDate: $request['payment_date'] ?? now()->toDateString(),
            tax: $request['tax'] ?? 'no_etr',
            receiptType: $request['receipt_type'] ?? 'none',
            createdBy: $request['created_by'] ?? auth()->id(),
        );
    }

    /**
     * Map voucher type to payment type.
     */
    private static function mapVoucherTypeToPaymentType(string $voucherType): string
    {
        return match ($voucherType) {
            'advance' => 'advance',
            'refund' => 'refund',
            'reimbursement' => 'reimbursement',
            default => 'direct',
        };
    }

    /**
     * Generate description for voucher-based payment.
     */
    private static function describeVoucher(SpendVoucher $voucher): string
    {
        return match ($voucher->type) {
            'reimbursement' => "Reimbursement voucher {$voucher->voucher_no}",
            'advance' => "Staff advance voucher {$voucher->voucher_no}",
            'refund' => "Refund voucher {$voucher->voucher_no}",
            'top_up' => "Petty cash replenishment {$voucher->voucher_no}",
            default => "Payment voucher {$voucher->voucher_no}",
        };
    }

    /**
     * Extract allocations from voucher.
     */
    private static function extractVoucherAllocations(SpendVoucher $voucher): ?array
    {
        if (! in_array($voucher->type, ['payment', 'reimbursement'], true)) {
            return null;
        }

        $allocations = $voucher->allocations()->get();

        if ($allocations->isEmpty()) {
            return null;
        }

        return $allocations->map(fn ($allocation) => [
            'cost_line_id' => $allocation->cost_line_id,
            'amount' => (float) $allocation->amount,
            'allocation_type' => 'settlement',
        ])->toArray();
    }
}

