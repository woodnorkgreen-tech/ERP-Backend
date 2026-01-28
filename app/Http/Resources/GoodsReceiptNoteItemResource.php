<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptNoteItemResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'goods_receipt_note_id' => $this->goods_receipt_note_id,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'material_id' => $this->material_id,
            'material' => $this->when($this->purchaseOrderItem && $this->purchaseOrderItem->material, function () {
                return [
                    'id' => $this->purchaseOrderItem->material->id,
                    'material_code' => $this->purchaseOrderItem->material->material_code,
                    'material_name' => $this->purchaseOrderItem->material->material_name,
                ];
            }),
            'ordered_quantity' => $this->ordered_quantity,
            'received_quantity' => $this->received_quantity,
            'condition' => $this->condition,
            'accepted' => $this->accepted,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}