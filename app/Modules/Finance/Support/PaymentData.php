<?php

namespace App\Modules\Finance\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * PaymentData - DTO for unified payment creation
 *
 * Phase 2 of Finance Architecture Redesign:
 * Standardizes payment creation parameters across all entry points.
 */
class PaymentData
{
    public function __construct(
        public ?int $voucherId,
        public int $paymentSourceId,
        public string $paymentMethod,
        public float $amount,
        public string $payeeName,
        public string $payeeType,
        public ?int $payeeId = null,
        public ?float $transactionCost = null,
        public ?string $paymentDate = null,
        public ?string $referenceNumber = null,
        public ?Model $sourceDocument = null,
        public ?int $projectId = null,
        public ?int $projectEnquiryId = null,
        public ?string $jobNumber = null,
        public ?string $budgetCategory = null,
        public ?int $expenseCodeId = null,
        public ?string $description = null,
        public ?array $allocations = null,
        public ?int $accountingPeriodId = null,
    ) {
    }

    /**
     * Create from array (for API requests).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            voucherId: $data['voucher_id'] ?? null,
            paymentSourceId: $data['payment_source_id'],
            paymentMethod: $data['payment_method'],
            amount: $data['amount'],
            payeeName: $data['payee_name'],
            payeeType: $data['payee_type'],
            payeeId: $data['payee_id'] ?? null,
            transactionCost: $data['transaction_cost'] ?? null,
            paymentDate: $data['payment_date'] ?? null,
            referenceNumber: $data['reference_number'] ?? null,
            sourceDocument: $data['source_document'] ?? null,
            projectId: $data['project_id'] ?? null,
            projectEnquiryId: $data['project_enquiry_id'] ?? null,
            jobNumber: $data['job_number'] ?? null,
            budgetCategory: $data['budget_category'] ?? null,
            expenseCodeId: $data['expense_code_id'] ?? null,
            description: $data['description'] ?? null,
            allocations: $data['allocations'] ?? null,
            accountingPeriodId: $data['accounting_period_id'] ?? null,
        );
    }

    /**
     * Create from SpendVoucher (for voucher posting).
     */
    public static function fromVoucher($voucher): self
    {
        return new self(
            voucherId: $voucher->id,
            paymentSourceId: $voucher->payment_source_id,
            paymentMethod: $voucher->payment_method,
            amount: $voucher->net_cash_paid,
            payeeName: $voucher->payee_name,
            payeeType: $voucher->payee_type ?? 'other',
            payeeId: $voucher->payee_id,
            transactionCost: 0,
            paymentDate: $voucher->transacted_at?->format('Y-m-d'),
            referenceNumber: $voucher->payment_reference,
            sourceDocument: null, // Voucher doesn't have polymorphic source in legacy
            projectId: null,
            projectEnquiryId: null,
            jobNumber: null,
            budgetCategory: null,
            expenseCodeId: null,
            description: $voucher->notes,
            allocations: $voucher->allocations->map(fn ($alloc) => [
                'cost_line_id' => $alloc->cost_line_id,
                'amount' => $alloc->amount,
            ])->toArray(),
            accountingPeriodId: $voucher->accounting_period_id,
        );
    }

    /**
     * Create from Bill payment request.
     */
    public static function fromBillPayment($bill, array $requestData): self
    {
        return new self(
            voucherId: null, // Will be created
            paymentSourceId: $requestData['payment_source_id'],
            paymentMethod: $requestData['payment_method'],
            amount: $bill->payableAmount(),
            payeeName: $bill->supplier->name,
            payeeType: 'supplier',
            payeeId: $bill->supplier_id,
            transactionCost: $requestData['transaction_cost'] ?? 0,
            paymentDate: $requestData['payment_date'] ?? now()->format('Y-m-d'),
            referenceNumber: $requestData['reference_number'] ?? null,
            sourceDocument: $bill,
            projectId: $bill->project_id,
            projectEnquiryId: $bill->project_enquiry_id,
            jobNumber: $bill->job_number,
            budgetCategory: null,
            expenseCodeId: null,
            description: "Payment for invoice {$bill->bill_number}",
            allocations: null, // Bill payment doesn't use cost line allocations directly
            accountingPeriodId: null, // Will be resolved
        );
    }

    /**
     * Create from PettyCashRequisition.
     */
    public static function fromPettyCashRequisition($requisition): self
    {
        return new self(
            voucherId: null,
            paymentSourceId: $requisition->payment_source_id ?? $requisition->petty_cash_source_id,
            paymentMethod: $requisition->payment_method ?? 'cash',
            amount: $requisition->amount,
            payeeName: $requisition->payee_name ?? $requisition->requestedBy->name,
            payeeType: 'staff',
            payeeId: $requisition->requested_by,
            transactionCost: 0,
            paymentDate: now()->format('Y-m-d'),
            referenceNumber: $requisition->requisition_no,
            sourceDocument: $requisition,
            projectId: $requisition->project_id,
            projectEnquiryId: $requisition->project_enquiry_id,
            jobNumber: $requisition->job_number,
            budgetCategory: $requisition->budget_category,
            expenseCodeId: $requisition->expense_code_id,
            description: $requisition->purpose,
            allocations: null,
            accountingPeriodId: null,
        );
    }

    /**
     * Convert to array for Payment model creation.
     */
    public function toPaymentAttributes(): array
    {
        return [
            'voucher_id' => $this->voucherId,
            'payment_source_id' => $this->paymentSourceId,
            'payment_method' => $this->paymentMethod,
            'amount' => $this->amount,
            'payee_name' => $this->payeeName,
            'payee_type' => $this->payeeType,
            'payee_id' => $this->payeeId,
            'transaction_cost' => $this->transactionCost ?? 0,
            'date_disbursed' => $this->paymentDate ?? now()->format('Y-m-d'),
            'external_reference' => $this->referenceNumber,
            'source_document_type' => $this->sourceDocument?->getMorphClass(),
            'source_document_id' => $this->sourceDocument?->getKey(),
            'project_id' => $this->projectId,
            'project_enquiry_id' => $this->projectEnquiryId,
            'job_number' => $this->jobNumber,
            'budget_category' => $this->budgetCategory,
            'expense_code_id' => $this->expenseCodeId,
            'description' => $this->description,
            'status' => 'paid',
            'created_by' => auth()->id(),
        ];
    }
}
