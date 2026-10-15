<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\Finance\Models\VatTreatment;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Validation\ValidationException;

/**
 * Purchase prices are held net of VAT (Report 76A-3 §1).
 *
 * The goods receipt accrues at the order's unit price, and the supplier's
 * invoice is matched against that accrual net of VAT. An order priced with VAT
 * in it would accrue the tax as though it were goods, and every invoice would
 * then show a "price difference" that is only the tax.
 *
 * So an order line's `unit_price` is the NET price. A supplier may quote with
 * tax included; that quote is kept exactly as given — the price, that it
 * included tax, the treatment and rate it was read with — beside the net,
 * tax and gross it normalises to. Nothing is discarded and nothing is guessed:
 * a tax-inclusive quote with no VAT treatment to read it by is refused.
 *
 * Orders created before this rule carry no basis (`price_basis` NULL) and no
 * quote columns. They are not reinterpreted: nobody recorded whether their
 * prices included tax, and this code does not decide it for them.
 */
class PurchasePriceBasis
{
    public const NET = 'net';

    /**
     * @param  array<string, mixed>  $item  quantity, unit_price (as quoted), optional price_includes_tax, vat_treatment_id
     * @return array<string, mixed> the columns of a net-priced order line
     */
    public function normalise(array $item, ?int $supplierId, string $at = 'items'): array
    {
        $quantity = number_format((float) ($item['quantity'] ?? 0), 6, '.', '');
        $quoted = number_format((float) ($item['unit_price'] ?? 0), 4, '.', '');
        $includesTax = filter_var($item['price_includes_tax'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $treatment = ! empty($item['vat_treatment_id'])
            ? VatTreatment::whereKey($item['vat_treatment_id'])->where('is_active', true)->first()
            : ($supplierId ? VatTreatment::whereKey(Supplier::whereKey($supplierId)->value('default_vat_treatment_id'))->where('is_active', true)->first() : null);

        if ($includesTax && ! $treatment) {
            throw ValidationException::withMessages(["{$at}.vat_treatment_id" => 'This price is quoted with tax included. Choose the VAT treatment it includes, so the net price can be worked out; no rate is assumed.']);
        }
        if (! empty($item['vat_treatment_id']) && ! $treatment) {
            throw ValidationException::withMessages(["{$at}.vat_treatment_id" => 'That VAT treatment is not active.']);
        }

        $rate = number_format((float) ($treatment?->rate_percent ?? 0), 3, '.', '');
        $factor = bcadd('1', bcdiv($rate, '100', 8), 8);
        $extended = $this->round(bcmul($quantity, $quoted, 10));

        if ($includesTax) {
            $gross = $extended;
            $net = $this->round(bcdiv($gross, $factor, 10));
        } else {
            $net = $extended;
            $gross = $this->round(bcmul($net, $factor, 10));
        }
        $unitNet = bccomp($quantity, '0', 6) > 0 ? $this->round(bcdiv($net, $quantity, 10)) : '0.00';

        return [
            'unit_price' => $unitNet,
            'total' => $net,
            'quoted_unit_price' => $quoted,
            'quoted_price_includes_tax' => $includesTax,
            'vat_treatment_id' => $treatment?->id,
            'tax_rate_percent' => $treatment ? $rate : null,
            'net_amount' => $net,
            'tax_amount' => bcsub($gross, $net, 2),
            'gross_amount' => $gross,
        ];
    }

    private function round(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }
}
