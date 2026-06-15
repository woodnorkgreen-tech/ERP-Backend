<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceScheduleAssignment;
use App\Modules\HR\Models\AttendanceWorkSchedule;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class AttendanceScheduleService
{
    public function forEmployee(Employee $employee, Carbon $workDate): AttendanceWorkSchedule
    {
        if (
            !Schema::hasTable('attendance_work_schedules')
            || !Schema::hasTable('attendance_schedule_assignments')
        ) {
            return $this->configurationSchedule();
        }

        $assignment = AttendanceScheduleAssignment::query()
            ->with('schedule')
            ->where(function ($query) use ($employee) {
                $query->where('employee_id', $employee->id)
                    ->orWhere(function ($departmentQuery) use ($employee) {
                        $departmentQuery->whereNull('employee_id')
                            ->where('department_id', $employee->department_id);
                    });
            })
            ->whereDate('effective_from', '<=', $workDate)
            ->where(function ($query) use ($workDate) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $workDate);
            })
            ->orderByRaw('CASE WHEN employee_id = ? THEN 0 ELSE 1 END', [$employee->id])
            ->latest('effective_from')
            ->first();

        return $assignment?->schedule
            ?? AttendanceWorkSchedule::query()->where('is_active', true)->where('is_default', true)->first()
            ?? $this->configurationSchedule();
    }

    public function isWorkingDay(AttendanceWorkSchedule $schedule, Carbon $date): bool
    {
        return in_array($date->dayOfWeekIso, $schedule->working_days ?? [1, 2, 3, 4, 5, 6], true);
    }

    public function window(AttendanceWorkSchedule $schedule, Carbon $workDate): array
    {
        $start = $workDate->copy()->setTimeFromTimeString($schedule->shift_start);
        $end = $workDate->copy()->setTimeFromTimeString($schedule->shift_end);
        $earliest = $workDate->copy()->setTimeFromTimeString($schedule->earliest_clock_in);
        $latest = $workDate->copy()->setTimeFromTimeString($schedule->latest_clock_out);

        if ($schedule->is_overnight || $end->lte($start)) {
            $end->addDay();
            if ($latest->lte($start)) {
                $latest->addDay();
            }
        }

        return compact('start', 'end', 'earliest', 'latest');
    }

    private function configurationSchedule(): AttendanceWorkSchedule
    {
        return new AttendanceWorkSchedule([
            'name' => 'Configured Default',
            'shift_start' => config('hikvision.shift_start', '08:00'),
            'shift_end' => config('hikvision.shift_end', '17:00'),
            'grace_minutes' => (int) config('hikvision.late_threshold_minutes', 10),
            'break_minutes' => 0,
            'earliest_clock_in' => '05:00:00',
            'latest_clock_out' => '23:59:59',
            'half_day_minutes' => 240,
            'working_days' => [1, 2, 3, 4, 5, 6],
            'is_overnight' => false,
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
