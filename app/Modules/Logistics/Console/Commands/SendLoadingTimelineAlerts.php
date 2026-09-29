<?php

namespace App\Modules\Logistics\Console\Commands;

use App\Modules\Logistics\Models\TripRequest;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Kevin's request, the reminder/escalation half: "assign a responsible
 * person and send reminders before these times... flag delays for
 * escalation." Meant to run every few minutes via the scheduler
 * (app/Console/Kernel.php — see the note at the bottom of this file).
 *
 * Only looks at trips that opted into auto-calculation (loading_start_by is
 * set) and aren't already finished/cancelled. For each one it works out
 * where the trip stands against its own timeline (TripRequest::
 * getTimelineStatusAttribute()) and sends at most ONE notification per
 * state transition — loading_alert_state remembers the last state that was
 * actually notified, so a trip sitting in "overdue_loading" for an hour
 * doesn't spam the responsible person every five minutes; it only notifies
 * again if the state changes (e.g. escalates further to overdue_departure).
 */
class SendLoadingTimelineAlerts extends Command
{
    protected $signature = 'logistics:loading-timeline-alerts';

    protected $description = 'Remind the responsible person before loading/departure is due, and escalate overdue loading';

    public function handle(): int
    {
        $trips = TripRequest::with(['loadingResponsible', 'project', 'requestedBy'])
            ->whereNotNull('loading_start_by')
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
            ->whereNull('loading_ended_at')
            ->get();

        if ($trips->isEmpty()) {
            $this->info('No trips currently tracked against a loading timeline.');
            return self::SUCCESS;
        }

        $notified = 0;

        foreach ($trips as $trip) {
            $state = $trip->timeline_status; // due_soon | overdue_loading | overdue_departure | on_track | completed

            // Nothing to say for on_track — that's the default, quiet state.
            if (!in_array($state, ['due_soon', 'overdue_loading', 'overdue_departure'])) {
                continue;
            }

            // Already notified for this exact state — don't repeat until it
            // changes (e.g. due_soon -> overdue_loading is a real escalation
            // and does get a fresh notification).
            if ($trip->loading_alert_state === $state) {
                continue;
            }

            $responsible = $trip->loadingResponsible ?: $trip->requestedBy;
            $projectLabel = $trip->project->title ?? $trip->project->job_number ?? $trip->request_code;

            [$title, $message, $escalate] = match ($state) {
                'due_soon' => [
                    'Loading starts soon',
                    "Loading for {$trip->request_code} ({$projectLabel}) must start by " . $trip->loading_start_by->format('H:i') . '.',
                    false,
                ],
                'overdue_loading' => [
                    'Loading overdue',
                    "Loading for {$trip->request_code} ({$projectLabel}) was due to start by " . $trip->loading_start_by->format('H:i') . ' and has not been marked started.',
                    true,
                ],
                'overdue_departure' => [
                    'Departure overdue',
                    "{$trip->request_code} ({$projectLabel}) was due to depart by " . $trip->departure_by->format('H:i') . ' and has not left.',
                    true,
                ],
            };

            // Assumes Employee has a `user()` relation back to the login
            // account (the inverse of User::employee(), used throughout
            // TripRequestController already) — if your Employee model
            // names it differently, adjust this one line.
            $recipients = collect([$responsible?->user])->filter()->values();

            if ($recipients->isNotEmpty()) {
                NotificationService::send(
                    type: 'logistics_loading_' . $state,
                    title: $title,
                    message: $message,
                    module: 'logistics',
                    data: [
                        'trip_request_id' => $trip->id,
                        'request_code'    => $trip->request_code,
                        'project_id'      => $trip->project_id,
                        'loading_start_by' => $trip->loading_start_by?->toISOString(),
                        'departure_by'      => $trip->departure_by?->toISOString(),
                        'url' => "/logistics/trip-requests/{$trip->id}",
                    ],
                    users: $recipients->all(),
                );
            }

            // Escalation also goes to the logistics team, not just the
            // person who's already late — that's the "flagging delays for
            // escalation" half of the request.
            if ($escalate) {
                $escalationTargets = \App\Models\User::role(['Logistics', 'Logistics Manager', 'Super Admin', 'Admin'])->get();
                if ($escalationTargets->isNotEmpty()) {
                    NotificationService::send(
                        type: 'logistics_loading_escalation',
                        title: 'Escalation: ' . $title,
                        message: $message . ' Responsible: ' . ($responsible->name ?? $responsible->full_name ?? 'Unassigned') . '.',
                        module: 'logistics',
                        data: [
                            'trip_request_id' => $trip->id,
                            'request_code'    => $trip->request_code,
                            'url' => "/logistics/trip-requests/{$trip->id}",
                        ],
                        users: $escalationTargets->all(),
                    );
                }
            }

            $trip->update(['loading_alert_state' => $state]);
            $notified++;
        }

        $this->info("Checked {$trips->count()} trip(s), sent {$notified} new alert(s).");
        return self::SUCCESS;
    }
}
