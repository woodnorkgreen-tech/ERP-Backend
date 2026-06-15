<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\AttendanceWorkSchedule;
use Carbon\Carbon;

class AttendanceStatusPolicy
{
    public function determine(
        Carbon $clockIn,
        ?Carbon $clockOut,
        AttendanceWorkSchedule $schedule,
        Carbon $workDate
    ): string {
        if (!$clockOut) {
            return AttendanceRecord::STATUS_MISSING_CLOCK_OUT;
        }

        $shiftStart = $workDate->copy()->setTimeFromTimeString($schedule->shift_start);
        $shiftEnd = $workDate->copy()->setTimeFromTimeString($schedule->shift_end);
        if ($schedule->is_overnight || $shiftEnd->lte($shiftStart)) {
            $shiftEnd->addDay();
        }

        $workedMinutes = max(0, $clockIn->diffInMinutes($clockOut, false) - (int) $schedule->break_minutes);
        if ($workedMinutes < (int) $schedule->half_day_minutes) {
            return AttendanceRecord::STATUS_HALF_DAY;
        }
        if ($clockIn->gt($shiftStart->copy()->addMinutes((int) $schedule->grace_minutes))) {
            return AttendanceRecord::STATUS_LATE;
        }
        if ($clockOut->lt($shiftEnd)) {
            return AttendanceRecord::STATUS_EARLY_DEPARTURE;
        }

        return AttendanceRecord::STATUS_PRESENT;
    }
}
