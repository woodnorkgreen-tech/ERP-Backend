<?php

namespace App\Modules\MaterialsLibrary\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryMaterialResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workstation_id' => $this->workstation_id,
            'item_type_id' => $this->item_type_id,
            'item_type' => $this->whenLoaded('itemType', fn () => [
                'id' => $this->itemType->id, 'code' => $this->itemType->code, 'name' => $this->itemType->name,
            ]),
            'workstation_name' => $this->whenLoaded('workstation', function () {
                return $this->workstation->name;
            }),
            'material_code'        => $this->material_code,
            'material_name'        => $this->material_name,
            'brand_manufacturer'   => $this->brand_manufacturer,
            'manufacturer_part_number' => $this->manufacturer_part_number,
            'alternative_item_name' => $this->alternative_item_name,
            'category'             => $this->category,
            'subcategory'          => $this->subcategory,
            'material_category_id' => $this->material_category_id,
            'material_type'        => $this->material_type ?? 'consumable',
            'item_status'           => $this->item_status ?? ($this->is_active ? 'Active' : 'Inactive'),
            'issue_disposition'     => $this->issue_disposition ?? ($this->material_type === 'reusable' ? 'returnable' : 'consumed'),
            'tracking_mode'         => $this->tracking_mode ?? ($this->resource->isBoardTrackable() ? 'dimension_piece' : 'bulk_quantity'),
            'is_hazardous'          => (bool) $this->is_hazardous,
            'is_serialized'         => (bool) $this->is_serialized,
            'is_batch_controlled'   => (bool) $this->is_batch_controlled,
            'is_expiry_controlled'  => (bool) $this->is_expiry_controlled,
            'is_project_chargeable' => (bool) $this->is_project_chargeable,
            'minimum_reusable_length_mm' => $this->minimum_reusable_length_mm !== null ? (float) $this->minimum_reusable_length_mm : null,
            'minimum_reusable_width_mm'  => $this->minimum_reusable_width_mm !== null ? (float) $this->minimum_reusable_width_mm : null,
            'minimum_reusable_area_m2'   => $this->minimum_reusable_area_m2 !== null ? (float) $this->minimum_reusable_area_m2 : null,
            'board_trackable'      => $this->resource->isBoardTrackable(),
            // Read the model's one definition rather than recomputing it here.
            'stock_handling'       => $this->resource->stock_handling,
            'handling_label'       => $this->resource->handling_label,
            'unit_of_measure' => $this->unit_of_measure,
            'base_uom_id' => $this->base_uom_id,
            'purchase_uom_id' => $this->purchase_uom_id,
            'issue_uom_id' => $this->issue_uom_id,
            'base_uom' => $this->whenLoaded('baseUom', fn () => $this->baseUom?->code),
            'purchase_uom' => $this->whenLoaded('purchaseUom', fn () => $this->purchaseUom ? ['id' => $this->purchaseUom->id, 'code' => $this->purchaseUom->code, 'name' => $this->purchaseUom->name] : null),
            'issue_uom' => $this->whenLoaded('issueUom', fn () => $this->issueUom ? ['id' => $this->issueUom->id, 'code' => $this->issueUom->code, 'name' => $this->issueUom->name] : null),
            'uom_conversions' => $this->whenLoaded('uomConversions', fn () => $this->uomConversions->map(fn ($conversion) => [
                'from_uom_id' => $conversion->from_uom_id,
                'to_uom_id' => $conversion->to_uom_id,
                'factor' => (float) $conversion->factor,
            ])->values()),
            'valuation_method' => $this->valuation_method,
            'revision_version' => $this->revision_version,
            'effective_date' => $this->effective_date?->toDateString(),
            'unit_cost' => (float) $this->unit_cost,
            // Null means nobody has set one — the receipt screens read this to
            // decide whether they must ask for a price.
            'default_unit_cost' => $this->default_unit_cost !== null ? (float) $this->default_unit_cost : null,
            'attributes' => ($this->attributes && isset($this->attributes['attributes'])) ? $this->attributes['attributes'] : [],
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            // whenLoaded guard prevents N+1 when stock is not eager-loaded
            'quantity_on_hand'  => $this->whenLoaded('stock', fn() => (float) ($this->stock?->quantity_on_hand ?? 0), 0),
            'quantity_reserved' => $this->whenLoaded('stock', fn() => (float) ($this->stock?->quantity_reserved ?? 0), 0),
            'available'         => $this->whenLoaded('stock', fn() => (float) (($this->stock?->quantity_on_hand ?? 0) - ($this->stock?->quantity_reserved ?? 0)), 0),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
