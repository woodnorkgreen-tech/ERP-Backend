<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'employee_id'     => $this->employee_id,
            'name'            => $this->employee?->name,
            'phone'           => $this->employee?->phone,
            'license_number'  => $this->license_number,
            'license_expiry'  => $this->license_expiry?->format('Y-m-d'),
            'status'          => $this->status,
            'created_at'      => $this->created_at?->toDateTimeString(),
            'updated_at'      => $this->updated_at?->toDateTimeString(),

            // Active delivery data
            'active_delivery' => $this->whenLoaded('activeDelivery', function() {
                $delivery = $this->activeDelivery;
                return [
                    'id'              => $delivery->id,
                    'delivery_code'   => $delivery->delivery_code,
                    'status'          => $delivery->status,
                    'delivery_date'   => $delivery->delivery_date?->format('Y-m-d'),
                    'total_stops'     => $delivery->total_stops,
                    'completed_stops' => $delivery->completed_stops,
                    'vehicle'         => $delivery->vehicle ? [
                        'id'           => $delivery->vehicle->id,
                        'plate_number' => $delivery->vehicle->plate_number,
                        'vehicle_type' => $delivery->vehicle->vehicle_type,
                    ] : null,
                ];
            }),
        ];
    }
}
