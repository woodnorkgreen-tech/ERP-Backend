<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,

            // Employee relationship — name uses the $appends accessor, phone is the 'phone' field
            'employee_id'    => $this->employee_id,
            'name'           => $this->employee->name,         // uses getNameAttribute() → first_name . ' ' . last_name
            'phone'          => $this->employee->phone,        // direct column on employees table

            // Driver-specific fields
            'license_number' => $this->license_number,
            'license_expiry' => $this->license_expiry->format('Y-m-d'),
            'status'         => $this->status,

            'created_at'     => $this->created_at->toDateTimeString(),
            'updated_at'     => $this->updated_at->toDateTimeString(),
        ];
    }
}
