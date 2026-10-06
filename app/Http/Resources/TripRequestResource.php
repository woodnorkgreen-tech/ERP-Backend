<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TripRequestResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                     => $this->id,
            'request_code'           => $this->request_code,
            'context_type'           => $this->context_type,
            'transport_arrangement'  => $this->transport_arrangement,
            'project_id'             => $this->project_id,

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
            // Merged in from the Logistics Log's planning timeline.
            'loading_time'    => $this->loading_time,
            'departure_time'  => $this->departure_time,
            'setdown_time'    => $this->setdown_time,

            // Backward-calculation from the delivery deadline.
            'required_delivery_at'      => $this->required_delivery_at,
            'estimated_loading_minutes' => $this->estimated_loading_minutes,
            'load_size'                 => $this->load_size,
            'estimated_travel_minutes'  => $this->estimated_travel_minutes,
            'buffer_minutes'            => $this->buffer_minutes,
            'loading_start_by'          => $this->loading_start_by,
            'departure_by'              => $this->departure_by,
            'loading_started_at'        => $this->loading_started_at,
            'loading_ended_at'          => $this->loading_ended_at,
            'timeline_status'           => $this->timeline_status,
            'loading_responsible' => $this->whenLoaded('loadingResponsible', fn() => $this->loadingResponsible ? [
                'id'   => $this->loadingResponsible->id,
                'name' => $this->loadingResponsible->name ?? $this->loadingResponsible->full_name,
            ] : null),

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
            // Free-text pickup note used only for a client-arranged trip
            // (no assigned_vehicle in that case) — replaces the Log's
            // hardcoded "Client to pick" dropdown entry.
            'vehicle_note' => $this->vehicle_note,

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

            // Progress shown to the requester as steps (replaces the old
            // notifications): submitted → approved → driver/vehicle
            // allocated → loading → on the way → delivered.
            // A delivery the driver couldn't complete, and what the lead chose.
            'delivery_failure' => $this->delivery_failed_at ? [
                'reason'     => $this->delivery_failure_reason,
                'at'         => $this->delivery_failed_at,
                'resolution' => $this->failure_resolution ?: 'pending',
            ] : null,

            // How loading went against the plan (fast / on time / over).
            'loading_summary' => $this->loadingSummary(),

            'steps' => $this->buildSteps(),
        ];
    }

    /**
     * Compares the time loading actually took with the planned minutes, and
     * the finish time with the "must depart by" time. Null until loading has
     * both a start and a finish.
     */
    private function loadingSummary(): ?array
    {
        if (!$this->loading_started_at || !$this->loading_ended_at) {
            return null;
        }

        $actual   = (int) round(($this->loading_ended_at->timestamp - $this->loading_started_at->timestamp) / 60);
        $planned  = $this->estimated_loading_minutes !== null ? (int) $this->estimated_loading_minutes : null;
        $diff     = $planned !== null ? $actual - $planned : null;

        $lateBy = null;
        if ($this->departure_by) {
            $lateBy = (int) round(($this->loading_ended_at->timestamp - $this->departure_by->timestamp) / 60);
        }

        return [
            'actual_minutes'  => $actual,
            'planned_minutes' => $planned,
            'difference_minutes' => $diff,
            'status' => $diff === null ? null : ($diff < 0 ? 'fast' : ($diff > 0 ? 'over' : 'on_time')),
            // Positive = finished loading after the depart-by time.
            'minutes_after_departure_by' => $lateBy,
            'text' => $this->loadingSummaryText($actual, $planned, $diff),
        ];
    }

    private function loadingSummaryText(int $actual, ?int $planned, ?int $diff): string
    {
        $text = "Loaded in {$actual} min";
        if ($planned === null) {
            return $text;
        }
        if ($diff === 0) {
            return "{$text}, exactly as planned ({$planned} min)";
        }
        $abs = abs($diff);
        return $diff < 0
            ? "{$text} — {$abs} min faster than the {$planned} min planned"
            : "{$text} — {$abs} min over the {$planned} min planned";
    }

    /**
     * Derived purely from timestamps/people already on the trip request, so
     * there's nothing extra to store or keep in sync. `done` steps carry the
     * time they happened; the first not-done step is flagged `current`.
     */
    private function buildSteps(): array
    {
        $personName = fn ($p) => $p ? ($p->name ?? $p->full_name ?? null) : null;
        $by = fn (?string $n) => $n ? "by {$n}" : null;

        $requester = $this->relationLoaded('requestedBy') ? $personName($this->requestedBy) : null;
        $approver  = $this->relationLoaded('approvedBy') ? $personName($this->approvedBy) : null;

        $steps = [[
            'key' => 'requested', 'label' => 'Request submitted',
            'detail' => $by($requester), 'at' => $this->created_at, 'done' => true,
        ]];

        if ($this->status === 'rejected') {
            $steps[] = [
                'key' => 'rejected', 'label' => 'Request rejected',
                'detail' => trim(implode(' — ', array_filter([$by($approver), $this->rejection_reason]))) ?: null,
                'at' => $this->approved_at, 'done' => true, 'failed' => true,
            ];
            return $this->markCurrent($steps);
        }

        $steps[] = [
            'key' => 'approved', 'label' => 'Approved by Logistics',
            'detail' => $by($approver), 'at' => $this->approved_at, 'done' => (bool) $this->approved_at,
        ];

        // Allocation: a company trip gets a driver + vehicle; a client
        // pickup just has a note on who is collecting.
        if ($this->transport_arrangement === 'client') {
            $allocDetail = $this->vehicle_note ? "Client collecting — {$this->vehicle_note}" : 'Client will collect';
            $allocLabel  = 'Client pickup arranged';
        } else {
            $driver  = $this->relationLoaded('assignedDriver') && $this->assignedDriver
                ? $personName($this->assignedDriver->employee) : null;
            $vehicle = $this->relationLoaded('assignedVehicle') && $this->assignedVehicle
                ? ($this->assignedVehicle->plate_number ?? $this->assignedVehicle->vehicle_id) : null;
            $allocLabel  = 'Driver and vehicle allocated';
            $allocDetail = $this->assigned_at
                ? trim(implode(' · ', array_filter([
                    $driver ? "Driver: {$driver}" : null,
                    $vehicle ? "Vehicle: {$vehicle}" : null,
                ]))) ?: null
                : null;
        }
        $steps[] = [
            'key' => 'assigned', 'label' => $allocLabel,
            'detail' => $allocDetail, 'at' => $this->assigned_at, 'done' => (bool) $this->assigned_at,
        ];

        // Loading steps only exist for trips using the deadline calculation.
        if ($this->loading_start_by) {
            $steps[] = [
                'key' => 'loading_started', 'label' => 'Loading started',
                'detail' => null, 'at' => $this->loading_started_at, 'done' => (bool) $this->loading_started_at,
            ];
            $steps[] = [
                'key' => 'loading_ended', 'label' => 'Loading finished',
                'detail' => ($s = $this->loadingSummary()) ? $s['text'] : null, 'at' => $this->loading_ended_at, 'done' => (bool) $this->loading_ended_at,
            ];
        }

        $steps[] = [
            'key' => 'in_transit', 'label' => 'On the way',
            'detail' => null, 'at' => $this->started_at, 'done' => (bool) $this->started_at,
        ];
        $steps[] = [
            'key' => 'completed', 'label' => 'Delivered',
            'detail' => null, 'at' => $this->completed_at, 'done' => (bool) $this->completed_at,
        ];

        // Driver couldn't deliver and the lead hasn't decided yet.
        if ($this->delivery_failed_at && !$this->failure_resolution && $this->status === 'in_transit') {
            $steps = array_values(array_filter($steps, fn ($s) => $s['key'] !== 'completed'));
            $steps[] = [
                'key' => 'delivery_failed', 'label' => 'Delivery not completed — Logistics is deciding next steps',
                'detail' => $this->delivery_failure_reason, 'at' => $this->delivery_failed_at,
                'done' => true, 'failed' => true,
            ];
        }

        if ($this->status === 'cancelled') {
            $steps = array_values(array_filter($steps, fn ($s) => $s['done']));
            $steps[] = [
                'key' => 'cancelled', 'label' => 'Request cancelled',
                'detail' => null, 'at' => $this->updated_at, 'done' => true, 'failed' => true,
            ];
        }

        return $this->markCurrent($steps);
    }

    private function markCurrent(array $steps): array
    {
        $currentSet = false;
        foreach ($steps as &$s) {
            $s['failed']  = $s['failed'] ?? false;
            $s['current'] = false;
            if (!$s['done'] && !$currentSet) {
                $s['current'] = true;
                $currentSet = true;
            }
        }
        unset($s);
        return $steps;
    }
}
