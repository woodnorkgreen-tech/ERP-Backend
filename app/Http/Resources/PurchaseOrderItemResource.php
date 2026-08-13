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
        $accepted = $this->relationLoaded('goodsReceiptNoteItems')
            ? (float) $this->goodsReceiptNoteItems->where('accepted', true)->sum('received_quantity')
            : 0.0;
        return [
            'id'               => $this->id,
            'purchase_order_id'=> $this->purchase_order_id,
            'requisition_item_id' => $this->requisition_item_id,
            'material_id'      => $this->material_id,
            'custom_description' => $this->custom_description,
            // Resolved display name: library name OR custom description
            'item_name'        => $this->material?->material_name ?? $this->custom_description ?? '—',
            'material'         => $this->whenLoaded('material', function () {
                if (!$this->material) return null;
                return [
                    'id'            => $this->material->id,
                    'material_code' => $this->material->material_code,
                    'material_name' => $this->material->material_name,
                ];
            }),
            'quantity'    => $this->quantity,
            'accepted_quantity' => $accepted,
            'remaining_quantity' => max(0, (float) $this->quantity - $accepted),
            'unit_price'  => (float) $this->unit_price,
            'total'       => (float) $this->total,
            'created_at'  => $this->created_at->toISOString(),
            'updated_at'  => $this->updated_at->toISOString(),
        ];
    }
}
