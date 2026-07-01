<?php

namespace Tests\Feature\Overtime;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\OTEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pins the overtime authorization matrix now that it lives in OvertimePolicy (one place)
 * instead of being re-derived inline in each controller method.
 */
class OvertimePolicyTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = Department::create(['name' => 'Production']);
    }

    private function user(?string $role = null, ?int $employeeId = null): User
    {
        $u = User::create([
            'name'        => uniqid('user_'),
            'email'       => uniqid() . '@test.local',
            'password'    => bcrypt('secret'),
            'employee_id' => $employeeId,
        ]);
        if ($role) {
            Role::findOrCreate($role);
            $u->assignRole($role);
        }
        return $u->fresh();
    }

    private function employee(?int $managerId = null): Employee
    {
        return Employee::create([
            'employee_id'   => uniqid('EMP'),
            'first_name'    => 'Sub',
            'last_name'     => 'Ordinate',
            'department_id' => $this->dept->id,
            'position'      => 'Technician',
            'hire_date'     => now()->subYear()->toDateString(),
            'status'        => 'active',
            'manager_id'    => $managerId,
        ]);
    }

    private function reviewedEntryFor(Employee $emp): OTEntry
    {
        return OTEntry::create([
            'employee_id' => $emp->id,
            'work_date'   => now()->toDateString(),
            'start_time'  => '18:00',
            'end_time'    => '21:00',
            'hours'       => 3,
            'status'      => 'under_review',
        ]);
    }

    public function test_hr_can_final_approve_but_a_plain_employee_cannot(): void
    {
        $emp = $this->employee();
        $entry = $this->reviewedEntryFor($emp);

        $hr = $this->user('HR');
        $plain = $this->user(null, $emp->id);

        $this->assertTrue(Gate::forUser($hr)->allows('hrApprove', $entry));
        $this->assertFalse(Gate::forUser($plain)->allows('hrApprove', $entry));
    }

    public function test_hr_cannot_final_approve_their_own_entry_but_super_admin_can(): void
    {
        $hrEmp = $this->employee();
        $entry = $this->reviewedEntryFor($hrEmp);

        $hrSelf = $this->user('HR', $hrEmp->id);
        $superSelf = $this->user('Super Admin', $hrEmp->id);

        $this->assertFalse(Gate::forUser($hrSelf)->allows('hrApprove', $entry), 'Segregation of duties: HR may not self-approve.');
        $this->assertTrue(Gate::forUser($superSelf)->allows('hrApprove', $entry), 'Super Admin keeps an escape hatch.');
    }

    public function test_a_direct_manager_can_supervisor_approve_a_subordinate(): void
    {
        $manager = $this->employee();
        $subordinate = $this->employee(managerId: $manager->id);
        $entry = $this->reviewedEntryFor($subordinate);

        $managerUser = $this->user(null, $manager->id); // no global role — authority is hierarchical
        $stranger = $this->user(null, $this->employee()->id);

        $this->assertTrue(Gate::forUser($managerUser)->allows('supervisorApprove', $entry));
        $this->assertFalse(Gate::forUser($stranger)->allows('supervisorApprove', $entry));
    }

    public function test_overtime_approve_hr_permission_alone_authorizes_final_approval(): void
    {
        // The crux of the consolidation: a permission — not just a hardcoded role name —
        // is sufficient. A user holding OVERTIME_APPROVE_HR with no HR role can approve.
        Permission::findOrCreate(Permissions::OVERTIME_APPROVE_HR);
        $approver = $this->user();
        $approver->givePermissionTo(Permissions::OVERTIME_APPROVE_HR);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $entry = $this->reviewedEntryFor($this->employee());

        $this->assertTrue(Gate::forUser($approver->fresh())->allows('hrApprove', $entry));
    }

    public function test_only_super_admin_may_delete(): void
    {
        $entry = $this->reviewedEntryFor($this->employee());

        $this->assertFalse(Gate::forUser($this->user('HR'))->allows('delete', $entry));
        $this->assertTrue(Gate::forUser($this->user('Super Admin'))->allows('delete', $entry));
    }
}
