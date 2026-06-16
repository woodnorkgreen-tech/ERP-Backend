<?php

namespace App\Modules\HR\Support;

final class AttendancePersonId
{
    public static function normalize(mixed $value): string
    {
        return preg_replace('/[\s,]/', '', ltrim(trim((string) $value), "'")) ?? '';
    }
}
