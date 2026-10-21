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
            $code    = $trip->request_code;
            $dest    = $trip->destination;
            $requester = $trip->requestedBy?->phone;
            $driver    = $trip->assignedDriver?->employee?->phone;
            $driverName = $trip->assignedDriver?->employee?->name
                ?? trim(($trip->assignedDriver?->employee?->first_name ?? '') . ' ' . ($trip->assignedDriver?->employee?->last_name ?? ''));
            $plate   = $trip->assignedVehicle?->plate_number;

            switch ($trip->status) {
                case 'approved':
                    SendSms::dispatchAfterResponse($requester, "Woodnork: Trip {$code} to {$dest} has been approved. A driver and vehicle will be assigned shortly.");
                    break;

                case 'rejected':
                    $why = $trip->rejection_reason ? " Reason: {$trip->rejection_reason}" : '';
                    SendSms::dispatchAfterResponse($requester, "Woodnork: Trip {$code} was not approved.{$why}");
                    break;

                case 'assigned':
                    $when = $trip->required_delivery_at ? ' Needed by ' . $trip->required_delivery_at->format('d M H:i') . '.' : '';
                    SendSms::dispatchAfterResponse($driver, "Woodnork: New trip {$code}. {$trip->pickup_location} to {$dest}.{$when}" . ($plate ? " Vehicle {$plate}." : '') . ' Open the app for details.');
                    SendSms::dispatchAfterResponse($requester, "Woodnork: Trip {$code} assigned" . ($driverName ? " to {$driverName}" : '') . ($plate ? ", vehicle {$plate}" : '') . '.');
                    break;

                case 'completed':
                    SendSms::dispatchAfterResponse($requester, "Woodnork: Trip {$code} to {$dest} is completed.");
                    break;

                case 'cancelled':
                    // Only the driver needs telling, and only if one was assigned.
                    SendSms::dispatchAfterResponse($driver, "Woodnork: Trip {$code} to {$dest} has been cancelled.");
                    break;
            }
        } catch (Throwable $e) {
            Log::warning('Trip SMS failed', ['trip_request_id' => $trip->id, 'error' => $e->getMessage()]);
        }
    }
}
