<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Data\AttendanceProcessingResult;
use App\Modules\HR\Models\AttendanceDeviceRawEvent;
use App\Modules\HR\Support\AttendancePersonId;
use Carbon\Carbon;

class AttendanceReprocessingService
{
    public function __construct(
        private readonly AttendanceProcessingService $processingService
    ) {}

    public function reprocess(Carbon $from, Carbon $to, ?string $personId = null): AttendanceProcessingResult
    {
        $query = AttendanceDeviceRawEvent::query()
            ->whereBetween('event_datetime', [
                $from->copy()->startOfDay(),
                $to->copy()->endOfDay(),
            ]);

        $normalizedId = AttendancePersonId::normalize($personId);
        if ($normalizedId !== '') {
            $query->where('person_id', $normalizedId);
        }

        return $this->processingService->processRawEventsDetailed($query->get());
    }
}
