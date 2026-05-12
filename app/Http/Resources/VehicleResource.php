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
            'vehicle_id'           => $this->vehicle_id,
            'plate_number'         => $this->plate_number,

            // Make / Model (added via migration)
            'make'                 => $this->make,
            'model'                => $this->model,

            // Classification
            'vehicle_type'         => $this->vehicle_type,
            'capacity_kg'          => $this->capacity_kg,
            'fuel_type'            => $this->fuel_type,

            // Compliance
            'insurance_expiry'     => $this->insurance_expiry->format('Y-m-d'),
            'insurance_is_expired' => $this->insurance_is_expired,
            'odometer_km'          => $this->odometer_km,

            // GPS
            'gps_status'           => $this->gps_status,
            'is_gps_active'        => $this->is_gps_active,
            'gps_lat'              => $this->gps_lat,
            'gps_lng'              => $this->gps_lng,
            'gps_last_updated'     => $this->gps_last_updated?->toDateTimeString(),

            // Status
            'status'               => $this->status,
            'is_available'         => $this->is_available,

            // Photos (full public URLs via model accessors)
            'photo_front_url'      => $this->photo_front_url,
            'photo_side_url'       => $this->photo_side_url,

            // Assigned driver (only when eager-loaded)
            'assigned_driver'      => $this->whenLoaded('assignedDriver', fn() => [
                'id'   => $this->assignedDriver->id,
                'name' => $this->assignedDriver->employee->name,
            ]),

            'created_at'           => $this->created_at->toDateTimeString(),
            'updated_at'           => $this->updated_at->toDateTimeString(),
        ];
    }
}