<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'material_id' => $this->material_id,
            'material' => $this->whenLoaded('material', function () {
                return [
                    'id' => $this->material->id,
                    'material_code' => $this->material->material_code,
                    'material_name' => $this->material->material_name,
                ];
            }),
            'quantity' => $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'total' => (float) $this->total,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}