<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'vehicle_id'           => $this->vehicle_id,           // VEH-001
            'plate_number'         => $this->plate_number,

            // Classification
            'vehicle_type'         => $this->vehicle_type,
            'capacity_kg'          => $this->capacity_kg,
            'fuel_type'            => $this->fuel_type,

            // Compliance
            'insurance_expiry'     => $this->insurance_expiry->format('Y-m-d'),
            'insurance_is_expired' => $this->insurance_is_expired, // accessor
            'odometer_km'          => $this->odometer_km,

            // GPS
            'gps_status'           => $this->gps_status,
            'is_gps_active'        => $this->is_gps_active,        // accessor
            'gps_lat'              => $this->gps_lat,
            'gps_lng'              => $this->gps_lng,
            'gps_last_updated'     => $this->gps_last_updated?->toDateTimeString(),

            // Status
            'status'               => $this->status,
            'is_available'         => $this->is_available,         // accessor

            // Assigned driver (only loaded when eager-loaded)
            'assigned_driver'      => $this->whenLoaded('assignedDriver', fn() => [
                'id'   => $this->assignedDriver->id,
                'name' => $this->assignedDriver->employee->name,
            ]),

            'created_at'           => $this->created_at->toDateTimeString(),
            'updated_at'           => $this->updated_at->toDateTimeString(),
        ];
    }
}
