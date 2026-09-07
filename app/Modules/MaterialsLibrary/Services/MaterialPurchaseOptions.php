<?php

namespace App\Modules\MaterialsLibrary\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;

/** Keep the selected buying unit and its estimated price on the same basis. */
class MaterialPurchaseOptions
{
    public function forMaterial(LibraryMaterial $material): array
    {
        $material->loadMissing('baseUom', 'purchaseUom', 'uomConversions', 'materialCategory.parent');
        $unit = $material->baseUom;
        $factor = 1.0;
        $warning = null;

        if ($material->purchase_uom_id && $material->purchase_uom_id !== $material->base_uom_id) {
            $conversion = $material->uomConversions->first(fn ($row) =>
                (int) $row->from_uom_id === (int) $material->purchase_uom_id
                && (int) $row->to_uom_id === (int) $material->base_uom_id
                && (float) $row->factor > 0);

            if ($material->is_serialized || $material->isBoardTrackable()) {
                $warning = 'Individually tracked items are purchased in their stock unit.';
            } elseif (! $conversion || ! $material->purchaseUom?->is_active) {
                $warning = 'Buying unit setup is incomplete. Use the stock unit or complete the conversion in the Materials Library.';
            } else {
                $unit = $material->purchaseUom;
                $factor = (float) $conversion->factor;
            }
        }

        $baseCost = (float) $material->unit_cost > 0
            ? (float) $material->unit_cost
            : (float) ($material->default_unit_cost ?? 0);

        return [
            'ordering_uom' => $unit ? ['id' => $unit->id, 'code' => $unit->code, 'name' => $unit->name] : null,
            'ordering_unit_cost' => round($baseCost * $factor, 2),
            'purchase_conversion_factor' => $factor,
            'purchase_setup_warning' => $warning,
        ];
    }
}
