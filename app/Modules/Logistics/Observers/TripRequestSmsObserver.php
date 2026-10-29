<?php

namespace App\Modules\Logistics\Observers;

use App\Modules\Logistics\Jobs\SendSms;
use App\Modules\Logistics\Jobs\SendWhatsApp;
use App\Modules\Logistics\Models\TripRequest;
use App\Modules\Logistics\Services\SmsService;
use App\Modules\Logistics\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Texts the people who need to act when a trip request changes status:
 * WhatsApp when it is switched on (with an SMS fallback), otherwise SMS.
 * In-app notifications are untouched. It runs after the response is sent
 * and never breaks the save.
 */
class TripRequestSmsObserver
{
    public function updated(TripRequest $trip): void
    {
        if (!$trip->wasChanged('status')) {
            return;
        }

        $this->notify($trip);
    }

    /**
     * One person, one message: WhatsApp template if enabled, else SMS.
     *
     * @param  string[]  $params  fills the template's {{1}}, {{2}}…
     */
    private function send(?string $phone, string $templateKey, array $params, string $smsText, ?string $name = null): void
    {
        if (WhatsAppService::enabled()) {
            if (config('logistics.whatsapp.mode') === 'notice') {
                // One flexible template: "Hello {{1}}, {{2}}. Open the ERP app for more details."
                $msg = preg_replace('/^Woodnork:\s*/', '', $smsText);
                $msg = preg_replace('/\s*Open the app for details\.?$/', '', $msg);
                SendWhatsApp::dispatchAfterResponse($phone, 'notice', [], trim($msg), $name);
                return;
            }
            SendWhatsApp::dispatchAfterResponse(
                $phone,
                (string) config("logistics.whatsapp.templates.{$templateKey}"),
                $params,
                $smsText
            );
            return;
        }
        SendSms::dispatchAfterResponse($phone, $smsText);
    }

    private function notify(TripRequest $trip): void
    {
        if (!WhatsAppService::enabled() && !SmsService::enabled()) {
            return;
        }

        try {
            $trip->loadMissing('project.client', 'requestedBy', 'assignedDriver.employee', 'assignedVehicle');

            $code      = $trip->request_code;
            $dest      = $trip->destination;
            $requester = $trip->requestedBy?->phone;
            $reqName   = $trip->requestedBy?->name
                ?? trim(($trip->requestedBy?->first_name ?? '') . ' ' . ($trip->requestedBy?->last_name ?? ''));
            $driver    = $trip->assignedDriver?->employee?->phone;
            $driverName = $trip->assignedDriver?->employee?->name
                ?? trim(($trip->assignedDriver?->employee?->first_name ?? '') . ' ' . ($trip->assignedDriver?->employee?->last_name ?? ''));
            $plate     = $trip->assignedVehicle?->plate_number;

            // "Project: X (JOB-12) | Client: Y | Venue: Z", skipping what is missing.
            $p     = $trip->project;
            $parts = [];
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

            $routeText = trim(($trip->pickup_location ?: '') . ($trip->pickup_location && $dest ? ' to ' : '') . ($dest ?: ''));
            $route     = $routeText !== '' ? " Route: {$routeText}." : '';

            $needed = $trip->time_tbc
                ? ' Time: to be communicated.'
                : ($trip->required_delivery_at ? ' Needed by ' . $trip->required_delivery_at->format('D d M, H:i') . '.' : '');
            $neededShort = $trip->time_tbc
                ? 'To be communicated'
                : ($trip->required_delivery_at ? $trip->required_delivery_at->format('D d M, H:i') : '-');

            // Template values (single line each).
            $projectVal = $p?->title ? $p->title . ($p->job_number ? " ({$p->job_number})" : '') : ($trip->delivery_type_label ?: '-');
            $clientVal  = $p?->client?->full_name ?: '-';
            $routeVal   = $routeText !== '' ? $routeText : '-';
            $update = fn (string $what) => [$code, $what, $projectVal, $clientVal, $routeVal];

            switch ($trip->status) {
                case 'approved':
                    $this->send($requester, 'update', $update('approved'),
                        "Woodnork: Trip {$code} approved.{$about}{$route}{$needed} A driver and vehicle will be assigned shortly.", $reqName);
                    break;

                case 'rejected':
                    $why = $trip->rejection_reason ? " Reason: {$trip->rejection_reason}" : '';
                    $this->send($requester, 'update', $update('not approved' . ($trip->rejection_reason ? " ({$trip->rejection_reason})" : '')),
                        "Woodnork: Trip {$code} was not approved.{$about}{$why}", $reqName);
                    break;

                case 'assigned':
                    $veh = $plate ? " Vehicle: {$plate}." : '';
                    $who = $reqName ? " Requested by {$reqName}" . ($requester ? " ({$requester})" : '') . '.' : '';
                    $this->send($driver, 'driver_assigned', [
                        $code, $projectVal, $clientVal, $routeVal, $neededShort,
                        $plate ?: 'To be confirmed',
                        $reqName ? $reqName . ($requester ? " ({$requester})" : '') : '-',
                    ], "Woodnork: New trip {$code}.{$about}{$route}{$needed}{$veh}{$who} Open the app for details.", $driverName);
                    $assignedTo = 'assigned' . ($driverName ? " to {$driverName}" : '') . ($plate ? " (vehicle {$plate})" : '');
                    $this->send($requester, 'update', $update($assignedTo),
                        "Woodnork: Trip {$code} assigned.{$about}{$route}{$needed}" . ($driverName ? " Driver: {$driverName}." : '') . $veh, $reqName);
                    break;

                case 'completed':
                    $this->send($requester, 'update', $update('completed'),
                        "Woodnork: Trip {$code} completed.{$about}{$route}", $reqName);
                    break;

                case 'cancelled':
                    // Only the driver needs telling, and only if one was assigned.
                    $this->send($driver, 'update', $update('cancelled'),
                        "Woodnork: Trip {$code} has been cancelled.{$about}{$route}{$needed}", $driverName);
                    break;
            }
        } catch (Throwable $e) {
            Log::warning('Trip notification failed', ['trip_request_id' => $trip->id, 'error' => $e->getMessage()]);
        }
    }
}
