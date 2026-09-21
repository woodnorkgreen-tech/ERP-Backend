<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveBalanceAdjustment;
use App\Modules\HR\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportPastLeaveTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Employee $employee;
    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create();
        $department = Department::create(['name' => 'Import testing']);
        $this->employee = Employee::create([
            'employee_id' => 'IMPORT-001',
            'first_name' => 'Leave',
            'last_name' => 'Tester',
            'department_id' => $department->id,
            'position' => 'Technician',
            'hire_date' => '2025-01-01',
            'status' => 'active',
        ]);
        $this->csv = tempnam(sys_get_temp_dir(), 'leave-import-');
        file_put_contents($this->csv, implode("\n", [
            'staff_name_sheet,matched_emp_code,row_type,leave_type,start_date,end_date,days,reason',
            'Leave Tester,IMPORT-001,REQUEST,ANNUAL,2026-01-05,2026-01-06,2,Legacy request',
            'Leave Tester,IMPORT-001,ADJUSTMENT,ANNUAL,,,3,Legacy adjustment',
            'Unmatched,,SKIP,ANNUAL,,,1,Needs review',
            '',
        ]));
    }

    protected function tearDown(): void
    {
        if (isset($this->csv) && is_file($this->csv)) {
            unlink($this->csv);
        }

        parent::tearDown();
    }

    public function test_import_records_approved_leave_and_adjustments_without_duplicates(): void
    {
        $arguments = ['csv' => $this->csv, '--actor' => $this->actor->id, '--year' => 2026];
        $this->artisan('hr:import-past-leave', $arguments)->assertSuccessful();
        $this->artisan('hr:import-past-leave', $arguments)->assertSuccessful();

        $this->assertSame(1, LeaveRequest::where('employee_id', $this->employee->id)->count());
        $this->assertSame(1, LeaveBalanceAdjustment::where('employee_id', $this->employee->id)->count());
        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $this->employee->id,
            'status' => LeaveRequest::STATUS_APPROVED,
            'days_requested' => 2,
            'approved_by' => $this->actor->id,
        ]);
        $this->assertDatabaseHas('leave_balance_adjustments', [
            'employee_id' => $this->employee->id,
            'year' => 2026,
            'days' => 3,
            'created_by' => $this->actor->id,
        ]);
    }

    public function test_dry_run_leaves_no_imported_records(): void
    {
        $this->artisan('hr:import-past-leave', [
            'csv' => $this->csv,
            '--actor' => $this->actor->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, LeaveRequest::where('employee_id', $this->employee->id)->count());
        $this->assertSame(0, LeaveBalanceAdjustment::where('employee_id', $this->employee->id)->count());
    }

    public function test_missing_columns_fail_without_importing(): void
    {
        file_put_contents($this->csv, "staff_name_sheet,days\nLeave Tester,2\n");

        $this->artisan('hr:import-past-leave', [
            'csv' => $this->csv,
            '--actor' => $this->actor->id,
        ])->assertFailed();

        $this->assertSame(0, LeaveRequest::where('employee_id', $this->employee->id)->count());
    }
}
