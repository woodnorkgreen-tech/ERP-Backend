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
            'unit_of_measure' => $this->unit_of_measure,
            'unit_cost' => (float) $this->unit_cost,
            'attributes' => ($this->attributes && isset($this->attributes['attributes'])) ? $this->attributes['attributes'] : [],
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            // whenLoaded guard prevents N+1 when stock is not eager-loaded
            'quantity_on_hand'  => $this->whenLoaded('stock', fn() => (float) $this->stock->quantity_on_hand, 0),
            'quantity_reserved' => $this->whenLoaded('stock', fn() => (float) $this->stock->quantity_reserved, 0),
            'available'         => $this->whenLoaded('stock', fn() => (float) ($this->stock->quantity_on_hand - $this->stock->quantity_reserved), 0),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
