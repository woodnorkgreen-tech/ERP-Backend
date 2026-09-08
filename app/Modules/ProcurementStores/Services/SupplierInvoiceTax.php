<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Services\TaxResolver;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\Supplier;

/**
 * Splitting a supplier invoice into net, VAT and withholding.
 *
 * The same two questions `CostTaxPricer` asks of a cost line, asked of an
 * invoice: which VAT treatment applies to this supplier and this kind of spend,
 * and is anything withheld from what they are paid. Resolution goes through the
 * shared {@see TaxResolver} rather than a second copy, so a purchase priced here
 * and the same purchase priced as petty cash reach the same answer — including
 * the rules that are easy to get wrong, like an unregistered supplier being
 * out-of-scope rather than zero-rated, and withholding being charged on the fee
 * rather than on the VAT.
 *
 * ## Gross in, split out
 *
 * `amount` is what the supplier billed, so the VAT is extracted from it rather
 * than added to it: an invoice for 116,000 at 16% is 100,000 of goods and
 * 16,000 of tax, not 116,000 of goods. Getting that backwards would inflate
 * every project's material cost by the VAT.
 *
 * An explicit `vat_amount` always wins. Invoices state their own VAT, and a
 * rounded figure on the document is the claimable one — deriving it and quietly
 * disagreeing with the paper by a shilling is how a VAT return stops
 * reconciling.
 */
class SupplierInvoiceTax
{
    public function __construct(private TaxResolver $taxResolver) {}

    /**
     * The tax columns for an invoice, ready to persist.
     *
     * @param  array<string, mixed>  $input  May carry an explicit `vat_amount`.
     * @return array<string, mixed>
     */
    public function priceFor(Bill $bill, array $input = []): array
    {
        $gross = $this->money($bill->amount);
        $on = (string) ($bill->bill_date?->toDateString() ?? now()->toDateString());

        $supplier = $bill->supplier ?: ($bill->supplier_id ? Supplier::find($bill->supplier_id) : null);
        $treatment = $this->taxResolver->vatTreatmentFor($supplier, $this->expenseCodeFor($bill), $on);

        $vat = array_key_exists('vat_amount', $input) && is_numeric($input['vat_amount'])
            ? $this->money($input['vat_amount'])
            : $this->vatWithin($gross, $treatment?->rate_percent);

        // A stated VAT larger than the invoice is a typing error, not a tax
        // position. Clamped rather than thrown so recording the invoice is never
        // blocked by it; the net simply goes to zero and reads as obviously wrong.
        if (bccomp($vat, $gross, 2) > 0) {
            $vat = $gross;
        }

        $net = bcsub($gross, $vat, 2);

        $category = $this->taxResolver->whtCategoryFor($supplier, $this->expenseCodeFor($bill), $on);
        $wht = array_key_exists('wht_amount', $input) && is_numeric($input['wht_amount'])
            ? $this->money($input['wht_amount'])
            : $this->taxResolver->withholding($net, $category);

        return [
            'net_amount' => $net,
            'vat_amount' => $vat,
            'wht_amount' => $wht,
            'vat_treatment_id' => $treatment?->id,
            // Recorded only when something is actually withheld, so a category
            // that resolved but priced to nothing does not read as a deduction
            // somebody forgot to take.
            'wht_category_id' => bccomp($wht, '0.00', 2) > 0 ? $category?->id : null,
            'etims_invoice_no' => $input['etims_invoice_no'] ?? $bill->etims_invoice_no,
            // The supplier's own PIN unless the invoice carries a different one.
            // Typed beats stored for the same reason it does on a petty cash
            // receipt: the document is the evidence KRA will accept.
            'supplier_pin' => $input['supplier_pin'] ?? $bill->supplier_pin ?? $supplier?->kra_pin,
            'tax_point_date' => $input['tax_point_date'] ?? $bill->tax_point_date ?? $on,
        ];
    }

    /**
     * What the supplier is actually paid: the invoice less anything withheld.
     * The withheld portion is not a discount — it is owed to KRA instead.
     */
    public function payableAmount(Bill $bill): string
    {
        return bcsub($this->money($bill->amount), $this->money($bill->wht_amount), 2);
    }

    /**
     * The kind of spend, which can override the supplier's default treatment.
     * Read off the requisition line behind the order, the same place the
     * goods-receipt accrual reads it.
     */
    private function expenseCodeFor(Bill $bill): ?ExpenseCode
    {
        $codeId = $bill->purchaseOrder?->items()
            ->with('requisitionItem')
            ->get()
            ->map(fn ($item) => $item->requisitionItem?->expense_code_id)
            ->filter()
            ->first();

        return $codeId ? ExpenseCode::find($codeId) : null;
    }

    /** VAT already inside a gross figure: gross − gross / (1 + rate). */
    private function vatWithin(string $gross, mixed $ratePercent): string
    {
        $rate = $this->money($ratePercent ?? 0);

        if (bccomp($rate, '0.00', 2) <= 0 || bccomp($gross, '0.00', 2) <= 0) {
            return '0.00';
        }

        $divisor = bcadd('1', bcdiv($rate, '100', 6), 6);
        $net = bcdiv($gross, $divisor, 2);

        return bcsub($gross, $net, 2);
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
