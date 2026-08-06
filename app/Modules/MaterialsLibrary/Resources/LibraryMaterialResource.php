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
            'workstation_name' => $this->whenLoaded('workstation', function () {
                return $this->workstation->name;
            }),
            'material_code'        => $this->material_code,
            'material_name'        => $this->material_name,
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
            'stock_handling'       => $this->resource->isBoardTrackable()
                ? 'individual_board'
                : ($this->issue_disposition === 'returnable' ? 'reusable_item' : 'quantity'),
            'unit_of_measure' => $this->unit_of_measure,
            'unit_cost' => (float) $this->unit_cost,
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
