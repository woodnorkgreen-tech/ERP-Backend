<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Services\AttendanceReprocessingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ReprocessAttendance extends Command
{
    protected $signature = 'attendance:reprocess
        {--from= : First attendance date (YYYY-MM-DD)}
        {--to= : Last attendance date (YYYY-MM-DD)}
        {--person-id= : Optional Hikvision person ID}';

    protected $description = 'Rebuild attendance records from stored raw device events';

    public function handle(AttendanceReprocessingService $service): int
    {
        $from = Carbon::parse($this->option('from') ?: today()->subDays(30)->toDateString());
        $to = Carbon::parse($this->option('to') ?: today()->toDateString());

        if ($from->gt($to) || $from->diffInDays($to) > 366) {
            $this->error('The date range must be ordered and no longer than 366 days.');
            return self::FAILURE;
        }

        $result = $service->reprocess($from, $to, $this->option('person-id'));
        $this->info("Processed {$result->recordsProcessed} record(s); {$result->unmappedPersonCount} unmapped; {$result->failedPersonDayCount} failed.");

        return $result->failedPersonDayCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
