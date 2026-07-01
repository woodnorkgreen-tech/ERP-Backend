<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Data\AttendanceProcessingResult;
use App\Modules\HR\Models\AttendanceDeviceRawEvent;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\AttendanceHoliday;
use App\Modules\HR\Models\AttendanceWorkSchedule;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Support\AttendancePersonId;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AttendanceProcessingService
{
    private AttendanceEmployeeResolver $employeeResolver;
    private AttendanceScheduleService $scheduleService;
    private AttendanceStatusPolicy $statusPolicy;
    private AttendanceOvertimeService $overtimeService;

    public function __construct(
        ?AttendanceEmployeeResolver $employeeResolver = null,
        ?AttendanceScheduleService $scheduleService = null,
        ?AttendanceStatusPolicy $statusPolicy = null,
        ?AttendanceOvertimeService $overtimeService = null
    ) {
        $this->employeeResolver = $employeeResolver ?? new AttendanceEmployeeResolver();
        $this->scheduleService = $scheduleService ?? new AttendanceScheduleService();
        $this->statusPolicy = $statusPolicy ?? new AttendanceStatusPolicy();
        $this->overtimeService = $overtimeService ?? new AttendanceOvertimeService();
    }

    public function processRawEvents(Collection $events, ?int $syncLogId = null): int
    {
        return $this->processRawEventsDetailed($events, $syncLogId)->recordsProcessed;
    }

    public function processRawEventsDetailed(
        Collection $events,
        ?int $syncLogId = null
    ): AttendanceProcessingResult {
        $personIds = $events->pluck('person_id')
            ->map(fn ($id) => AttendancePersonId::normalize($id))
            ->filter()
            ->unique()
            ->values();
        $employeeMap = $this->employeeResolver->map($personIds);
        $employees = Employee::query()
            ->whereIn('id', $employeeMap->values()->unique())
            ->get()
            ->keyBy('id');
        $workdays = collect();

        foreach ($events as $event) {
            $employee = $employees->get(
                $employeeMap->get(AttendancePersonId::normalize($event->person_id))
            );
            if (!$employee) {
                continue;
            }

            $assignment = $this->assignEventToWorkday($employee, $event);
            if (!$assignment) {
                continue;
            }

            [$workDate, $schedule] = $assignment;
            $key = $employee->id . '|' . $workDate->toDateString();
            $workday = $workdays->get($key, [
                'employee' => $employee,
                'date' => $workDate,
                'schedule' => $schedule,
                'events' => collect(),
            ]);
            $workday['events']->push($event);
            $workdays->put($key, $workday);
        }

        Log::info('attendance.processing.mapping_completed', [
            'sync_log_id' => $syncLogId,
            'source_event_count' => $events->count(),
            'person_count' => $personIds->count(),
            'mapped_person_count' => $employeeMap->count(),
            'unmapped_person_count' => max(0, $personIds->count() - $employeeMap->count()),
        ]);

        $processed = 0;
        $failed = 0;
        foreach ($workdays as $workday) {
            try {
                $processed += $this->processPersonDay(
                    $workday['employee']->id,
                    $workday['date'],
                    $workday['events'],
                    $workday['schedule']
                );
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('attendance.processing.person_day_failed', [
                    'sync_log_id' => $syncLogId,
                    'attendance_date' => $workday['date']->toDateString(),
                    'exception_class' => $e::class,
                ]);
            }
        }

        Log::info('attendance.processing.completed', [
            'sync_log_id' => $syncLogId,
            'person_day_count' => $workdays->count(),
            'records_processed' => $processed,
            'failed_person_day_count' => $failed,
        ]);

        return new AttendanceProcessingResult(
            $processed,
            max(0, $personIds->count() - $employeeMap->count()),
            $failed
        );
    }

    public function repairIncompleteHistoricalRecords(?int $syncLogId = null): int
    {
        return $this->repairIncompleteHistoricalRecordsDetailed($syncLogId)->recordsProcessed;
    }

    public function repairIncompleteHistoricalRecordsDetailed(
        ?int $syncLogId = null
    ): AttendanceProcessingResult {
        $processed = 0;
        $failed = 0;

        AttendanceRecord::query()
            ->with('employee')
            ->where('is_manual', false)
            ->whereDate('date', '<', today())
            ->where(fn ($query) => $query->whereNull('clock_in')->orWhereNull('clock_out'))
            ->orderBy('date')
            ->chunkById(200, function ($records) use (&$processed, &$failed, $syncLogId) {
                foreach ($records as $record) {
                    try {
                        if (!$record->employee) {
                            continue;
                        }
                        $personIds = collect([
                            $record->employee->hikvision_id,
                            $record->employee->id_number,
                        ])->map(fn ($id) => AttendancePersonId::normalize($id))->filter()->unique();
                        if ($personIds->isEmpty()) {
                            continue;
                        }

                        $workDate = Carbon::parse($record->date);
                        $schedule = $this->scheduleService->forEmployee($record->employee, $workDate);
                        $window = $this->scheduleService->window($schedule, $workDate);
                        $events = AttendanceDeviceRawEvent::query()
                            ->whereIn('person_id', $personIds)
                            ->whereBetween('event_datetime', [$window['earliest'], $window['latest']])
                            ->get();
                        if ($this->deduplicateScans($events)->count() < 2) {
                            continue;
                        }

                        $processed += $this->processPersonDay(
                            $record->employee_id,
                            $workDate,
                            $events,
                            $schedule
                        );
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning('attendance.processing.reconciliation_failed', [
                            'sync_log_id' => $syncLogId,
                            'attendance_record_id' => $record->id,
                            'exception_class' => $e::class,
                        ]);
                    }
                }
            });

        return new AttendanceProcessingResult($processed, 0, $failed);
    }

    private function assignEventToWorkday(
        Employee $employee,
        AttendanceDeviceRawEvent $event
    ): ?array {
        foreach ([
            $event->event_datetime->copy()->subDay()->startOfDay(),
            $event->event_datetime->copy()->startOfDay(),
        ] as $workDate) {
            $schedule = $this->scheduleService->forEmployee($employee, $workDate);
            $window = $this->scheduleService->window($schedule, $workDate);
            if ($event->event_datetime->betweenIncluded($window['earliest'], $window['latest'])) {
                return [$workDate, $schedule];
            }
        }

        return null;
    }

    private function processPersonDay(
        int $employeeId,
        Carbon $workDate,
        Collection $events,
        AttendanceWorkSchedule $schedule
    ): int {
        $sorted = $this->deduplicateScans($events)->sortBy('event_datetime')->values();
        if ($sorted->isEmpty()) {
            return 0;
        }

        $clockIn = $sorted->first()->event_datetime;
        $clockOut = $sorted->count() > 1 ? $sorted->last()->event_datetime : null;
        $workHours = $clockOut
            ? round(max(0, $clockIn->diffInMinutes($clockOut, false) - (int) $schedule->break_minutes) / 60, 2)
            : null;
        $status = $this->statusPolicy->determine($clockIn, $clockOut, $schedule, $workDate);
        $holiday = Schema::hasTable('attendance_holidays')
            ? AttendanceHoliday::query()
                ->where('is_active', true)
                ->whereDate('date', $workDate)
                ->first()
            : null;
        $isNonWorkingDay = !$this->scheduleService->isWorkingDay($schedule, $workDate);

        $payload = [
            'employee_id' => $employeeId,
            'date' => $workDate->toDateString(),
            'clock_in' => $clockIn->format('H:i:s'),
            'clock_out' => $clockOut?->format('H:i:s'),
            'device_clock_in' => $clockIn->format('H:i:s'),
            'device_clock_out' => $clockOut?->format('H:i:s'),
            'status' => $status,
            'work_hours' => $workHours,
            'overtime_hours' => $clockOut ? $this->calculateOvertimeHours($clockOut) : 0,
            'is_manual' => false,
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('attendance_records', 'work_schedule_id')) {
            $payload['work_schedule_id'] = $schedule->exists ? $schedule->id : null;
        }
        if (Schema::hasColumn('attendance_records', 'is_holiday_work')) {
            $payload['is_holiday_work'] = (bool) ($holiday || $isNonWorkingDay);
            $payload['holiday_name'] = $holiday?->name
                ?? ($isNonWorkingDay ? 'Scheduled non-working day' : null);
        }

        $existing = AttendanceRecord::withTrashed()
            ->where('employee_id', $employeeId)
            ->where('date', $workDate->toDateString())
            ->first();
        if ($existing) {
            if (!$existing->is_manual && $existing->clock_in && $existing->clock_out) {
                $this->overtimeService->syncProposal($existing, $schedule);
                return 0;
            }
            $update = [
                'device_clock_in' => $payload['device_clock_in'],
                'device_clock_out' => $payload['device_clock_out'],
                'synced_at' => $payload['synced_at'],
                'updated_at' => $payload['updated_at'],
            ];
            if (array_key_exists('work_schedule_id', $payload)) {
                $update['work_schedule_id'] = $payload['work_schedule_id'];
            }
            if (!$existing->is_manual) {
                $update = array_merge($update, [
                    'clock_in' => $payload['clock_in'],
                    'clock_out' => $payload['clock_out'],
                    'status' => $payload['status'],
                    'work_hours' => $payload['work_hours'],
                    'overtime_hours' => $payload['overtime_hours'],
                    'is_manual' => false,
                    'deleted_at' => null,
                ]);
                if (array_key_exists('is_holiday_work', $payload)) {
                    $update['is_holiday_work'] = $payload['is_holiday_work'];
                    $update['holiday_name'] = $payload['holiday_name'];
                }
            }
            DB::table('attendance_records')->where('id', $existing->id)->update($update);
        } else {
            DB::table('attendance_records')->insert($payload);
        }

        $record = AttendanceRecord::withTrashed()
            ->where('employee_id', $employeeId)
            ->whereDate('date', $workDate)
            ->first();
        if ($record) {
            $this->overtimeService->syncProposal($record, $schedule);
        }

        return 1;
    }

    private function deduplicateScans(Collection $events): Collection
    {
        $sorted = $events->sortBy('event_datetime')->values();
        
        if ($sorted->isEmpty()) {
            return collect();
        }

        // Keep only the first entry (clock-in) and last exit (clock-out)
        // This handles door access devices where people scan multiple times per day
        $result = collect();
        
        // Always keep the first event (earliest - clock-in)
        $result->push($sorted->first());
        
        // If there are multiple events, keep the last one (latest - clock-out)
        if ($sorted->count() > 1) {
            $lastEvent = $sorted->last();
            // Only add last event if it's different from the first (at least 1 minute apart)
            if ($sorted->first()->event_datetime->diffInMinutes($lastEvent->event_datetime) >= 1) {
                $result->push($lastEvent);
            }
        }
        
        return $result;
    }

    private function calculateOvertimeHours(Carbon $clockOut): float
    {
        $start = Carbon::createFromFormat(
            'H:i',
            config('hikvision.overtime_start', '18:00'),
            $clockOut->timezone
        )->setDateFrom($clockOut);

        return $clockOut->gt($start)
            ? round($start->diffInMinutes($clockOut) / 60, 2)
            : 0.0;
    }
}
