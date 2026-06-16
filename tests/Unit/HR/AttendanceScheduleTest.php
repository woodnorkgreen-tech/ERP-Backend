<?php

namespace Tests\Unit\HR;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class AttendanceScheduleTest extends TestCase
{
    public function test_attendance_sync_has_one_authoritative_three_run_schedule(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'attendance:sync-hikvision'))
            ->values();

        $this->assertCount(3, $events);
        $this->assertSame(
            ['0 18 * * *', '0 23 * * *', '0 9 * * *'],
            $events->pluck('expression')->sort()->values()->all()
        );

        $events->each(function ($event) {
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
        });
    }

    public function test_attendance_reconciliation_has_one_protected_daily_schedule(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'attendance:reconcile'));

        $this->assertCount(1, $events);
        $this->assertSame('30 23 * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertTrue($events->first()->onOneServer);
    }

    public function test_kenyan_holidays_refresh_monthly(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'attendance:sync-kenya-holidays'));

        $this->assertCount(1, $events);
        $this->assertSame('30 2 1 * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertTrue($events->first()->onOneServer);
    }
}
