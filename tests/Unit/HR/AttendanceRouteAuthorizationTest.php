<?php

namespace Tests\Unit\HR;

use App\Constants\Permissions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AttendanceRouteAuthorizationTest extends TestCase
{
    public function test_every_attendance_route_requires_the_manage_attendance_permission(): void
    {
        $attendanceRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/hr/attendance'));

        $this->assertCount(19, $attendanceRoutes);

        $attendanceRoutes->each(function ($route) {
            $this->assertContains(
                'permission:' . Permissions::HR_MANAGE_ATTENDANCE,
                $route->gatherMiddleware(),
                "{$route->methods()[0]} {$route->uri()} is missing attendance authorization"
            );
        });
    }
}
