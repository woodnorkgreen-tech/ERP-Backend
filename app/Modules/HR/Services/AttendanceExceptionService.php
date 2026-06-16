<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceDeviceRawEvent;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Support\AttendancePersonId;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceExceptionService
{
    public function __construct(
        private readonly AttendanceEmployeeResolver $employeeResolver,
        private readonly AttendanceReprocessingService $reprocessingService
    ) {}

    public function unmapped(): array
    {
        $rows = AttendanceDeviceRawEvent::query()
            ->select([
                'person_id',
                DB::raw('MAX(person_name) as person_name'),
                DB::raw('COUNT(*) as event_count'),
                DB::raw('MIN(event_datetime) as first_event_at'),
                DB::raw('MAX(event_datetime) as last_event_at'),
            ])
            ->groupBy('person_id')
            ->orderByDesc('last_event_at')
            ->get();

        $mapped = $this->employeeResolver->map($rows->pluck('person_id'));

        return $rows
            ->reject(fn ($row) => $mapped->has(AttendancePersonId::normalize($row->person_id)))
            ->values()
            ->toArray();
    }

    public function mapToEmployee(string $personId, Employee $employee): array
    {
        $personId = AttendancePersonId::normalize($personId);
        $range = AttendanceDeviceRawEvent::query()
            ->where('person_id', $personId)
            ->selectRaw('MIN(event_datetime) as first_event_at, MAX(event_datetime) as last_event_at')
            ->first();

        if (!$range?->first_event_at) {
            throw new \InvalidArgumentException('No raw attendance events exist for this person ID.');
        }

        return DB::transaction(function () use ($personId, $employee, $range) {
            $employee->update(['hikvision_id' => $personId]);
            $result = $this->reprocessingService->reprocess(
                Carbon::parse($range->first_event_at),
                Carbon::parse($range->last_event_at),
                $personId
            );

            return [
                'employee_id' => $employee->id,
                'person_id' => $personId,
                'records_processed' => $result->recordsProcessed,
                'failed_count' => $result->failedPersonDayCount,
            ];
        });
    }
}
