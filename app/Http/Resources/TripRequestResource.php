<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'request_code'        => $this->request_code,

            // Context
            'context_type'        => $this->context_type,
            'project'             => $this->whenLoaded('project', fn() => [
                'id'         => $this->project->id,
                'project_id' => $this->project->project_id,
                'title'      => $this->project->enquiry?->title,
            ]),

            // Delivery
            'delivery_type_label' => $this->delivery_type_label,
            'priority'            => $this->priority,

            // Requester
            'requested_by'        => $this->whenLoaded('requestedBy', fn() => [
                'id'   => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ]),

            // Locations
            'pickup_location'     => $this->pickup_location,
            'pickup_lat'          => $this->pickup_lat,
            'pickup_lng'          => $this->pickup_lng,
            'destination'         => $this->destination,
            'destination_lat'     => $this->destination_lat,
            'destination_lng'     => $this->destination_lng,

            // Schedule
            'required_date'       => $this->required_date->format('Y-m-d'),
            'notes'               => $this->notes,

            // Status
            'status'              => $this->status,

            // Approval
            'approved_by'         => $this->whenLoaded('approvedBy', fn() => [
                'id'   => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ]),
            'approved_at'         => $this->approved_at?->toDateTimeString(),
            'rejection_reason'    => $this->rejection_reason,

            // Assignment
            'assigned_driver'     => $this->whenLoaded('assignedDriver', fn() => [
                'id'             => $this->assignedDriver->id,
                'license_number' => $this->assignedDriver->license_number,
                'employee'       => [
                    'id'   => $this->assignedDriver->employee->id,
                    'name' => $this->assignedDriver->employee->name,
                    'phone'=> $this->assignedDriver->employee->phone,
                ],
            ]),
            'assigned_vehicle'    => $this->whenLoaded('assignedVehicle', fn() => [
                'id'           => $this->assignedVehicle->id,
                'vehicle_id'   => $this->assignedVehicle->vehicle_id,
                'plate_number' => $this->assignedVehicle->plate_number,
                'vehicle_type' => $this->assignedVehicle->vehicle_type,
                'capacity_kg'  => $this->assignedVehicle->capacity_kg,
                'gps_lat'      => $this->assignedVehicle->gps_lat,
                'gps_lng'      => $this->assignedVehicle->gps_lng,
            ]),
            'assigned_by'         => $this->whenLoaded('assignedBy', fn() => [
                'id'   => $this->assignedBy->id,
                'name' => $this->assignedBy->name,
            ]),
            'assigned_at'         => $this->assigned_at?->toDateTimeString(),
            'assignment_notes'    => $this->assignment_notes,

            // Trip tracking
            'started_at'          => $this->started_at?->toDateTimeString(),
            'completed_at'        => $this->completed_at?->toDateTimeString(),

            'created_at'          => $this->created_at->toDateTimeString(),
            'updated_at'          => $this->updated_at->toDateTimeString(),
        ];
    }
}
