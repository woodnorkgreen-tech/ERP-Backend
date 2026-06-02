<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TripRequestResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'request_code' => $this->request_code,
            'context_type' => $this->context_type,
            'project_id'   => $this->project_id,

            'project' => $this->whenLoaded('project', function () {
                if (!$this->project) return null;
                $p  = $this->project;
                $po = $p->projectOfficer ?? $p->project_officer ?? null;
                return [
                    'id'             => $p->id,
                    'job_number'     => $p->job_number     ?? null,
                    'enquiry_number' => $p->enquiry_number ?? null,
                    'title'          => $p->title          ?? null,
                    'venue'          => $p->venue          ?? null,
                    'client'         => $p->client ? [
                        'full_name' => $p->client->full_name ?? $p->client->name ?? null,
                    ] : null,
                    'project_officer' => $po ? [
                        'name'        => $po->name        ?? null,
                        'employee_id' => $po->employee_id ?? null,
                    ] : null,
                ];
            }),

            'delivery_type_label' => $this->delivery_type_label,
            'priority'            => $this->priority,

            'requested_by' => $this->whenLoaded('requestedBy', fn() => $this->requestedBy ? [
                'id'   => $this->requestedBy->id,
                'name' => $this->requestedBy->name ?? $this->requestedBy->full_name,
            ] : null),

            'pickup_location' => $this->pickup_location,
            'pickup_lat'      => $this->pickup_lat,
            'pickup_lng'      => $this->pickup_lng,
            'destination'     => $this->destination,
            'destination_lat' => $this->destination_lat,
            'destination_lng' => $this->destination_lng,
            'required_date'   => $this->required_date?->toDateString(),
            'notes'           => $this->notes,
            'status'          => $this->status,

            'approved_by' => $this->whenLoaded('approvedBy', fn() => $this->approvedBy ? [
                'id'   => $this->approvedBy->id,
                'name' => $this->approvedBy->name ?? $this->approvedBy->full_name,
            ] : null),
            'approved_at'      => $this->approved_at,
            'rejection_reason' => $this->rejection_reason,

            'assigned_driver' => $this->whenLoaded('assignedDriver', fn() => $this->assignedDriver ? [
                'id'             => $this->assignedDriver->id,
                'license_number' => $this->assignedDriver->license_number,
                'employee'       => $this->assignedDriver->employee ? [
                    'id'   => $this->assignedDriver->employee->id,
                    'name' => $this->assignedDriver->employee->name ?? $this->assignedDriver->employee->full_name,
                ] : null,
            ] : null),

            'assigned_vehicle' => $this->whenLoaded('assignedVehicle', fn() => $this->assignedVehicle ? [
                'id'           => $this->assignedVehicle->id,
                'vehicle_id'   => $this->assignedVehicle->vehicle_id,
                'plate_number' => $this->assignedVehicle->plate_number,
                'vehicle_type' => $this->assignedVehicle->vehicle_type,
                'capacity_kg'  => $this->assignedVehicle->capacity_kg,
                'gps_lat'      => $this->assignedVehicle->gps_lat ?? null,
                'gps_lng'      => $this->assignedVehicle->gps_lng ?? null,
            ] : null),

            'assigned_by' => $this->whenLoaded('assignedBy', fn() => $this->assignedBy ? [
                'id'   => $this->assignedBy->id,
                'name' => $this->assignedBy->name ?? $this->assignedBy->full_name,
            ] : null),
            'assigned_at'      => $this->assigned_at,
            'assignment_notes' => $this->assignment_notes,

            'batch_id'    => $this->batch_id    ?? null,
            'stop_order'  => $this->stop_order   ?? null,
            'started_at'  => $this->started_at,
            'completed_at'=> $this->completed_at,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
