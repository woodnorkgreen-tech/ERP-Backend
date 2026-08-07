<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LeaveRequestPastRecordTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;
    private LeaveType $annualLeave;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-30 10:00:00');

        $this->department = Department::create(['name' => 'Production']);
        $this->annualLeave = LeaveType::query()
            ->where('code', 'ANNUAL')
            ->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_hr_can_record_past_leave_as_approved(): void
    {
        $employee = $this->employee();
        $hr = $this->userWithRole('HR');

        $this->actingAs($hr, 'sanctum');

        $this->postJson('/api/hr/leave/requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $this->annualLeave->id,
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-21',
            'session' => 'full_day',
            'reason' => 'Recorded from legacy leave tracker.',
            'record_as_approved' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_APPROVED)
            ->assertJsonPath('data.approved_by', $hr->id);

        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $employee->id,
            'status' => LeaveRequest::STATUS_APPROVED,
            'approved_by' => $hr->id,
        ]);
    }

    public function test_non_hr_cannot_record_immediate_approved_leave(): void
    {
        $employee = $this->employee();
        $user = $this->userWithRole('Employee', $employee);

        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/hr/leave/requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $this->annualLeave->id,
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-04',
            'session' => 'full_day',
            'reason' => 'Regular employee leave request.',
            'record_as_approved' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING)
            ->assertJsonPath('data.approved_by', null);
    }

    public function test_attachment_is_not_required_even_for_leave_types_that_flag_it(): void
    {
        $employee = $this->employee();
        $sickLeave = LeaveType::query()->where('code', 'SICK')->firstOrFail();
        $this->actingAs($this->userWithRole('Employee', $employee), 'sanctum');

        $this->postJson('/api/hr/leave/requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $sickLeave->id,
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-03',
            'session' => 'full_day',
            'reason' => 'Medical appointment.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_id' => uniqid('EMP'),
            'first_name' => 'Leave',
            'last_name' => 'Tester',
            'department_id' => $this->department->id,
            'position' => 'Technician',
            'hire_date' => '2025-01-01',
            'status' => 'active',
        ]);
    }

    private function userWithRole(string $roleName, ?Employee $employee = null): User
    {
        $role = Role::findOrCreate($roleName, 'web');
        $user = User::create([
            'name' => uniqid('user_'),
            'email' => uniqid('user_') . '@test.local',
            'password' => bcrypt('secret'),
            'employee_id' => $employee?->id,
            'department_id' => $employee?->department_id,
        ]);

        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
