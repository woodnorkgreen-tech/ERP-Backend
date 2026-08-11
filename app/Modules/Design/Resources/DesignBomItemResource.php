<?php

namespace App\Modules\Design\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DesignBomItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'design_item_id' => $this->design_item_id,
            'material_id' => $this->material_id,
            'description' => $this->description,
            'specification' => $this->specification,
            'quantity' => $this->quantity !== null ? (float) $this->quantity : null,
            'unit' => $this->unit,
            'wastage_percent' => $this->wastage_percent !== null ? (float) $this->wastage_percent : null,
            'source' => $this->source,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
