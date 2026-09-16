<?php

namespace App\Modules\MaterialsLibrary\Support;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\MaterialUomConversion;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;

/**
 * The field-consistency rules a material's data must satisfy no matter which
 * door it was written through — the registration form, an edit, a bulk
 * governance change, or a material created inline while receiving stock.
 *
 * Pulled out of MaterialController so a rule about what a material's data must
 * agree with internally is defined once, per Rule 2 of the catalogue workflow:
 * classification is server-owned, defined once.
 */
final class MaterialFieldSync
{
    /**
     * When material_category_id is provided, write matching values into the legacy
     * category/subcategory string columns so both lookup paths stay consistent.
     * The root category name maps to `category`; the leaf name maps to `subcategory`.
     */
    public static function syncCategoryStrings(array $data): array
    {
        if (empty($data['material_category_id'])) {
            return $data;
        }

        $cat = MaterialCategory::with('parent')->find($data['material_category_id']);
        if (!$cat) {
            return $data;
        }

        if ($cat->parent) {
            $data['category']    = $cat->parent->name;
            $data['subcategory'] = $cat->name;
        } else {
            $data['category'] = $cat->name;
            $data['subcategory'] = null;
        }

        return $data;
    }

    public static function syncControlCompatibility(array $data, ?LibraryMaterial $material = null): array
    {
        $disposition = $data['issue_disposition'] ?? $material?->issue_disposition ?? 'consumed';
        $data['material_type'] = MaterialControl::legacyMaterialType($disposition);

        if (isset($data['item_status'])) {
            $data['is_active'] = $data['item_status'] === 'Active';
        } elseif (array_key_exists('is_active', $data)) {
            $data['item_status'] = $data['is_active'] ? 'Active' : 'Inactive';
        }

        return $data;
    }

    public static function syncUomCompatibility(array $data, ?LibraryMaterial $material = null): array
    {
        $baseUomId = $data['base_uom_id'] ?? $material?->base_uom_id;
        if ($baseUomId) {
            $baseUom = UnitOfMeasure::where('is_active', true)->findOrFail($baseUomId);
            $data['unit_of_measure'] = $baseUom->code; // keep legacy consumers synchronized
            $data['issue_uom_id'] ??= $baseUomId;
        }
        return $data;
    }

    /** Keep only the practical purchase/issue -> stock conversions supplied by the form. */
    public static function syncUomConversions(LibraryMaterial $material, array $conversions): void
    {
        $baseUomId = (int) $material->base_uom_id;
        MaterialUomConversion::where('material_id', $material->id)->delete();

        foreach ($conversions as $conversion) {
            $fromUomId = (int) $conversion['from_uom_id'];
            if (! $baseUomId || $fromUomId === $baseUomId) {
                continue;
            }

            MaterialUomConversion::create([
                'material_id' => $material->id,
                'from_uom_id' => $fromUomId,
                'to_uom_id' => $baseUomId,
                'factor' => $conversion['factor'],
            ]);
        }
    }
}
