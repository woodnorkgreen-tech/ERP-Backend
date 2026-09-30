<?php

namespace App\Modules\Finance\Services;

/**
 * W2-5 (confirmed 2026-09-23): a reusable Finance duplicate-detection
 * foundation. W3-5 (the petty-cash expense check) is built on it below
 * rather than as a second engine.
 *
 * Deliberately narrow today. The directive's own confirmed minimum is exact,
 * explainable matching — (Supplier + Supplier Invoice Number) for Bills,
 * (payment source/account + reference) for payments — and that is all this
 * builds. A "possible duplicate" tier (a looser, fuzzy match) is explicitly
 * NOT implemented: nothing here can state a clear, explainable rule for one
 * without inventing a threshold or tolerance nobody has confirmed, and the
 * directive is explicit that a flagged match must always be explainable to
 * Finance as "why was this flagged?" — an exact match on the two facts both
 * sides of a duplicate necessarily share answers that question on its own;
 * a fuzzy one would not.
 *
 * Every check normalises the same, narrow way: trimmed, case-folded. No
 * global uniqueness is ever asserted — every check is scoped (to a supplier,
 * to a payment source/account) because the same invoice number or the same
 * reference format can legitimately recur across two different suppliers or
 * two different accounts.
 */
class DuplicateDetectionService
{
    /**
     * W3-5 (confirmed 2026-09-23): the same supplier/payee, the same receipt
     * number AND the same amount already claimed as a live petty-cash expense —
     * either as a surrender item or as a direct disbursement (which is where a
     * W3-1 walk-in cash purchase lands). All three facts must match: receipt
     * formats legitimately repeat across shops, and one shop legitimately issues
     * one receipt number once. No date window or fuzzy tolerance is applied;
     * nothing has confirmed one.
     *
     * Excluded: items retired by a W3-7 reversal, voided payments, and the
     * requisition/payment being checked itself.
     *
     * @return array{status: 'none'|'confirmed', matched_type: 'surrender_item'|'payment'|null, matched_id: int|null}
     */
    public function checkExpenseReceipt(
        string $supplier,
        string $receipt,
        string $amount,
        ?int $excludeRequisitionId = null,
        ?int $excludePaymentId = null,
    ): array {
        $amount = number_format((float) $amount, 2, '.', '');

        $item = \App\Modules\Finance\PettyCash\Models\PettyCashSurrenderItem::query()
            ->whereNull('superseded_at')
            ->whereRaw('LOWER(TRIM(supplier_name)) = ?', [$this->normalise($supplier)])
            ->whereRaw('LOWER(TRIM(receipt_number)) = ?', [$this->normalise($receipt)])
            ->where('amount', $amount)
            ->when($excludeRequisitionId, fn ($q) => $q->where('requisition_id', '!=', $excludeRequisitionId))
            ->value('id');
        if ($item) {
            return ['status' => 'confirmed', 'matched_type' => 'surrender_item', 'matched_id' => (int) $item];
        }

        $payment = \App\Modules\Finance\Models\Payment::query()
            ->where('status', 'active')
            ->whereRaw('LOWER(TRIM(payee_name)) = ?', [$this->normalise($supplier)])
            ->whereRaw('LOWER(TRIM(receipt_number)) = ?', [$this->normalise($receipt)])
            ->where('amount', $amount)
            ->when($excludePaymentId, fn ($q) => $q->whereKeyNot($excludePaymentId))
            ->value('id');

        return $payment
            ? ['status' => 'confirmed', 'matched_type' => 'payment', 'matched_id' => (int) $payment]
            : ['status' => 'none', 'matched_type' => null, 'matched_id' => null];
    }

    /** @return array{status: 'none'|'confirmed', matched_id: int|null} */
    public function checkBillInvoiceNumber(int $supplierId, string $invoiceNumber, ?int $excludeBillId = null): array
    {
        $query = \App\Modules\ProcurementStores\Models\Bill::query()
            ->where('supplier_id', $supplierId)
            ->where('status', '!=', 'cancelled')
            ->whereRaw('LOWER(TRIM(supplier_invoice_number)) = ?', [$this->normalise($invoiceNumber)]);

        if ($excludeBillId) {
            $query->whereKeyNot($excludeBillId);
        }

        $matchId = $query->value('id');

        return ['status' => $matchId ? 'confirmed' : 'none', 'matched_id' => $matchId];
    }

    /**
     * Cash is excluded by the caller before this is ever reached — a cash
     * payment legitimately carries no reference at all, so "no reference"
     * can never itself be treated as a duplicate signal.
     *
     * @return array{status: 'none'|'confirmed', matched_id: int|null}
     */
    public function checkPaymentReference(int $paymentSourceId, string $reference, ?int $excludePaymentId = null): array
    {
        $query = \App\Modules\ProcurementStores\Models\BillPayment::query()
            ->where('payment_source_id', $paymentSourceId)
            ->whereRaw('LOWER(TRIM(reference_number)) = ?', [$this->normalise($reference)]);

        if ($excludePaymentId) {
            $query->whereKeyNot($excludePaymentId);
        }

        $matchId = $query->value('id');

        return ['status' => $matchId ? 'confirmed' : 'none', 'matched_id' => $matchId];
    }

    private function normalise(string $value): string
    {
        return strtolower(trim($value));
    }
}
