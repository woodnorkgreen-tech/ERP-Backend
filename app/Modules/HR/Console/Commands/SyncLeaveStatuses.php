<?php

namespace App\Modules\HR\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use Carbon\Carbon;

class SyncLeaveStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hr:sync-leave-statuses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize employee statuses based on their active leave requests';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $today = Carbon::today()->toDateString();
        
        $this->info("Starting Leave Status Synchronization for {$today}");

        // 1. Identify all employees who should be inherently "on-leave" today.
        // That means an APPROVED leave request where today is >= start_date AND today <= end_date.
        $employeesOnLeaveIds = LeaveRequest::query()
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->pluck('employee_id')
            ->unique()
            ->toArray();

        // 2. Transition active employees to on-leave if they are in the list.
        $addedToLeave = 0;
        if (!empty($employeesOnLeaveIds)) {
            $addedToLeave = Employee::whereIn('id', $employeesOnLeaveIds)
                ->where('status', 'active')
                ->update(['status' => 'on-leave']);
        }
        $this->info("Moved {$addedToLeave} employees to 'on-leave' status.");

        // 3. Transition employees back to active if they are marked as 'on-leave' 
        // but no longer have an active leave request covering today.
        $returningCount = 0;
        $employeesToReturn = Employee::where('status', 'on-leave');
        
        if (!empty($employeesOnLeaveIds)) {
            $employeesToReturn->whereNotIn('id', $employeesOnLeaveIds);
        }
        
        $returningCount = $employeesToReturn->update(['status' => 'active']);
        
        $this->info("Returned {$returningCount} employees back to 'active' status.");
        
        $this->info('Synchronization complete.');
        return Command::SUCCESS;
    }
}
