<?php

namespace Tests\Feature\Hr;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pins the terminate → list → restore lifecycle: archived (soft-deleted) staff
 * are hidden from default lists but reachable via an explicit terminated filter,
 * counted in the KPI stats, and restorable — but only by HR admins.
 */
class EmployeeRestoreTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = Department::create(['name' => 'Production']);
    }

    private function hrUser(): User
    {
        $u = $this->makeUser();
        // Explicit guard: actingAs(..., 'sanctum') switches the default guard,
        // which would otherwise create these under 'sanctum' instead of 'web'.
        // The route middleware checks permissions, the policy checks the role —
        // production seeds the HR role with these permissions; mirror that here.
        $role = Role::findOrCreate('HR', 'web');
        foreach ([Permissions::EMPLOYEE_READ, Permissions::EMPLOYEE_DELETE] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $u->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function userWith(array $permissions): User
    {
        $u = $this->makeUser();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $u->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function makeUser(): User
    {
        return User::create([
            'name'     => uniqid('user_'),
            'email'    => uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    private function archivedEmployee(): Employee
    {
        $employee = Employee::create([
            'employee_id'        => uniqid('EMP'),
            'first_name'         => 'Gone',
            'last_name'          => 'Person',
            'department_id'      => $this->dept->id,
            'position'           => 'Technician',
            'hire_date'          => now()->subYear()->toDateString(),
            'status'             => 'terminated',
            'termination_reason' => 'Resigned',
            'termination_type'   => 'resignation',
            'termination_date'   => now()->toDateString(),
        ]);
        $employee->delete(); // soft delete, as destroy()/the offboarding final gate do

        return $employee;
    }

    public function test_terminated_filter_returns_archived_employees(): void
    {
        $archived = $this->archivedEmployee();
        $this->actingAs($this->hrUser(), 'sanctum');

        $this->getJson('/api/hr/employees?status=terminated&per_page=15')
            ->assertOk()
            ->assertJsonPath('data.0.id', $archived->id);
    }

    public function test_default_list_and_dropdowns_still_exclude_archived_employees(): void
    {
        $this->archivedEmployee();
        $this->actingAs($this->hrUser(), 'sanctum');

        $this->getJson('/api/hr/employees?per_page=15')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_stats_count_archived_employees_as_inactive(): void
    {
        $this->archivedEmployee();
        $this->actingAs($this->hrUser(), 'sanctum');

        $this->getJson('/api/hr/employees/stats')
            ->assertOk()
            ->assertJsonPath('inactive', 1)
            ->assertJsonPath('total', 1);
    }

    public function test_restore_reinstates_employee_and_clears_termination_details(): void
    {
        $archived = $this->archivedEmployee();
        $this->actingAs($this->hrUser(), 'sanctum');

        $this->postJson("/api/hr/employees/{$archived->id}/restore")->assertOk();

        $employee = Employee::find($archived->id); // default scope: must be un-trashed
        $this->assertNotNull($employee);
        $this->assertSame('active', $employee->status);
        $this->assertNull($employee->termination_reason);
        $this->assertNull($employee->termination_type);
        $this->assertNull($employee->termination_date);
    }

    public function test_restore_reactivates_a_deactivated_linked_user_account(): void
    {
        $archived = $this->archivedEmployee();
        $account  = $this->makeUser();
        $account->update(['employee_id' => $archived->id, 'is_active' => false]);

        $this->actingAs($this->hrUser(), 'sanctum');

        $this->postJson("/api/hr/employees/{$archived->id}/restore")
            ->assertOk()
            ->assertJsonPath('message', 'Employee reinstated and their user account reactivated.');

        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_restore_is_denied_without_hr_admin_role(): void
    {
        $archived = $this->archivedEmployee();

        // Even the delete permission is not enough — reinstatement is role-gated.
        $this->actingAs($this->userWith([Permissions::EMPLOYEE_DELETE]), 'sanctum');

        $this->postJson("/api/hr/employees/{$archived->id}/restore")->assertForbidden();
        $this->assertTrue(Employee::withTrashed()->find($archived->id)->trashed());
    }
}
