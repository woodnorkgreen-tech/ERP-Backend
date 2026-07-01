<?php

namespace App\Modules\Logistics\Observers;

use App\Modules\Logistics\Models\TripRequest;
use App\Modules\Notifications\Services\NotificationService;

class TripRequestObserver
{
    public function created(TripRequest $trip): void
    {
        NotificationService::send(
            type: 'logistics_trip_requested',
            title: "Trip request {$trip->request_code}",
            message: "A {$trip->priority} priority trip was requested to {$trip->destination}.",
            module: 'logistics',
            urgency: $trip->priority === 'urgent' ? 'critical' : 'info',
            data: ['trip_request_id' => $trip->id, 'url' => "/logistics/trip-requests/{$trip->id}"],
            role: ['Super Admin', 'Logistics', 'Manager'],
        );
    }

    public function updated(TripRequest $trip): void
    {
        if (!$trip->wasChanged('status')) {
            return;
        }

        $users = collect([
            $trip->requestedBy?->user,
            $trip->assignedDriver?->user,
        ])->filter()->all();

        NotificationService::send(
            type: 'logistics_trip_status_changed',
            title: "Trip {$trip->request_code} is {$trip->status}",
            message: "The trip request status changed from {$trip->getOriginal('status')} to {$trip->status}.",
            module: 'logistics',
            data: ['trip_request_id' => $trip->id, 'status' => $trip->status, 'url' => "/logistics/trip-requests/{$trip->id}"],
            users: $users,
        );
    }
}
