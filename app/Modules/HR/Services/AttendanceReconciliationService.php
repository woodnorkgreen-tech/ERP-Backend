<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceHoliday;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AttendanceReconciliationService
{
    public function __construct(
        private readonly AttendanceScheduleService $scheduleService
    ) {}

    public function reconcile(Carbon $from, Carbon $to): int
    {
        $created = 0;

        DB::transaction(function () use ($from, $to, &$created) {
            Employee::query()
                ->where('status', 'active')
                ->orderBy('id')
                ->chunkById(200, function ($employees) use ($from, $to, &$created) {
                    foreach ($employees as $employee) {
                        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $date) {
                            if (!$this->isEmployedOn($employee, $date)) {
                                continue;
                            }

                            $schedule = $this->scheduleService->forEmployee($employee, $date);
                            $holiday = $this->holidayOn($date);
                            $isNonWorkingDay = !$this->scheduleService->isWorkingDay($schedule, $date);
                            $existing = DB::table('attendance_records')
                                ->where('employee_id', $employee->id)
                                ->where('date', $date->toDateString())
                                ->first();
                            if ($existing) {
                                if (
                                    Schema::hasColumn('attendance_records', 'is_holiday_work')
                                    && ($holiday || $isNonWorkingDay)
                                    && $existing->clock_in
                                ) {
                                    DB::table('attendance_records')
                                        ->where('id', $existing->id)
                                        ->update([
                                            'is_holiday_work' => true,
                                            'holiday_name' => $holiday?->name ?? 'Scheduled non-working day',
                                            'updated_at' => now(),
                                        ]);
                                }
                                continue;
                            }

                            $status = $this->expectedStatus($employee, $schedule, $date);
                            $payload = [
                                'employee_id' => $employee->id,
                                'date' => $date->toDateString(),
                                'status' => $status,
                                'is_manual' => false,
                                'work_hours' => null,
                                'overtime_hours' => 0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                            if (Schema::hasColumn('attendance_records', 'work_schedule_id')) {
                                $payload['work_schedule_id'] = $schedule->exists ? $schedule->id : null;
                            }
                            if (Schema::hasColumn('attendance_records', 'is_holiday_work')) {
                                $payload['is_holiday_work'] = false;
                                $payload['holiday_name'] = $holiday?->name;
                            }

                            $created += DB::table('attendance_records')->insertOrIgnore($payload);
                        }
                    }
                });
        });

        return $created;
    }

    private function expectedStatus(Employee $employee, $schedule, Carbon $date): string
    {
        if ($this->holidayOn($date) || !$this->scheduleService->isWorkingDay($schedule, $date)) {
            return AttendanceRecord::STATUS_HOLIDAY;
        }

        if ($this->isOnApprovedLeave($employee->id, $date)) {
            return AttendanceRecord::STATUS_ON_LEAVE;
        }

        return AttendanceRecord::STATUS_ABSENT;
    }

    private function holidayOn(Carbon $date): ?AttendanceHoliday
    {
        return Schema::hasTable('attendance_holidays')
            ? AttendanceHoliday::query()
                ->where('is_active', true)
                ->whereDate('date', $date)
                ->first()
            : null;
    }

    private function isOnApprovedLeave(int $employeeId, Carbon $date): bool
    {
        return Schema::hasTable('leave_requests')
            && LeaveRequest::query()
                ->where('employee_id', $employeeId)
                ->where('status', LeaveRequest::STATUS_APPROVED)
                ->whereDate('start_date', '<=', $date)
                ->whereDate('end_date', '>=', $date)
                ->exists();
    }

    private function isEmployedOn(Employee $employee, Carbon $date): bool
    {
        if ($employee->hire_date && $date->lt(Carbon::parse($employee->hire_date)->startOfDay())) {
            return false;
        }
        if ($employee->contract_end_date && $date->gt(Carbon::parse($employee->contract_end_date)->startOfDay())) {
            return false;
        }

        return true;
    }
}
