<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\AttendanceHoliday;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Services\LeaveManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_working_day_leave_excludes_sundays_and_active_kenyan_holidays(): void
    {
        AttendanceHoliday::create([
            'date' => '2026-05-01',
            'name' => 'Labour Day',
            'source' => 'test',
            'is_active' => true,
        ]);

        $leaveType = LeaveType::create([
            'name' => 'Annual Leave',
            'code' => 'ANNUAL',
            'days_per_year' => 21,
            'color' => 'emerald',
            'is_active' => true,
            'requires_attachment' => false,
        ]);

        $days = app(LeaveManagementService::class)->calculateBusinessDays(
            '2026-04-30',
            '2026-05-03',
            'full_day',
            $leaveType,
        );

        $this->assertSame(2.0, $days);
    }

    public function test_maternity_and_paternity_leave_use_full_calendar_days(): void
    {
        AttendanceHoliday::create([
            'date' => '2026-05-01',
            'name' => 'Labour Day',
            'source' => 'test',
            'is_active' => true,
        ]);

        $leaveType = LeaveType::create([
            'name' => 'Paternity Leave',
            'code' => 'PATERNITY',
            'days_per_year' => 14,
            'color' => 'green',
            'is_active' => true,
            'requires_attachment' => true,
        ]);

        $days = app(LeaveManagementService::class)->calculateBusinessDays(
            '2026-04-30',
            '2026-05-03',
            'full_day',
            $leaveType,
        );

        $this->assertSame(4.0, $days);
    }
}
