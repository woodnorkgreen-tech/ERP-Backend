<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BillResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'bill_number' => $this->bill_number,
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
            'bill_date' => $this->bill_date->format('Y-m-d'),
            'due_date' => $this->due_date->format('Y-m-d'),
            'amount' => (float) $this->amount,
            'paid_amount' => (float) $this->paid_amount,
            'balance' => (float) $this->balance,
            'status' => $this->status,
            'notes' => $this->notes,
            'payments' => $this->whenLoaded('payments', function () {
                return $this->payments->map(function ($payment) {
                    return [
                        'id' => $payment->id,
                        'payment_code' => $payment->payment_code,
                        'amount_paid' => (float) $payment->amount_paid,
                        'payment_date' => $payment->payment_date->format('Y-m-d'),
                        'payment_method' => $payment->paymentMethod ? [
                            'id' => $payment->paymentMethod->id,
                            'method_name' => $payment->paymentMethod->method_name,
                        ] : null,
                        'notes' => $payment->notes,
                        'created_by' => $payment->createdBy ? [
                            'id' => $payment->createdBy->id,
                            'name' => $payment->createdBy->name,
                        ] : null,
                        'created_at' => $payment->created_at->toISOString(),
                    ];
                });
            }),
            'createdBy' => $this->whenLoaded('createdBy', function() {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ];
            }),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}