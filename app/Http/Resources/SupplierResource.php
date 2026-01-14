<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id ?? null,
            'supplier_name' => $this->supplier_name ?? null,
            'contact_person' => $this->contact_person ?? null,
            'phone' => $this->phone ?? null,
            'email' => $this->email ?? null,
            'address' => $this->address ?? null,
            'payment_terms' => $this->payment_terms ?? null,
            'status' => $this->status ?? null,
            'user_id'=> $this->user_id,
            'createdBy'=> $this->createdBy,
            'created_at' => $this->created_at ?? null,
        ];
    }
}