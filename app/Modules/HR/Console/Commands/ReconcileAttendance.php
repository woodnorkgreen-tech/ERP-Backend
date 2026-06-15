<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Services\AttendanceReconciliationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ReconcileAttendance extends Command
{
    protected $signature = 'attendance:reconcile
        {--from= : First workday (YYYY-MM-DD)}
        {--to= : Last workday (YYYY-MM-DD)}';

    protected $description = 'Create expected absence, leave, holiday, and non-working attendance rows';

    public function handle(AttendanceReconciliationService $service): int
    {
        $from = Carbon::parse($this->option('from') ?: today()->subDays(7)->toDateString());
        $to = Carbon::parse($this->option('to') ?: today()->subDay()->toDateString());

        if ($from->gt($to) || $from->diffInDays($to) > 366) {
            $this->error('The date range must be ordered and no longer than 366 days.');
            return self::FAILURE;
        }

        $created = $service->reconcile($from, $to);
        $this->info("Attendance reconciliation created {$created} expected row(s).");

        return self::SUCCESS;
    }
}
