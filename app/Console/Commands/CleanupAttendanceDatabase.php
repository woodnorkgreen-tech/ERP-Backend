<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupAttendanceDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:cleanup-database 
                            {--all : Clean all attendance tables}
                            {--raw-events : Clean raw device events only}
                            {--records : Clean attendance records only}
                            {--sync-logs : Clean sync logs only}
                            {--sync-requests : Clean sync requests only}
                            {--schedules : Clean work schedules only}
                            {--holidays : Clean holidays only}
                            {--overtime : Clean overtime proposals only}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Bypass confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up attendance module database tables';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $all = $this->option('all');

        $tables = [];

        if ($all) {
            $tables = $this->getAllAttendanceTables();
        } else {
            if ($this->option('raw-events')) {
                $tables[] = 'attendance_device_raw_events';
            }
            if ($this->option('records')) {
                $tables[] = 'attendance_records';
            }
            if ($this->option('sync-logs')) {
                $tables[] = 'attendance_device_sync_logs';
            }
            if ($this->option('sync-requests')) {
                $tables[] = 'attendance_sync_requests';
            }
            if ($this->option('schedules')) {
                $tables[] = 'attendance_work_schedules';
                $tables[] = 'attendance_schedule_assignments';
            }
            if ($this->option('holidays')) {
                $tables[] = 'attendance_holidays';
            }
            if ($this->option('overtime')) {
                $tables[] = 'attendance_overtime_proposals';
            }
        }

        if (empty($tables)) {
            $this->warn('No tables specified. Use --all or specific table flags.');
            $this->info('Available options:');
            $this->info('  --all           Clean all attendance tables');
            $this->info('  --raw-events    Clean raw device events only');
            $this->info('  --records       Clean attendance records only');
            $this->info('  --sync-logs     Clean sync logs only');
            $this->info('  --sync-requests Clean sync requests only');
            $this->info('  --schedules     Clean work schedules only');
            $this->info('  --holidays      Clean holidays only');
            $this->info('  --overtime      Clean overtime proposals only');
            return 0;
        }

        // Filter to only existing tables
        $existingTables = array_filter($tables, fn ($table) => Schema::hasTable($table));

        if (empty($existingTables)) {
            $this->warn('None of the specified tables exist in the database.');
            return 0;
        }

        $this->info('Tables to be cleaned:');
        foreach ($existingTables as $table) {
            $count = DB::table($table)->count();
            $this->line("  - {$table}: {$count} records");
        }

        if (!$dryRun && !$force) {
            if (!$this->confirm('Do you want to delete all records from these tables?')) {
                $this->info('Operation cancelled');
                return 0;
            }
        }

        if ($dryRun) {
            $this->warn('DRY RUN: No records will be deleted');
            return 0;
        }

        $totalDeleted = 0;
        foreach ($existingTables as $table) {
            try {
                $deleted = DB::table($table)->delete();
                $totalDeleted += $deleted;
                $this->info("Deleted {$deleted} records from {$table}");
                
                // Reset auto-increment
                DB::statement("DELETE FROM sqlite_sequence WHERE name='{$table}'");
            } catch (\Exception $e) {
                $this->error("Failed to delete from {$table}: {$e->getMessage()}");
            }
        }

        $this->info("Cleanup complete. Total records deleted: {$totalDeleted}");
        return 0;
    }

    private function getAllAttendanceTables(): array
    {
        return [
            'attendance_device_raw_events',
            'attendance_records',
            'attendance_device_sync_logs',
            'attendance_sync_requests',
            'attendance_work_schedules',
            'attendance_schedule_assignments',
            'attendance_holidays',
            'attendance_overtime_proposals',
        ];
    }
}
