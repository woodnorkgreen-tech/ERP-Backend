<?php

namespace App\Console\Commands;

use App\Models\LogisticsLogEntry;
use App\Models\User;
use App\Modules\Logistics\Models\TripRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time move of every existing logistics_log_entries row into
 * trip_requests, as the last step of merging the standalone Logistics Log
 * into Trip Request. Field mapping and the judgment calls behind it:
 *
 *  - project_enquiry_id -> project_id           (same table, no remapping)
 *  - site               -> destination          (the Log never had a
 *                          separate pickup location, so pickup_location is
 *                          filled with a placeholder — see --pickup-default)
 *  - loading_time / departure (datetime) -> loading_time / departure_time
 *                          as 'HH:MM' strings, matching the string format
 *                          already used for these fields elsewhere in this
 *                          module (Delivery::departure_time, etc.)
 *  - setdown_time       -> setdown_time          (already a datetime both
 *                          sides, copied as-is)
 *  - vehicle_allocated  -> vehicle_note, and also decides
 *                          transport_arrangement: a value containing
 *                          "client" (case-insensitive) becomes
 *                          transport_arrangement = 'client'; anything else
 *                          stays 'company'. Either way it lands in
 *                          vehicle_note rather than assigned_vehicle_id,
 *                          because the Log stored a free-text plate/name,
 *                          not a real fleet vehicle_id, and guessing a
 *                          fleet match here risks attaching history to the
 *                          wrong vehicle.
 *  - driver             -> folded into assignment_notes as free text, for
 *                          the same reason (no reliable driver_id to
 *                          resolve a name against).
 *  - project_officer_incharge -> folded into notes, since trip_requests has
 *                          no dedicated "who's in charge" field distinct
 *                          from requested_by_id.
 *  - remarks            -> appended into notes.
 *  - status: open -> assigned (already had driver/vehicle/times, so it was
 *                          past "requested"); completed -> completed;
 *                          closed -> cancelled. The original Log status is
 *                          always preserved in notes for anyone auditing
 *                          this later.
 *  - created_by (a users.id) -> requested_by_id (an employees.id) via
 *                          User::find($id)->employee->id. A row whose
 *                          creator has no linked employee record is
 *                          SKIPPED, not guessed — it's listed in the
 *                          command's summary for manual follow-up instead.
 *
 * Defaults to a dry run: it reports what it WOULD do and writes nothing
 * until you pass --commit. Run it once, read the summary, then run again
 * with --commit.
 */
class MigrateLogisticsLogToTripRequests extends Command
{
    protected $signature = 'logistics:migrate-log-to-trip-requests
        {--commit : Actually write the rows. Without this flag, nothing is written.}
        {--pickup-default=Workshop / Warehouse (migrated — confirm actual pickup) : Placeholder pickup_location for migrated rows, since the Log never recorded one.}';

    protected $description = 'One-time copy of logistics_log_entries into trip_requests (Logistics Log -> Trip Request merge)';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $pickupDefault = (string) $this->option('pickup-default');

        $entries = LogisticsLogEntry::with('projectEnquiry')->orderBy('id')->get();

        if ($entries->isEmpty()) {
            $this->info('No logistics_log_entries rows found — nothing to migrate.');
            return self::SUCCESS;
        }

        $this->info(($commit ? 'Migrating ' : 'DRY RUN — would migrate ') . $entries->count() . ' log entr' . ($entries->count() === 1 ? 'y' : 'ies') . '...');

        $migrated = 0;
        $skippedNoEmployee = [];
        $skippedNoProject = [];

        DB::beginTransaction();

        try {
            foreach ($entries as $entry) {
                if (!$entry->project_enquiry_id || !$entry->projectEnquiry) {
                    $skippedNoProject[] = $entry->id;
                    continue;
                }

                $user = $entry->created_by ? User::find($entry->created_by) : null;
                $employeeId = $user?->employee?->id;

                if (!$employeeId) {
                    $skippedNoEmployee[] = ['log_id' => $entry->id, 'user_id' => $entry->created_by];
                    continue;
                }

                $vehicleAllocated = (string) ($entry->vehicle_allocated ?? '');
                $isClientPickup = stripos($vehicleAllocated, 'client') !== false;

                $notesParts = [];
                if ($entry->project_officer_incharge) {
                    $notesParts[] = 'PO in charge (migrated): ' . $entry->project_officer_incharge;
                }
                if ($entry->driver) {
                    $notesParts[] = 'Driver (migrated): ' . $entry->driver;
                }
                if ($entry->remarks) {
                    $notesParts[] = $entry->remarks;
                }
                $notesParts[] = "Migrated from Logistics Log #{$entry->id} (original status: {$entry->status})";

                $statusMap = [
                    'open'      => 'assigned',
                    'completed' => 'completed',
                    'closed'    => 'cancelled',
                ];
                $status = $statusMap[$entry->status] ?? 'assigned';

                $payload = [
                    'context_type'          => 'project',
                    'transport_arrangement' => $isClientPickup ? 'client' : 'company',
                    'project_id'            => $entry->project_enquiry_id,
                    'delivery_type_label'   => 'Project Logistics (migrated)',
                    'requested_by_id'       => $employeeId,
                    'priority'              => 'medium',
                    'pickup_location'       => $pickupDefault,
                    'destination'           => $entry->site,
                    'required_date'         => $entry->departure?->toDateString() ?? $entry->loading_time?->toDateString() ?? now()->toDateString(),
                    'loading_time'          => $entry->loading_time?->format('H:i'),
                    'departure_time'        => $entry->departure?->format('H:i'),
                    'setdown_time'          => $entry->setdown_time,
                    'vehicle_note'          => $vehicleAllocated ?: null,
                    'assignment_notes'      => $entry->driver ? ('Driver (migrated): ' . $entry->driver) : null,
                    'notes'                 => implode("\n", $notesParts),
                    'status'                => $status,
                    'created_at'            => $entry->created_at,
                    'updated_at'            => $entry->updated_at,
                ];

                if ($commit) {
                    TripRequest::create($payload);
                }

                $migrated++;
            }

            if ($commit) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Migration aborted, nothing was written: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(($commit ? 'Migrated' : 'Would migrate') . " {$migrated} of {$entries->count()} entries.");

        if (!empty($skippedNoEmployee)) {
            $this->warn(count($skippedNoEmployee) . ' entr(ies) skipped — creator has no linked employee record:');
            foreach ($skippedNoEmployee as $s) {
                $this->line("  - logistics_log_entries.id={$s['log_id']} (users.id={$s['user_id']})");
            }
        }

        if (!empty($skippedNoProject)) {
            $this->warn(count($skippedNoProject) . ' entr(ies) skipped — no matching project:');
            $this->line('  IDs: ' . implode(', ', $skippedNoProject));
        }

        if (!$commit) {
            $this->comment('Nothing was written. Re-run with --commit once this looks right.');
        }

        return self::SUCCESS;
    }
}
