<?php

namespace Tests\Unit\HR;

use Carbon\Carbon;
use App\Modules\HR\Support\AttendanceTimestampNormalizer;
use Tests\TestCase;

class AttendanceTimezoneConfigurationTest extends TestCase
{
    public function test_backend_uses_the_nairobi_attendance_timezone_convention(): void
    {
        $this->assertSame('Africa/Nairobi', config('app.timezone'));
        $this->assertSame('Africa/Nairobi', config('hikvision.device_timezone'));
        $this->assertSame('Africa/Nairobi', config('hikvision.storage_timezone'));
        $this->assertSame('+03:00', config('database.connections.mysql.timezone'));
    }

    public function test_nairobi_business_time_serializes_as_the_same_utc_instant(): void
    {
        $businessTime = Carbon::parse(
            '2026-06-12 08:00:00',
            config('hikvision.storage_timezone')
        );

        $this->assertSame('2026-06-12T08:00:00+03:00', $businessTime->toIso8601String());
        $this->assertSame('2026-06-12T05:00:00.000000Z', $businessTime->toJSON());
    }

    public function test_nairobi_offset_is_utc_plus_three_throughout_the_year(): void
    {
        $january = Carbon::parse('2026-01-15 12:00:00', 'Africa/Nairobi');
        $july = Carbon::parse('2026-07-15 12:00:00', 'Africa/Nairobi');

        $this->assertSame(10800, $january->utcOffset() * 60);
        $this->assertSame(10800, $july->utcOffset() * 60);
    }

    public function test_utc_events_on_each_side_of_nairobi_midnight_keep_the_correct_workday(): void
    {
        $beforeMidnight = AttendanceTimestampNormalizer::deviceToStorage(
            '2026-06-10T20:59:59Z',
            'Africa/Nairobi',
            'Africa/Nairobi'
        );
        $afterMidnight = AttendanceTimestampNormalizer::deviceToStorage(
            '2026-06-10T21:00:00Z',
            'Africa/Nairobi',
            'Africa/Nairobi'
        );

        $this->assertSame('2026-06-10 23:59:59', $beforeMidnight);
        $this->assertSame('2026-06-11 00:00:00', $afterMidnight);
    }
}
