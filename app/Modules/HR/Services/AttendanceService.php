<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Exceptions\AttendanceRecordConflictException;
use App\Modules\HR\Models\AttendanceDeviceSyncLog;
use App\Modules\HR\Models\AttendanceHoliday;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\HRAuditLog;
use App\Modules\HR\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AttendanceService
{
    public function __construct(
        private readonly HikvisionSyncService $hikvisionSyncService,
        private readonly AttendanceScheduleService $scheduleService = new AttendanceScheduleService(),
        private readonly AttendanceOvertimeService $overtimeService = new AttendanceOvertimeService(),
        private readonly AttendanceStatusPolicy $statusPolicy = new AttendanceStatusPolicy()
    ) {}

    public function getRecords(Request $request): LengthAwarePaginator
    {
        $query = AttendanceRecord::with(['employee']);

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('date')) {
            $query->byDate($request->date);
        } elseif ($request->filled('date_from') && $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        } elseif ($request->filled('date_from')) {
            $query->where('date', '>=', $request->date_from);
        } elseif ($request->filled('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        if ($request->filled('employee_id')) {
            $query->byEmployee((int) $request->employee_id);
        }

        if ($request->filled('department_id')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('department_id', $request->department_id);
            });
        }

        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        $sortBy  = in_array($request->sort_by, ['clock_in', 'clock_out', 'work_hours', 'overtime_hours', 'date'], true)
            ? $request->sort_by
            : null;
        $sortDir = $request->sort_dir === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'clock_in') {
            // Push NULLs to the end regardless of direction
            $query->orderByRaw('clock_in IS NULL')
                  ->orderBy('clock_in', $sortDir);
        } elseif ($sortBy) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('date', 'desc')->orderBy('id', 'desc');
        }

        return $query->paginate($request->per_page ?? 15);
    }

    public function getRecord(int $id): AttendanceRecord
    {
        return AttendanceRecord::with(['employee', 'correctedBy', 'auditLogs.user'])->findOrFail($id);
    }

    public function createRecord(array $data, ?int $actorId = null, ?string $ipAddress = null): AttendanceRecord
    {
        return DB::transaction(function () use ($data, $actorId, $ipAddress) {
            $this->ensureEmployeeDayIsAvailable((int) $data['employee_id'], $data['date']);

            $reason = $data['correction_reason'];
            $calculation = $this->calculateManualRecord($data);
            $record = AttendanceRecord::create(array_merge($data, $calculation, [
                'is_manual' => true,
                'corrected_by' => $actorId,
                'corrected_at' => now(),
            ]));

            $this->writeAudit($record, 'attendance_created_manually', $reason, null, $record->attributesToArray(), $actorId, $ipAddress);
            $this->overtimeService->syncProposal(
                $record,
                $this->scheduleService->forEmployee($record->employee, $record->date)
            );

            return $record->load(['employee', 'correctedBy']);
        });
    }

    public function updateRecord(int $id, array $data, ?int $actorId = null, ?string $ipAddress = null): AttendanceRecord
    {
        return DB::transaction(function () use ($id, $data, $actorId, $ipAddress) {
            $record = AttendanceRecord::findOrFail($id);
            $before = $record->attributesToArray();
            $reason = $data['correction_reason'];
            $date = $data['date'] ?? $record->date->toDateString();

            $this->ensureEmployeeDayIsAvailable($record->employee_id, $date, $record->id);

            $clockIn  = $data['clock_in']  ?? $record->clock_in;
            $clockOut = $data['clock_out'] ?? $record->clock_out;
            $calculation = $this->calculateManualRecord([
                ...$data,
                'employee_id' => $record->employee_id,
                'date' => $date,
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
            ]);

            $record->update(array_merge($data, $calculation, [
                'date' => $date,
                'is_manual' => true,
                'corrected_by' => $actorId,
                'corrected_at' => now(),
            ]));

            $this->writeAudit(
                $record,
                'attendance_corrected',
                $reason,
                $before,
                $record->fresh()->attributesToArray(),
                $actorId,
                $ipAddress
            );
            $record->load('employee');
            $this->overtimeService->syncProposal(
                $record,
                $this->scheduleService->forEmployee($record->employee, $record->date)
            );

            return $record->fresh(['employee']);
        });
    }

    public function deleteRecord(int $id, string $reason, ?int $actorId = null, ?string $ipAddress = null): void
    {
        DB::transaction(function () use ($id, $reason, $actorId, $ipAddress) {
            $record = AttendanceRecord::findOrFail($id);
            $before = $record->attributesToArray();
            $record->delete();

            $this->writeAudit($record, 'attendance_deleted', $reason, $before, null, $actorId, $ipAddress);
        });
    }

    public function restoreRecord(int $id, string $reason, ?int $actorId = null, ?string $ipAddress = null): AttendanceRecord
    {
        return DB::transaction(function () use ($id, $reason, $actorId, $ipAddress) {
            $record = AttendanceRecord::withTrashed()->findOrFail($id);

            if (!$record->trashed()) {
                return $record->load(['employee', 'correctedBy']);
            }

            $this->ensureEmployeeDayIsAvailable($record->employee_id, $record->date->toDateString(), $record->id);
            $record->restore();
            $record->update([
                'is_manual' => true,
                'correction_reason' => $reason,
                'corrected_by' => $actorId,
                'corrected_at' => now(),
            ]);

            $this->writeAudit(
                $record,
                'attendance_restored',
                $reason,
                null,
                $record->fresh()->attributesToArray(),
                $actorId,
                $ipAddress
            );

            return $record->fresh(['employee', 'correctedBy']);
        });
    }

    public function getSummary(?string $date = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        [$from, $to] = $this->summaryRange($date, $dateFrom, $dateTo);
        $records = AttendanceRecord::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get();
        $counts = $records->countBy('status')->all();
        $expectedKeys = [];
        $eligibleEmployees = [];

        $employees = Employee::query()->where('status', 'active')->get();
        foreach ($employees as $employee) {
            foreach (CarbonPeriod::create($from, $to) as $workDate) {
                if (!$this->isEmployedOn($employee, $workDate)) {
                    continue;
                }

                $eligibleEmployees[$employee->id] = true;
                $schedule = $this->scheduleService->forEmployee($employee, $workDate);
                if (
                    !$this->scheduleService->isWorkingDay($schedule, $workDate)
                    || $this->isHoliday($workDate)
                    || $this->isOnApprovedLeave($employee->id, $workDate)
                ) {
                    continue;
                }

                $expectedKeys[$employee->id . '|' . $workDate->toDateString()] = true;
            }
        }

        $attendedExpectedDays = $records
            ->filter(fn (AttendanceRecord $record) => $record->clock_in
                && isset($expectedKeys[
                    $record->employee_id . '|' . $record->date
                        ->copy()
                        ->timezone(config('app.timezone'))
                        ->toDateString()
                ]))
            ->count();
        $expectedWorkdays = count($expectedKeys);
        $attendanceRate = $expectedWorkdays > 0
            ? round(($attendedExpectedDays / $expectedWorkdays) * 100, 1)
            : 0;
        $present = $records->whereNotNull('clock_in')->count();

        return [
            'date'             => $date,
            'date_from'        => $dateFrom,
            'date_to'          => $dateTo,
            'total_employees'  => count($eligibleEmployees),
            'expected_workdays' => $expectedWorkdays,
            'attended_expected_workdays' => $attendedExpectedDays,
            'present'          => $present,
            'absent'           => $counts['absent'] ?? 0,
            'late'             => $counts[AttendanceRecord::STATUS_LATE] ?? 0,
            'early_departure'  => $counts['early_departure'] ?? 0,
            'half_day'         => $counts['half_day'] ?? 0,
            'missing_clock_out' => $counts['missing_clock_out'] ?? 0,
            'on_leave'         => $counts['on_leave'] ?? 0,
            'holiday'          => $counts['holiday'] ?? 0,
            'attendance_rate'  => $attendanceRate,
            'total_overtime_hours' => round((float) $records->sum('proposed_overtime_hours'), 2),
            'approved_overtime_hours' => round((float) $records->sum('approved_overtime_hours'), 2),
        ];
    }

    public function getOvertimeReport(Request $request): LengthAwarePaginator
    {
        $query = AttendanceRecord::with(['employee', 'overtimeEntry'])
            ->where('proposed_overtime_hours', '>', 0);

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        } elseif ($request->filled('date_from')) {
            $query->where('date', '>=', $request->date_from);
        } elseif ($request->filled('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        if ($request->filled('employee_id')) {
            $query->byEmployee((int) $request->employee_id);
        }

        if ($request->filled('department_id')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('department_id', $request->department_id);
            });
        }

        if ($request->filled('overtime_status')) {
            $query->where('overtime_status', $request->overtime_status);
        }

        return $query->orderBy('proposed_overtime_hours', 'desc')
                     ->paginate($request->per_page ?? 10);
    }

    public function triggerSync(): AttendanceDeviceSyncLog
    {
        return $this->hikvisionSyncService->sync();
    }

    public function getSyncLogs(int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return AttendanceDeviceSyncLog::latest('synced_at')->limit($limit)->get();
    }

    public function calculateManualRecord(array $data): array
    {
        $employee = Employee::findOrFail((int) $data['employee_id']);
        $workDate = Carbon::parse($data['date'])->startOfDay();
        $schedule = $this->scheduleService->forEmployee($employee, $workDate);
        $clockIn = $this->attendanceDateTime($workDate, $data['clock_in'] ?? null);
        $clockOut = $this->attendanceDateTime($workDate, $data['clock_out'] ?? null);

        if ($clockIn && $clockOut && ($schedule->is_overnight || $clockOut->lte($clockIn))) {
            $clockOut->addDay();
        }

        $holiday = $this->holidayForDate($workDate);
        $isNonWorkingDay = !$this->scheduleService->isWorkingDay($schedule, $workDate);
        $isHolidayWork = (bool) ($clockIn && ($holiday || $isNonWorkingDay));
        $workHours = $clockIn && $clockOut
            ? round(max(0, $clockIn->diffInMinutes($clockOut, false) - (int) $schedule->break_minutes) / 60, 2)
            : null;

        if ($clockIn) {
            $status = $this->statusPolicy->determine($clockIn, $clockOut, $schedule, $workDate);
        } elseif ($holiday || $isNonWorkingDay) {
            $status = AttendanceRecord::STATUS_HOLIDAY;
        } elseif ($this->isOnApprovedLeave($employee->id, $workDate)) {
            $status = AttendanceRecord::STATUS_ON_LEAVE;
        } else {
            $status = AttendanceRecord::STATUS_ABSENT;
        }

        $overtimeHours = $this->calculateManualOvertime($clockIn, $clockOut, $workHours, $isHolidayWork);

        $calculation = [
            'work_schedule_id' => $schedule->exists ? $schedule->id : null,
            'status' => $status,
            'work_hours' => $workHours,
            'overtime_hours' => $overtimeHours,
            'proposed_overtime_hours' => $overtimeHours,
            'is_holiday_work' => $isHolidayWork,
            'holiday_name' => $holiday?->name
                ?? ($isNonWorkingDay ? 'Scheduled non-working day' : null),
        ];

        return array_filter(
            $calculation,
            fn (string $column) => Schema::hasColumn('attendance_records', $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function attendanceDateTime(Carbon $workDate, ?string $time): ?Carbon
    {
        if (!$time) {
            return null;
        }

        return $workDate->copy()->setTimeFromTimeString($time);
    }

    private function calculateManualOvertime(
        ?Carbon $clockIn,
        ?Carbon $clockOut,
        ?float $workHours,
        bool $isHolidayWork
    ): float {
        if (!$clockIn || !$clockOut) {
            return 0.0;
        }

        if ($isHolidayWork) {
            return round((float) $workHours, 2);
        }

        $threshold = $clockOut->copy()->setTimeFromTimeString(
            config('hikvision.overtime_start', '18:00')
        );

        return $clockOut->gt($threshold)
            ? round($threshold->diffInMinutes($clockOut) / 60, 2)
            : 0.0;
    }

    private function ensureEmployeeDayIsAvailable(int $employeeId, string $date, ?int $exceptId = null): void
    {
        $query = AttendanceRecord::withTrashed()
            ->where('employee_id', $employeeId)
            ->whereDate('date', $date);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw new AttendanceRecordConflictException(
                'An attendance record already exists for this employee and date.'
            );
        }
    }

    private function writeAudit(
        AttendanceRecord $record,
        string $action,
        string $reason,
        ?array $before,
        ?array $after,
        ?int $actorId,
        ?string $ipAddress
    ): void {
        HRAuditLog::create([
            'user_id' => $actorId,
            'employee_id' => $record->employee_id,
            'action' => $action,
            'model_type' => AttendanceRecord::class,
            'model_id' => $record->id,
            'message' => $reason,
            'context' => [
                'reason' => $reason,
                'before' => $before,
                'after' => $after,
            ],
            'ip_address' => $ipAddress,
        ]);
    }

    private function summaryRange(?string $date, ?string $dateFrom, ?string $dateTo): array
    {
        $today = today();
        $from = Carbon::parse($date ?? $dateFrom ?? $dateTo ?? $today)->startOfDay();
        $to = Carbon::parse($date ?? $dateTo ?? $dateFrom ?? $today)->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($to->gt($today)) {
            $to = $today;
        }

        return [$from, $to];
    }

    private function isEmployedOn(Employee $employee, Carbon $date): bool
    {
        return (!$employee->hire_date || $date->gte($employee->hire_date->copy()->startOfDay()))
            && (!$employee->contract_end_date || $date->lte($employee->contract_end_date->copy()->startOfDay()));
    }

    private function isHoliday(Carbon $date): bool
    {
        return (bool) $this->holidayForDate($date);
    }

    private function holidayForDate(Carbon $date): ?AttendanceHoliday
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
}
