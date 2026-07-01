<?php

namespace App\Console\Commands;

use App\Modules\HR\Models\AttendanceDeviceRawEvent;
use App\Modules\HR\Models\AttendanceRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupConflictingAttendanceEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:cleanup-conflicting-events 
                            {--date= : Specific date to cleanup (YYYY-MM-DD)}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Bypass confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up conflicting attendance events caused by timezone changes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->option('date');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        if (!$date) {
            $date = $this->ask('Enter the date to cleanup (YYYY-MM-DD)', '2026-06-15');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('Invalid date format. Use YYYY-MM-DD');
            return 1;
        }

        $this->info("Analyzing events for date: {$date}");
        $this->info("Dry run: " . ($dryRun ? 'YES' : 'NO'));

        // Find events for the specified date
        $events = AttendanceDeviceRawEvent::query()
            ->whereDate('event_datetime', $date)
            ->orderBy('person_id')
            ->orderBy('event_datetime')
            ->get();

        if ($events->isEmpty()) {
            $this->warn("No events found for date: {$date}");
            return 0;
        }

        $this->info("Found {$events->count()} events for {$date}");

        // Group by person_id to identify potential duplicates
        $grouped = $events->groupBy('person_id');
        $conflicts = [];

        foreach ($grouped as $personId => $personEvents) {
            if ($personEvents->count() > 2) {
                // More than 2 events for a single person on one day is suspicious
                $conflicts[$personId] = $personEvents;
            }
        }

        if (empty($conflicts)) {
            $this->info("No obvious conflicts found (no person with >2 events)");
            
            // Still show all events for review
            $this->table(
                ['ID', 'Person ID', 'Person Name', 'Event DateTime', 'Source'],
                $events->map(fn ($e) => [
                    $e->id,
                    $e->person_id,
                    $e->person_name,
                    $e->event_datetime,
                    $e->source,
                ])
            );

            if (!$dryRun && !$force) {
                if (!$this->confirm("Do you want to delete ALL events for {$date}?")) {
                    $this->info('Operation cancelled');
                    return 0;
                }
            }

            if ($dryRun) {
                $this->warn("DRY RUN: Would delete {$events->count()} events");
                return 0;
            }

            $deleted = AttendanceDeviceRawEvent::query()
                ->whereDate('event_datetime', $date)
                ->delete();

            $this->info("Deleted {$deleted} events");
            
            // Also clean up attendance records for this date
            $recordsDeleted = AttendanceRecord::query()
                ->whereDate('date', $date)
                ->where('is_manual', false)
                ->delete();
            
            $this->info("Deleted {$recordsDeleted} attendance records");

            return 0;
        }

        // Show conflicts
        $this->warn("Found " . count($conflicts) . " persons with potential conflicts:");
        
        foreach ($conflicts as $personId => $personEvents) {
            $this->newLine();
            $this->warn("Person ID: {$personId} ({$personEvents->first()->person_name}) - {$personEvents->count()} events");
            $this->table(
                ['ID', 'Event DateTime', 'Source'],
                $personEvents->map(fn ($e) => [$e->id, $e->event_datetime, $e->source])
            );
        }

        if (!$dryRun && !$force) {
            if (!$this->confirm("Do you want to delete ALL events for {$date}?")) {
                $this->info('Operation cancelled');
                return 0;
            }
        }

        if ($dryRun) {
            $this->warn("DRY RUN: Would delete {$events->count()} events");
            return 0;
        }

        // Delete all events for the date
        $deleted = AttendanceDeviceRawEvent::query()
            ->whereDate('event_datetime', $date)
            ->delete();

        $this->info("Deleted {$deleted} events");
        
        // Also clean up attendance records for this date
        $recordsDeleted = AttendanceRecord::query()
            ->whereDate('date', $date)
            ->where('is_manual', false)
            ->delete();
        
        $this->info("Deleted {$recordsDeleted} attendance records");

        $this->info("Cleanup complete. Please re-sync attendance for this date.");

        return 0;
    }
}
