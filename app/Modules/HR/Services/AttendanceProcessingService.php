<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceDeviceRawEvent;
use App\Modules\HR\Models\AttendanceDeviceSyncLog;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AttendanceProcessingService
{
    private string $shiftEnd;
    private string $overtimeStart;
    private int $lateThresholdMinutes;

    public function __construct()
    {
        $this->shiftEnd            = config('hikvision.shift_end', '17:00');
        $this->overtimeStart       = config('hikvision.overtime_start', '18:00');
        $this->lateThresholdMinutes = (int) config('hikvision.late_threshold_minutes', 30);
    }

    /**
     * Process raw events for a given date range and create/update attendance records.
     * Returns the count of attendance records created or updated.
     */
    public function processRawEvents(Collection $events, ?int $syncLogId = null): int
    {
        $processed = 0;

        // Group events by person_id and by date
        $byPersonAndDate = $events->groupBy(function (AttendanceDeviceRawEvent $event) {
            return $this->normalizePersonId($event->person_id) . '|' . $event->event_datetime->toDateString();
        });

        // Build person_id → employee_id map using national ID (device sends id_number as Person ID).
        // Trim both sides to guard against whitespace stored in either the device export or the DB.
        $personIds = $events->pluck('person_id')
            ->map(fn ($id) => $this->normalizePersonId($id))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $employeeMap = $this->employeeMapForPersonIds($personIds);

        Log::info('AttendanceProcessingService: mapping', [
            'person_ids'   => $personIds,
            'mapped_count' => $employeeMap->count(),
            'mapped_ids'   => $employeeMap->keys()->toArray(),
        ]);

        foreach ($byPersonAndDate as $key => $personDateEvents) {
            [$personId, $date] = explode('|', $key, 2);

            $employeeId = $employeeMap->get($personId);
            if (!$employeeId) {
                continue; // Unmapped person — skip
            }

            try {
                $processed += $this->processPersonDay(
                    $employeeId,
                    $date,
                    $personDateEvents,
                    $syncLogId
                );
            } catch (\Throwable $e) {
                Log::warning("AttendanceProcessingService: failed processing employee {$employeeId} on {$date}: " . $e->getMessage());
            }
        }

        return $processed;
    }

    private function processPersonDay(int $employeeId, string $date, Collection $events, ?int $syncLogId): int
    {
        // Deduplicate: remove scans within 30 seconds of the previous scan
        $deduped = $this->deduplicateScans($events);

        if ($deduped->isEmpty()) {
            return 0;
        }

        $sorted = $deduped->sortBy('event_datetime')->values();

        $clockIn = $sorted->first()->event_datetime;
        $clockOut = $sorted->count() > 1 ? $sorted->last()->event_datetime : null;

        $workHours = null;
        $overtimeHours = 0.0;

        if ($clockOut) {
            $workHours = $this->calculateWorkHours($clockIn, $clockOut);
            $overtimeHours = $this->calculateOvertimeHours($clockOut);
        }

        $status = $this->determineStatus($clockIn, $clockOut);

        $payload = [
            'employee_id'      => $employeeId,
            'date'             => $date,
            'clock_in'         => $clockIn->format('H:i:s'),
            'clock_out'        => $clockOut?->format('H:i:s'),
            'device_clock_in'  => $clockIn->format('H:i:s'),
            'device_clock_out' => $clockOut?->format('H:i:s'),
            'status'           => $status,
            'work_hours'       => $workHours,
            'overtime_hours'   => $overtimeHours,
            'is_manual'        => false,
            'synced_at'        => now(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ];

        $existing = AttendanceRecord::withTrashed()
            ->where('employee_id', $employeeId)
            ->where('date', $date)
            ->first();

        if ($existing) {
            $update = [
                'device_clock_in'  => $payload['device_clock_in'],
                'device_clock_out' => $payload['device_clock_out'],
                'synced_at'        => $payload['synced_at'],
                'updated_at'       => $payload['updated_at'],
            ];

            if (!$existing->is_manual) {
                $update = array_merge($update, [
                    'clock_in'       => $payload['clock_in'],
                    'clock_out'      => $payload['clock_out'],
                    'status'         => $payload['status'],
                    'work_hours'     => $payload['work_hours'],
                    'overtime_hours' => $payload['overtime_hours'],
                    'is_manual'      => false,
                    'deleted_at'     => null,
                ]);
            }

            DB::table('attendance_records')->where('id', $existing->id)->update($update);
        } else {
            DB::table('attendance_records')->insert($payload);
        }

        return 1;
    }

    /**
     * Remove scans that fall within 30 seconds of the immediately preceding scan.
     */
    private function deduplicateScans(Collection $events): Collection
    {
        $sorted = $events->sortBy('event_datetime')->values();
        $result = collect();
        $lastKept = null;

        foreach ($sorted as $event) {
            if ($lastKept === null || $lastKept->event_datetime->diffInSeconds($event->event_datetime) > 30) {
                $result->push($event);
                $lastKept = $event;
            }
        }

        return $result;
    }

    private function calculateWorkHours(Carbon $clockIn, Carbon $clockOut): float
    {
        $diff = $clockIn->diffInMinutes($clockOut, false);

        // Night shift: clock_out is earlier (next day) — diff will be negative, take absolute
        if ($diff < 0) {
            $diff = 1440 + $diff; // 24 * 60 minutes
        }

        return round($diff / 60, 2);
    }

    private function calculateOvertimeHours(Carbon $clockOut): float
    {
        $overtimeStartToday = Carbon::createFromFormat('H:i', $this->overtimeStart, $clockOut->timezone)
            ->setDateFrom($clockOut);

        if ($clockOut->gt($overtimeStartToday)) {
            return round($overtimeStartToday->diffInMinutes($clockOut) / 60, 2);
        }

        return 0.0;
    }

    private function determineStatus(Carbon $clockIn, ?Carbon $clockOut): string
    {
        $shiftStart = config('hikvision.shift_start', '08:00');
        [$shiftHour, $shiftMin] = explode(':', $shiftStart);
        $lateThreshold = Carbon::createFromTime((int) $shiftHour, (int) $shiftMin, 0, $clockIn->timezone)
            ->setDateFrom($clockIn)
            ->addMinutes($this->lateThresholdMinutes);

        $isLate = $clockIn->gt($lateThreshold);

        if (!$clockOut) {
            return AttendanceRecord::STATUS_MISSING_CLOCK_OUT;
        }

        $shiftEndCarbon = Carbon::createFromFormat('H:i', $this->shiftEnd, $clockIn->timezone)
            ->setDateFrom($clockIn);

        $leftEarly = $clockOut->lt($shiftEndCarbon);

        if ($isLate && $leftEarly) {
            return AttendanceRecord::STATUS_HALF_DAY;
        }

        if ($isLate) {
            return AttendanceRecord::STATUS_LATE;
        }

        if ($leftEarly) {
            return AttendanceRecord::STATUS_EARLY_DEPARTURE;
        }

        return AttendanceRecord::STATUS_PRESENT;
    }

    private function employeeMapForPersonIds(array $personIds): Collection
    {
        $columns = ['id'];
        if (Schema::hasColumn('employees', 'hikvision_id')) {
            $columns[] = 'hikvision_id';
        }
        if (Schema::hasColumn('employees', 'id_number')) {
            $columns[] = 'id_number';
        }

        $map = collect();

        Employee::query()->get($columns)->each(function ($employee) use ($map, $personIds) {
            foreach (['hikvision_id', 'id_number'] as $field) {
                $personId = trim((string) ($employee->{$field} ?? ''));
                if ($personId !== '' && in_array($personId, $personIds, true) && !$map->has($personId)) {
                    $map->put($personId, $employee->id);
                }
            }
        });

        return $map;
    }

    private function normalizePersonId(?string $personId): string
    {
        return preg_replace('/[\s,]/', '', ltrim(trim((string) $personId), "'"));
    }
}
