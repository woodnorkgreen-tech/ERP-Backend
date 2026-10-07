<?php

namespace App\Modules\MaterialsLibrary\Support;

use App\Modules\MaterialsLibrary\Models\MaterialCategory;

/** Shared export/import contract; labels follow the material registration modal. */
final class MaterialWorkbook
{
    public const SHEET = 'Materials';

    public const COLUMNS = [
        'Material ID' => 'id', 'Item code' => 'material_code', 'Material name' => 'material_name',
        'Workshop area' => 'workstation_id', 'Category' => 'material_category_id', 'Item type' => 'item_type_id',
        'Status' => 'item_status', 'Issue disposition' => 'issue_disposition', 'Tracking mode' => 'tracking_mode',
        'Stock unit' => 'base_uom_id', 'Purchase unit' => 'purchase_uom_id', 'Purchase conversion factor' => 'purchase_factor',
        'Issue unit' => 'issue_uom_id', 'Issue conversion factor' => 'issue_factor',
        'Default unit cost' => 'default_unit_cost', 'Brand / manufacturer' => 'brand_manufacturer',
        'Manufacturer part number' => 'manufacturer_part_number', 'Alternative item name' => 'alternative_item_name',
        'Hazardous' => 'is_hazardous', 'Serialized' => 'is_serialized', 'Batch controlled' => 'is_batch_controlled',
        'Expiry controlled' => 'is_expiry_controlled', 'Project chargeable' => 'is_project_chargeable',
        'Minimum reusable length (mm)' => 'minimum_reusable_length_mm',
        'Minimum reusable width (mm)' => 'minimum_reusable_width_mm', 'Minimum reusable area (m2)' => 'minimum_reusable_area_m2',
        'Revision' => 'revision_version', 'Effective date' => 'effective_date', 'Notes' => 'notes',
        'Additional attributes (JSON)' => 'attributes_json',
        'Legacy category (reference)' => 'legacy_category', 'Legacy subcategory (reference)' => 'legacy_subcategory',
        'Legacy UOM (reference)' => 'legacy_uom', 'Current unit cost (reference)' => 'current_unit_cost',
        'Stock on hand (reference)' => 'stock_on_hand', 'Inventory visible (reference)' => 'inventory_visible',
    ];

    public static function choice($model): ?string
    {
        if (! $model) {
            return null;
        }
        $name = $model->name;
        if ($model instanceof MaterialCategory) {
            $name = ($model->parent ? $model->parent->name.' / ' : '').$model->name;
        }

        return $model->id.' | '.$name;
    }
}
