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
             'requisition' => $this->when($this->requisition, function () {
                return [
                    'id' => $this->requisition->id,
                    'requisition_number' => $this->requisition->requisition_number,
                ];
            }),
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            
            // Created By
            'createdBy' => $this->when($this->createdBy, function () {
                $user = $this->createdBy;
                return [
                    'id' => $user->id,
                    'name' => $user->employee 
                        ? ($user->employee->first_name . ' ' . $user->employee->last_name)
                        : $user->name,
                ];
            }),
            
            // Approved By
            'approvedBy' => $this->when($this->approved_by && $this->approvedBy, function () {
                $user = $this->approvedBy;
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