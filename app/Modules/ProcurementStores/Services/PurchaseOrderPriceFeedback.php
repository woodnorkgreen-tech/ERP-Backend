<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;

/**
 * The line price a buyer negotiates and the business already trusts enough to
 * commit money against is the freshest, most authoritative pre-receipt price
 * signal in the system. Approving a PO writes it forward into the material's
 * `default_unit_cost` so the estimate tracks what the business is actually
 * paying, instead of freezing at whatever was typed once on the material form
 * (or never typed — see StoresCostProducer's own note that only ~2% of the
 * catalogue carries a cost).
 *
 * `unit_cost`, the weighted-average receipt valuation, is never touched here.
 * This only ever moves the pre-receipt estimate.
 */
class PurchaseOrderPriceFeedback
{
    public function apply(PurchaseOrder $purchaseOrder): void
    {
        $purchaseOrder->loadMissing('items.material.uomConversions');

        foreach ($purchaseOrder->items as $item) {
            $material = $item->material;
            $unitPrice = (float) $item->unit_price;

            if (! $material || $unitPrice <= 0) {
                continue;
            }

            $baseUnitPrice = $this->toBaseUnitPrice($item, $material);

            if ($baseUnitPrice === null) {
                continue;
            }

            $material->forceFill(['default_unit_cost' => round($baseUnitPrice, 2)])->save();
        }
    }

    /**
     * `default_unit_cost` is always kept as a price per base unit — the same
     * basis MaterialPurchaseOptions scales up from when it shows a buyer an
     * ordering estimate. A PO line may have been priced in the base unit or
     * the purchase unit (the only two choices the picker ever offers), so
     * convert back down using the same conversion row that scaled it up.
     * Returns null rather than a guess when the line's unit cannot be
     * trusted to convert — an unexpected buying unit with no matching
     * conversion row.
     */
    private function toBaseUnitPrice(PurchaseOrderItem $item, LibraryMaterial $material): ?float
    {
        $unitPrice = (float) $item->unit_price;

        if (! $item->uom_id || (int) $item->uom_id === (int) $material->base_uom_id) {
            return $unitPrice;
        }

        $conversion = $material->uomConversions->first(fn ($row) =>
            (int) $row->from_uom_id === (int) $item->uom_id
            && (int) $row->to_uom_id === (int) $material->base_uom_id
            && (float) $row->factor > 0);

        return $conversion ? $unitPrice / (float) $conversion->factor : null;
    }
}
