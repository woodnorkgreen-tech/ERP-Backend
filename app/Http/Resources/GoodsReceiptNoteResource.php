<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptNoteResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'grn_number' => $this->grn_number,
            'date' => $this->date->format('Y-m-d'),
            'batch_number' => $this->batch_number,
            'store_location' => $this->store_location,
            'quality_check' => $this->quality_check,
            'notes' => $this->notes,
            
            'purchase_order' => $this->whenLoaded('purchaseOrder', function () {
                return [
                    'id' => $this->purchaseOrder->id,
                    'po_number' => $this->purchaseOrder->po_number,
                    'due_date' => $this->purchaseOrder->due_date->format('Y-m-d'),
                    'supplier' => $this->purchaseOrder->supplier ? [
                        'id' => $this->purchaseOrder->supplier->id,
                        'supplier_name' => $this->purchaseOrder->supplier->supplier_name,
                    ] : null,
                ];
            }),

            'items' => GoodsReceiptNoteItemResource::collection($this->whenLoaded('items')),

            'receivedBy' => $this->when($this->receivedByUser, function () {
                $user = $this->receivedByUser;
                return [
                    'id' => $user->id,
                    'name' => $user->employee 
                        ? ($user->employee->first_name . ' ' . $user->employee->last_name)
                        : $user->name,
                ];
            }),

            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}