<?php

namespace App\Modules\HR\Support;

use Carbon\Carbon;

final class AttendanceTimestampNormalizer
{
    public static function deviceToStorage(
        string $timestamp,
        string $deviceTimezone,
        string $storageTimezone
    ): string {
        return Carbon::parse($timestamp, $deviceTimezone)
            ->setTimezone($storageTimezone)
            ->format('Y-m-d H:i:s');
    }
}
