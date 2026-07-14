<?php

namespace Tests\Feature\Notifications;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\OTEntry;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Models\Payslip;
use App\Modules\HR\Models\SalaryAdvanceRequest;
use App\Modules\HR\Services\OvertimeService;
use App\Modules\Notifications\Models\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Payroll, Overtime, and Salary Advance previously sent no notifications at
 * all. These tests guard the newly wired dispatch points (via the
 * centralized Notifications module) so the recipient and type stay correct.
 */
class HrModuleNotificationWiringTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->department = Department::create(['name' => 'Production']);
    }

    // ── Overtime ─────────────────────────────────────────────────────────────

    public function test_overtime_submission_notifies_supervisor_approvers(): void
    {
        $employee = $this->employee();
        $approver = $this->userWithPermission(Permissions::OVERTIME_APPROVE_SUPERVISOR);
        $this->actingAsHrUser();

        $entry = $this->otEntry($employee, 'draft');
        app(OvertimeService::class)->submitEntry($entry);

        $this->assertTrue(
            AppNotification::where('user_id', $approver->id)
                ->where('type', 'overtime_submitted')
                ->exists()
        );
    }

    public function test_supervisor_approval_notifies_hr_approvers(): void
    {
        $employee = $this->employee();
        $hrApprover = $this->userWithPermission(Permissions::OVERTIME_APPROVE_HR);
        $this->actingAsHrUser();

        $entry = $this->otEntry($employee, 'submitted');
        app(OvertimeService::class)->supervisorApprove($entry);

        $this->assertTrue(
            AppNotification::where('user_id', $hrApprover->id)
                ->where('type', 'overtime_submitted')
                ->where('title', 'Overtime Awaiting HR Approval')
                ->exists()
        );
    }

    public function test_hr_approval_notifies_the_employee(): void
    {
        $employeeUser = $this->userLinkedToNewEmployee();
        $this->actingAsHrUser();

        $entry = $this->otEntry($employeeUser->employee, 'under_review');
        app(OvertimeService::class)->hrApprove($entry);

        $this->assertTrue(
            AppNotification::where('user_id', $employeeUser->id)
                ->where('type', 'overtime_approved')
                ->exists()
        );
    }

    public function test_rejection_notifies_the_employee(): void
    {
        $employeeUser = $this->userLinkedToNewEmployee();
        $hrUser = $this->actingAsHrUser();
        Sanctum::actingAs($hrUser);

        $entry = $this->otEntry($employeeUser->employee, 'submitted');

        $this->postJson("/api/hr/overtime/{$entry->id}/reject", ['reason' => 'Insufficient justification'])
            ->assertOk();

        $this->assertTrue(
            AppNotification::where('user_id', $employeeUser->id)
                ->where('type', 'overtime_rejected')
                ->exists()
        );
    }

    // ── Salary Advance ───────────────────────────────────────────────────────

    public function test_salary_advance_request_notifies_hr(): void
    {
        $hrUser = $this->userWithRole('HR');
        $employeeUser = $this->userLinkedToNewEmployee(salary: 100000);
        Sanctum::actingAs($employeeUser);

        $this->postJson('/api/hr/my-advances', [
            'amount' => 20000,
            'reason' => 'School fees',
            'target_payroll_month' => now()->format('Y-m'),
        ])->assertStatus(201);

        $this->assertTrue(
            AppNotification::where('user_id', $hrUser->id)
                ->where('type', 'salary_advance_requested')
                ->exists()
        );
    }

    public function test_salary_advance_approval_notifies_the_employee(): void
    {
        $employeeUser = $this->userLinkedToNewEmployee(salary: 100000);
        $hrUser = $this->actingAsHrUser();
        Sanctum::actingAs($hrUser);

        $advance = SalaryAdvanceRequest::create([
            'employee_id' => $employeeUser->employee_id,
            'amount' => 20000,
            'reason' => 'School fees',
            'target_payroll_month' => now()->format('Y-m'),
            'status' => 'pending',
        ]);

        $this->postJson("/api/hr/advances/{$advance->id}/approve", [])->assertOk();

        $this->assertTrue(
            AppNotification::where('user_id', $employeeUser->id)
                ->where('type', 'salary_advance_approved')
                ->exists()
        );
    }

    public function test_salary_advance_rejection_notifies_the_employee(): void
    {
        $employeeUser = $this->userLinkedToNewEmployee(salary: 100000);
        $hrUser = $this->actingAsHrUser();
        Sanctum::actingAs($hrUser);

        $advance = SalaryAdvanceRequest::create([
            'employee_id' => $employeeUser->employee_id,
            'amount' => 20000,
            'reason' => 'School fees',
            'target_payroll_month' => now()->format('Y-m'),
            'status' => 'pending',
        ]);

        $this->postJson("/api/hr/advances/{$advance->id}/reject", ['hr_remarks' => 'Exceeds cap'])
            ->assertOk();

        $this->assertTrue(
            AppNotification::where('user_id', $employeeUser->id)
                ->where('type', 'salary_advance_rejected')
                ->exists()
        );
    }

    // ── Payroll ──────────────────────────────────────────────────────────────

    public function test_marking_a_run_paid_notifies_each_employee_with_a_payslip(): void
    {
        $employeeUser = $this->userLinkedToNewEmployee();
        $this->actingAsHrUser();

        $run = PayrollRun::create([
            'payroll_month' => now()->format('Y-m'),
            'status' => 'locked',
            'created_by' => auth()->id(),
        ]);

        Payslip::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employeeUser->employee_id,
            'payroll_month' => $run->payroll_month,
            'basic_salary' => 100000,
            'gross_pay' => 100000,
            'net_pay' => 85000,
            'tax_breakdown' => [],
            'ledger_breakdown' => [],
            'status' => 'locked',
        ]);

        $this->postJson("/api/hr/payroll/runs/{$run->id}/mark-paid")->assertOk();

        $this->assertTrue(
            AppNotification::where('user_id', $employeeUser->id)
                ->where('type', 'payroll_payslip_ready')
                ->exists()
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function employee(): Employee
    {
        return Employee::create([
            'employee_id' => 'EMP-' . uniqid(),
            'first_name' => 'Test',
            'last_name' => 'Worker',
            'department_id' => $this->department->id,
            'position' => 'Technician',
            'hire_date' => now()->subYear()->toDateString(),
            'status' => 'active',
            'salary' => 100000,
        ]);
    }

    /** @return User&object{employee: Employee} */
    private function userLinkedToNewEmployee(float $salary = 100000): User
    {
        $employee = $this->employee();
        $employee->update(['salary' => $salary]);

        $user = User::create([
            'name' => 'Employee ' . uniqid(),
            'email' => uniqid('employee_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
            'employee_id' => $employee->id,
        ]);

        $user->setRelation('employee', $employee);

        return $user;
    }

    private function otEntry(Employee $employee, string $status): OTEntry
    {
        $attributes = [
            'employee_id' => $employee->id,
            'work_date' => now()->toDateString(),
            'start_time' => '18:00',
            'end_time' => '21:00',
            'hours' => 3,
            'status' => $status,
        ];

        if (in_array($status, ['submitted', 'under_review'], true)) {
            $attributes['submitted_by'] = auth()->id();
        }
        if ($status === 'under_review') {
            $attributes['supervisor_approved_by'] = auth()->id();
            $attributes['supervisor_approved_at'] = now();
        }

        return OTEntry::create($attributes);
    }

    /**
     * Broadcast-by-permission targeting also requires module visibility
     * (NotificationService::userCanSeeModule), which for the 'hr' module is
     * role-based — so the recipient needs the 'HR' role alongside the
     * specific permission, matching how access is actually granted in
     * practice (permissions are layered onto roles, not assigned standalone).
     */
    private function userWithPermission(string $permission): User
    {
        Permission::findOrCreate($permission, 'web');
        $user = $this->userWithRole('HR');
        $user->givePermissionTo($permission);

        return $user;
    }

    private function userWithRole(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = $this->plainUser();
        $user->assignRole($role);

        return $user;
    }

    private function plainUser(): User
    {
        return User::create([
            'name' => 'HR User ' . uniqid(),
            'email' => uniqid('hr_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
    }

    /** Acting user for state-transition calls that don't go through Gate checks in tests. */
    private function actingAsHrUser(): User
    {
        $user = $this->userWithRole('Super Admin');
        $this->actingAs($user);

        return $user;
    }
}
