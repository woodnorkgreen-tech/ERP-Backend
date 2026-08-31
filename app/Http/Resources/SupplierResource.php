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
            // Tax identity. Exposed so the voucher screen can tell at a glance
            // whether a payee is withheld against before the payment is made,
            // rather than discovering it at verification.
            'legal_name' => $this->legal_name ?? null,
            'kra_pin' => $this->kra_pin ?? null,
            'vat_status' => $this->vat_status ?? null,
            'etims_default' => (bool) ($this->etims_default ?? false),
            'residency' => $this->residency ?? null,
            'default_vat_treatment_id' => $this->default_vat_treatment_id ?? null,
            'wht_category_id' => $this->wht_category_id ?? null,
            'user_id'=> $this->user_id,
            'createdBy'=> $this->createdBy,
            'created_at' => $this->created_at ?? null,
        ];
    }
}