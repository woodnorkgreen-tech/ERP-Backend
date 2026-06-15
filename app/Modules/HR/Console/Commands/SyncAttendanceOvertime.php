<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Services\AttendanceOvertimeService;
use App\Modules\HR\Services\AttendanceScheduleService;
use Illuminate\Console\Command;

class SyncAttendanceOvertime extends Command
{
    protected $signature = 'attendance:sync-overtime
        {--from= : First attendance date to evaluate}
        {--to= : Last attendance date to evaluate}';

    protected $description = 'Build or refresh attendance-derived overtime proposals';

    public function handle(
        AttendanceScheduleService $scheduleService,
        AttendanceOvertimeService $overtimeService
    ): int {
        $query = AttendanceRecord::query()
            ->with('employee')
            ->whereNotNull('clock_in')
            ->whereNotNull('clock_out')
            ->orderBy('id');

        if ($this->option('from')) {
            $query->whereDate('date', '>=', $this->option('from'));
        }
        if ($this->option('to')) {
            $query->whereDate('date', '<=', $this->option('to'));
        }

        $processed = 0;
        $query->chunkById(200, function ($records) use ($scheduleService, $overtimeService, &$processed) {
            foreach ($records as $record) {
                $schedule = $scheduleService->forEmployee($record->employee, $record->date);
                $overtimeService->syncProposal($record, $schedule);
                $processed++;
            }
        });

        $this->info("Evaluated {$processed} attendance record(s) for overtime.");

        return self::SUCCESS;
    }
}
