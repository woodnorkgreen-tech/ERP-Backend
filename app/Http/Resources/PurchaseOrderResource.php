<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'date' => $this->date->format('Y-m-d'),
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', function () {
                return [
                    'id' => $this->supplier->id,
                    'supplier_name' => $this->supplier->supplier_name,
                    'email' => $this->supplier->email,
                ];
            }),
            'due_date' => $this->due_date->format('Y-m-d'),
            'delivery_address' => $this->delivery_address,
            'description' => $this->description,
            'total_amount' => (float) $this->total_amount,
            'status' => $this->status,
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'createdBy' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'first_name' => $this->createdBy->first_name,
                    'last_name' => $this->createdBy->last_name,
                ];
            }),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
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
        ];
    }
}

class InvoiceResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order' => $this->whenLoaded('purchaseOrder', function () {
                return [
                    'id' => $this->purchaseOrder->id,
                    'po_number' => $this->purchaseOrder->po_number,
                ];
            }),
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', function () {
                return [
                    'id' => $this->supplier->id,
                    'supplier_name' => $this->supplier->supplier_name,
                ];
            }),
            'invoice_date' => $this->invoice_date->format('Y-m-d'),
            'due_date' => $this->due_date->format('Y-m-d'),
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'payment_date' => $this->payment_date ? $this->payment_date->format('Y-m-d') : null,
            'payment_method' => $this->payment_method,
            'notes' => $this->notes,
            'createdBy' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'first_name' => $this->createdBy->first_name,
                    'last_name' => $this->createdBy->last_name,
                ];
            }),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}