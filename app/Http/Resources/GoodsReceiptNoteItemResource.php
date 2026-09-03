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
            'custom_description' => $this->purchaseOrderItem->custom_description ?? null,
            // Resolved display name so items that were never in the library
            // (always bought in) still show a real name instead of blank/N-A.
            'item_name' => optional($this->purchaseOrderItem?->material)->material_name
                ?? $this->purchaseOrderItem->custom_description
                ?? '—',
            'material' => $this->when($this->purchaseOrderItem && $this->purchaseOrderItem->material, function () {
                return [
                    'id' => $this->purchaseOrderItem->material->id,
                    'material_code' => $this->purchaseOrderItem->material->material_code,
                    'material_name' => $this->purchaseOrderItem->material->material_name,
                ];
            }),
            'ordered_quantity' => $this->ordered_quantity,
            'received_quantity' => $this->received_quantity,
            'entered_uom_id' => $this->entered_uom_id,
            'stock_quantity' => $this->stock_quantity !== null ? (float) $this->stock_quantity : null,
            'receipt_unit_cost' => $this->receipt_unit_cost !== null ? (float) $this->receipt_unit_cost : null,
            'stock_status' => $this->stock_status,
            'inventory_log_id' => $this->inventory_log_id,
            'condition' => $this->condition,
            'accepted' => $this->accepted,

            // Store confirmation — set once Stores matches/creates the
            // material and prices it in. Until then store_status stays
            // 'pending' even though the item was already accepted at the
            // dock by Procurement.
            'store_status' => $this->store_status,
            'unit_price' => $this->unit_price !== null ? (float) $this->unit_price : null,
            'confirmed_by' => $this->when($this->confirmedBy, function () {
                return [
                    'id' => $this->confirmedBy->id,
                    'name' => $this->confirmedBy->employee
                        ? ($this->confirmedBy->employee->first_name . ' ' . $this->confirmedBy->employee->last_name)
                        : $this->confirmedBy->name,
                ];
            }),
            'confirmed_at' => $this->confirmed_at?->toISOString(),

            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
