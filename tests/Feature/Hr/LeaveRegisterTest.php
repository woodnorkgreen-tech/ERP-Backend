<?php

namespace Tests\Feature\Hr;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LeaveRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;
    private LeaveType $annualLeave;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-31 10:00:00');

        $this->department = Department::create(['name' => 'Production']);
        $this->annualLeave = LeaveType::query()->where('code', 'ANNUAL')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_hr_sees_per_employee_totals_and_instance_breakdown(): void
    {
        $employee = $this->employee('Shadrack', 'Mutuku');
        $this->actingAs($this->hrUser(), 'sanctum');

        $this->postJson('/api/hr/leave/requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $this->annualLeave->id,
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-08',
            'session' => 'full_day',
            'reason' => 'Family trip.',
            'record_as_approved' => true,
        ])->assertCreated();

        $response = $this->getJson('/api/hr/leave/register?year=2026')->assertOk();

        $entry = collect($response->json('data'))->firstWhere('employee.id', $employee->id);

        $this->assertNotNull($entry);
        // Employee was hired 2025-01-01, so the full 21-day annual entitlement applies for
        // 2026 (not gated by month-by-month accrual progress, no carry-forward).
        $this->assertEquals(21.0, $entry['total_allocated_days']);
        $this->assertEquals(3.0, $entry['total_used_days']);
        $this->assertEquals(18.0, $entry['total_remaining_days']);
        $this->assertCount(1, $entry['instances']);
        $this->assertSame('Family trip.', $entry['instances'][0]['reason']);
        $this->assertSame('2026-07-06', $entry['instances'][0]['start_date']);
        $this->assertSame('ANNUAL', $entry['instances'][0]['leave_type_code']);
        $this->assertSame(2026, $response->json('meta.year'));
    }

    public function test_register_paginates_employees(): void
    {
        $this->employee('Alice', 'Aaronson');
        $this->employee('Bob', 'Bartholomew');
        $this->employee('Carol', 'Carlisle');
        $this->actingAs($this->hrUser(), 'sanctum');

        $firstPage = $this->getJson('/api/hr/leave/register?year=2026&per_page=2&page=1')->assertOk();

        $this->assertCount(2, $firstPage->json('data'));
        $this->assertSame(1, $firstPage->json('meta.current_page'));
        $this->assertSame(2, $firstPage->json('meta.last_page'));
        $this->assertSame(2, $firstPage->json('meta.per_page'));
        $this->assertSame(3, $firstPage->json('meta.total'));

        $secondPage = $this->getJson('/api/hr/leave/register?year=2026&per_page=2&page=2')->assertOk();

        $this->assertCount(1, $secondPage->json('data'));
        $this->assertSame(2, $secondPage->json('meta.current_page'));

        // The two pages must not overlap.
        $firstPageIds = collect($firstPage->json('data'))->pluck('employee.id');
        $secondPageIds = collect($secondPage->json('data'))->pluck('employee.id');
        $this->assertEmpty($firstPageIds->intersect($secondPageIds));
    }

    public function test_user_without_leave_balance_view_permission_is_forbidden(): void
    {
        $employee = $this->employee('Plain', 'Employee');
        $user = User::create([
            'name' => uniqid('user_'),
            'email' => uniqid('user_') . '@test.local',
            'password' => bcrypt('secret'),
            'employee_id' => $employee->id,
            'department_id' => $employee->department_id,
        ]);

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/hr/leave/register?year=2026')->assertForbidden();
        $this->getJson('/api/hr/leave/register/export?year=2026')->assertForbidden();
    }

    public function test_export_downloads_a_spreadsheet(): void
    {
        $this->employee('Shadrack', 'Mutuku');
        $this->actingAs($this->hrUser(), 'sanctum');

        $response = $this->get('/api/hr/leave/register/export?year=2026');

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheet',
            $response->headers->get('Content-Type')
        );
    }

    private function employee(string $first, string $last): Employee
    {
        return Employee::create([
            'employee_id' => uniqid('EMP'),
            'first_name' => $first,
            'last_name' => $last,
            'department_id' => $this->department->id,
            'position' => 'Technician',
            'hire_date' => '2025-01-01',
            'status' => 'active',
        ]);
    }

    private function hrUser(): User
    {
        $role = Role::findOrCreate('HR', 'web');
        $user = User::create([
            'name' => uniqid('user_'),
            'email' => uniqid('user_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $user->assignRole($role);

        // Explicit 'web' guard: actingAs(..., 'sanctum') switches the default guard,
        // which would otherwise create/check this permission under 'sanctum' instead.
        Permission::findOrCreate(Permissions::LEAVE_BALANCE_VIEW, 'web');
        $user->givePermissionTo(Permissions::LEAVE_BALANCE_VIEW);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
