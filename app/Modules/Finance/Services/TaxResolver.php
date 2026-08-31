<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\Models\WhtCategory;
use App\Modules\ProcurementStores\Models\Supplier;

/**
 * Resolves the tax treatment for a payment, from data rather than constants.
 *
 * The brief (§8) requires the VAT and WHT rates to be Finance-editable before
 * go-live, so nothing here may hardcode 16%, 5% or 3% — every figure is read
 * from `vat_treatments` / `wht_categories`, effective-dated, so a cost posted
 * last year keeps last year's rate when the table changes.
 *
 * Precedence differs between the two taxes on purpose:
 *
 * - **VAT** follows the supplier first. A supplier who is not VAT-registered
 *   cannot charge it whatever was bought, so that check comes before the
 *   expense code's default.
 * - **WHT** follows the expense code first. The rate depends on the nature of
 *   the service — professional fees withhold at a different rate than
 *   contractual work — and that is what the code describes, not the vendor.
 */
class TaxResolver
{
    /**
     * The VAT treatment in force for this payment, or null when it cannot be
     * determined and a human should decide.
     */
    public function vatTreatmentFor(?Supplier $supplier, ?ExpenseCode $code, string $on): ?VatTreatment
    {
        // An unregistered supplier charges no VAT regardless of what was bought.
        // Out-of-scope is the honest classification: not "zero rated" (which is
        // a registered supply taxed at 0%) and not "exempt".
        if ($supplier && $supplier->vat_status === 'not_registered') {
            return $this->byCode('OOS', $on);
        }

        $preferred = $code?->default_vat_treatment_code;

        return $this->byCode($preferred, $on)
            ?? ($supplier?->default_vat_treatment_id
                ? VatTreatment::effectiveOn($on)->whereKey($supplier->default_vat_treatment_id)->first()
                : null);
    }

    /**
     * The WHT category in force, or null when nothing should be withheld
     * automatically.
     */
    public function whtCategoryFor(?Supplier $supplier, ?ExpenseCode $code, string $on): ?WhtCategory
    {
        $residency = $supplier?->residency ?? 'resident';

        $preferred = $code?->default_wht_category_code;

        if ($preferred) {
            $match = WhtCategory::effectiveOn($on)
                ->where('code', $preferred)
                ->where('residency', $residency)
                ->first();

            if ($match) {
                return $match;
            }

            // The code names a category that has no row for this supplier's
            // residency. Falling back to the resident rate for a non-resident
            // would understate the withholding and look settled; returning null
            // leaves it visibly unwithheld for Finance to price by hand.
            if ($residency !== 'resident') {
                return null;
            }
        }

        if (! $supplier?->wht_category_id) {
            return null;
        }

        return WhtCategory::effectiveOn($on)->whereKey($supplier->wht_category_id)->first();
    }

    /**
     * Withholding on a net (VAT-exclusive) amount.
     *
     * WHT is charged on the fee, not on the VAT, so the caller passes the net —
     * withholding on gross would over-deduct by the VAT rate.
     *
     * Returns a bcmath string for the same reason the ledger does: this figure
     * is deducted from what a supplier is paid, and float drift here is money.
     */
    public function withholding(string $netAmount, ?WhtCategory $category): string
    {
        if (! $category || bccomp($category->rate_percent, '0', 3) !== 1) {
            return '0.00';
        }

        // Below the threshold nothing is withheld. Note this tests the single
        // payment: `aggregate_monthly` says the threshold is really meant to be
        // tested against the supplier's month, which needs a supplier-month view
        // that does not exist yet. Under-withholding a series of small payments
        // is the known gap, and it is recorded rather than silently wrong.
        if ($category->threshold_amount !== null
            && bccomp($netAmount, (string) $category->threshold_amount, 2) === -1) {
            return '0.00';
        }

        return bcdiv(bcmul($netAmount, (string) $category->rate_percent, 5), '100', 2);
    }

    private function byCode(?string $code, string $on): ?VatTreatment
    {
        if (! $code) {
            return null;
        }

        return VatTreatment::effectiveOn($on)->where('code', $code)->first();
    }
}
