<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceDeviceSyncLog;
use App\Modules\HR\Models\AttendanceRecord;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function __construct(
        private readonly HikvisionSyncService $hikvisionSyncService
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
        return AttendanceRecord::with(['employee'])->findOrFail($id);
    }

    public function createRecord(array $data): AttendanceRecord
    {
        return DB::transaction(function () use ($data) {
            $record = AttendanceRecord::create(array_merge($data, [
                'is_manual' => true,
                'work_hours' => $this->calculateWorkHoursFromTimes(
                    $data['clock_in'] ?? null,
                    $data['clock_out'] ?? null
                ),
                'overtime_hours' => $this->calculateOvertimeFromClockOut($data['clock_out'] ?? null),
            ]));

            return $record;
        });
    }

    public function updateRecord(int $id, array $data): AttendanceRecord
    {
        return DB::transaction(function () use ($id, $data) {
            $record = AttendanceRecord::findOrFail($id);

            $clockIn  = $data['clock_in']  ?? $record->clock_in;
            $clockOut = $data['clock_out'] ?? $record->clock_out;

            $record->update(array_merge($data, [
                'work_hours'    => $this->calculateWorkHoursFromTimes($clockIn, $clockOut),
                'overtime_hours' => $this->calculateOvertimeFromClockOut($clockOut),
            ]));

            return $record->fresh(['employee']);
        });
    }

    public function deleteRecord(int $id): void
    {
        AttendanceRecord::findOrFail($id)->delete();
    }

    public function getSummary(?string $date = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $date = $date ?: null;

        $base = AttendanceRecord::query();
        if ($date) {
            $base->where('date', $date);
        } elseif ($dateFrom && $dateTo) {
            $base->whereBetween('date', [$dateFrom, $dateTo]);
        } elseif ($dateFrom) {
            $base->where('date', '>=', $dateFrom);
        } elseif ($dateTo) {
            $base->where('date', '<=', $dateTo);
        } else {
            $date = today()->toDateString();
            $base->where('date', $date);
        }

        $counts = (clone $base)->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $totalEmployees = (clone $base)->count();
        $clockedIn = (clone $base)->whereNotNull('clock_in')->count();
        $late = $this->countLateArrivals(clone $base);
        $present = $clockedIn;
        $attendanceRate = $totalEmployees > 0 ? round(($present / $totalEmployees) * 100, 1) : 0;

        // Total overtime hours for the day
        $totalOvertimeHours = (clone $base)->sum('overtime_hours');

        return [
            'date'             => $date,
            'date_from'        => $dateFrom,
            'date_to'          => $dateTo,
            'total_employees'  => $totalEmployees,
            'present'          => $present,
            'absent'           => $counts['absent'] ?? 0,
            'late'             => $late,
            'early_departure'  => $counts['early_departure'] ?? 0,
            'half_day'         => $counts['half_day'] ?? 0,
            'missing_clock_out' => $counts['missing_clock_out'] ?? 0,
            'on_leave'         => $counts['on_leave'] ?? 0,
            'holiday'          => $counts['holiday'] ?? 0,
            'attendance_rate'  => $attendanceRate,
            'total_overtime_hours' => round((float) $totalOvertimeHours, 2),
        ];
    }

    private function countLateArrivals($query): int
    {
        $shiftStart = config('hikvision.shift_start', '08:00');
        $lateThresholdMinutes = (int) config('hikvision.late_threshold_minutes', 10);

        $threshold = Carbon::createFromFormat('H:i:s', strlen($shiftStart) === 5 ? $shiftStart . ':00' : $shiftStart)
            ->addMinutes($lateThresholdMinutes)
            ->format('H:i:s');

        return $query->whereNotNull('clock_in')
            ->where('clock_in', '>', $threshold)
            ->count();
    }

    public function getOvertimeReport(Request $request): LengthAwarePaginator
    {
        $query = AttendanceRecord::with(['employee'])
            ->withOvertime();

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

        return $query->orderBy('overtime_hours', 'desc')
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

    private function calculateWorkHoursFromTimes(?string $clockIn, ?string $clockOut): ?float
    {
        if (!$clockIn || !$clockOut) {
            return null;
        }

        $in  = Carbon::createFromFormat('H:i:s', strlen($clockIn) === 5 ? $clockIn . ':00' : $clockIn);
        $out = Carbon::createFromFormat('H:i:s', strlen($clockOut) === 5 ? $clockOut . ':00' : $clockOut);

        $diff = $in->diffInMinutes($out, false);
        if ($diff < 0) {
            $diff = 1440 + $diff;
        }

        return round($diff / 60, 2);
    }

    private function calculateOvertimeFromClockOut(?string $clockOut): float
    {
        if (!$clockOut) {
            return 0.0;
        }

        $overtimeStart = config('hikvision.overtime_start', '18:00');
        $out = Carbon::createFromFormat('H:i:s', strlen($clockOut) === 5 ? $clockOut . ':00' : $clockOut);
        $start = Carbon::createFromFormat('H:i', $overtimeStart);

        if ($out->gt($start)) {
            return round($start->diffInMinutes($out) / 60, 2);
        }

        return 0.0;
    }
}
