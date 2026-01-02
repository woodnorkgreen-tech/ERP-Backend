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
            'material_code' => $this->material_code,
            'material_name' => $this->material_name,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'unit_of_measure' => $this->unit_of_measure,
            'unit_cost' => (float) $this->unit_cost,
            'attributes' => ($this->attributes && isset($this->attributes['attributes'])) ? $this->attributes['attributes'] : [],
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
