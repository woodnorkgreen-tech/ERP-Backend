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
                    'base_uom_id' => $this->material->base_uom_id,
                    'base_uom' => $this->material->baseUom?->code ?? $this->material->unit_of_measure,
                    'purchase_uom' => $this->material->purchaseUom ? ['id' => $this->material->purchaseUom->id, 'code' => $this->material->purchaseUom->code, 'name' => $this->material->purchaseUom->name] : null,
                    'uom_conversions' => $this->material->uomConversions->map(fn ($row) => ['from_uom_id' => $row->from_uom_id, 'to_uom_id' => $row->to_uom_id, 'factor' => (float) $row->factor])->values(),
                    'requires_controlled_receipt' => $this->material->isBoardTrackable() || $this->material->is_serialized || $this->material->is_batch_controlled || $this->material->is_expiry_controlled,
                ];
            }),
            'quantity'    => $this->quantity,
            'uom_id'      => $this->uom_id,
            'uom'         => $this->whenLoaded('uom', fn () => $this->uom ? ['id' => $this->uom->id, 'code' => $this->uom->code, 'name' => $this->uom->name] : null),
            'accepted_quantity' => $accepted,
            'remaining_quantity' => max(0, (float) $this->quantity - $accepted),
            'unit_price'  => (float) $this->unit_price,
            'total'       => (float) $this->total,
            'created_at'  => $this->created_at->toISOString(),
            'updated_at'  => $this->updated_at->toISOString(),
        ];
    }
}
