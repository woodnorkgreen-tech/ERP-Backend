<?php

namespace App\Modules\Logistics\Observers;

use App\Modules\Logistics\Jobs\SendSms;
use App\Modules\Logistics\Models\TripRequest;
use App\Modules\Logistics\Services\SmsService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SMS only. In-app notifications are untouched; this just texts the people
 * who need to act when a trip request changes status.
 */
class TripRequestSmsObserver
{
    public function updated(TripRequest $trip): void
    {
        if (!$trip->wasChanged('status')) {
            return;
        }

        $this->sendSms($trip);
    }

    /**
     * Text the people who need to act or know. Only the key moments, since
     * every SMS costs credit; everything else stays in the app. It runs after
     * the response is sent and never breaks the save.
     */
    private function sendSms(TripRequest $trip): void
    {
        if (!SmsService::enabled()) {
            return;
        }

        try {
            $trip->loadMissing('project.client', 'requestedBy', 'assignedDriver.employee', 'assignedVehicle');

            $code      = $trip->request_code;
            $dest      = $trip->destination;
            $requester = $trip->requestedBy?->phone;
            $driver    = $trip->assignedDriver?->employee?->phone;
            $driverName = $trip->assignedDriver?->employee?->name
                ?? trim(($trip->assignedDriver?->employee?->first_name ?? '') . ' ' . ($trip->assignedDriver?->employee?->last_name ?? ''));
            $plate     = $trip->assignedVehicle?->plate_number;
            $reqName   = $trip->requestedBy?->name
                ?? trim(($trip->requestedBy?->first_name ?? '') . ' ' . ($trip->requestedBy?->last_name ?? ''));

            // "Project: X (JOB-12) | Client: Y | Venue: Z", skipping what is missing.
            $p       = $trip->project;
            $parts   = [];
            if ($p?->title) {
                $parts[] = 'Project: ' . $p->title . ($p->job_number ? " ({$p->job_number})" : '');
            } elseif ($trip->delivery_type_label) {
                $parts[] = $trip->delivery_type_label;
            }
            if ($p?->client?->full_name) {
                $parts[] = 'Client: ' . $p->client->full_name;
            }
            if ($p?->venue) {
                $parts[] = 'Venue: ' . $p->venue;
            }
            $about = implode(' | ', $parts);
            $about = $about !== '' ? " {$about}." : '';

            $route = trim(($trip->pickup_location ?: '') . ($trip->pickup_location && $dest ? ' to ' : '') . ($dest ?: ''));
            $route = $route !== '' ? " Route: {$route}." : '';

            $needed = $trip->time_tbc
                ? ' Time: to be communicated.'
                : ($trip->required_delivery_at ? ' Needed by ' . $trip->required_delivery_at->format('D d M, H:i') . '.' : '');

            switch ($trip->status) {
                case 'approved':
                    SendSms::dispatchAfterResponse($requester, "Woodnork: Trip {$code} approved.{$about}{$route}{$needed} A driver and vehicle will be assigned shortly.");
                    break;

                case 'rejected':
                    $why = $trip->rejection_reason ? " Reason: {$trip->rejection_reason}" : '';
                    SendSms::dispatchAfterResponse($requester, "Woodnork: Trip {$code} was not approved.{$about}{$why}");
                    break;

                case 'assigned':
                    $veh = $plate ? " Vehicle: {$plate}." : '';
                    $who = $reqName ? " Requested by {$reqName}" . ($requester ? " ({$requester})" : '') . '.' : '';
                    SendSms::dispatchAfterResponse($driver, "Woodnork: New trip {$code}.{$about}{$route}{$needed}{$veh}{$who} Open the app for details.");
                    SendSms::dispatchAfterResponse($requester, "Woodnork: Trip {$code} assigned.{$about}{$route}{$needed}" . ($driverName ? " Driver: {$driverName}." : '') . $veh);
                    break;

                case 'completed':
                    SendSms::dispatchAfterResponse($requester, "Woodnork: Trip {$code} completed.{$about}{$route}");
                    break;

                case 'cancelled':
                    // Only the driver needs telling, and only if one was assigned.
                    SendSms::dispatchAfterResponse($driver, "Woodnork: Trip {$code} has been cancelled.{$about}{$route}{$needed}");
                    break;
            }
        } catch (Throwable $e) {
            Log::warning('Trip SMS failed', ['trip_request_id' => $trip->id, 'error' => $e->getMessage()]);
        }
    }
}
