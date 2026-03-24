<?php

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;

class LeaveRequestObserver
{
    /**
     * Fires whenever a LeaveRequest record is saved/updated.
     * Intelligently syncs the linked employee's status.
     */
    public function updated(LeaveRequest $leaveRequest): void
    {
        $this->syncEmployeeStatus($leaveRequest);
    }

    /**
     * Core logic: determine if the employee should currently be 'on-leave'.
     * Runs on every approval, rejection, or cancellation.
     */
    protected function syncEmployeeStatus(LeaveRequest $leaveRequest): void
    {
        $employee = $leaveRequest->employee;

        if (!$employee) {
            return;
        }

        // Do not interfere with terminated or inactive employees
        if (in_array($employee->status, ['terminated', 'inactive'])) {
            return;
        }

        $today = Carbon::today()->toDateString();

        // Check if this employee has ANY approved leave covering today
        $isOnLeaveToday = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->exists();

        $newStatus = $isOnLeaveToday ? 'on-leave' : 'active';

        if ($employee->status !== $newStatus) {
            $employee->update(['status' => $newStatus]);
        }
    }
}
