<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PayrollLedger;
use App\Modules\HR\Models\SalaryAdvanceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalaryAdvanceSplitTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name'     => 'HR Officer',
            'email'    => 'hr.officer@test.local',
            'password' => bcrypt('secret'),
            'is_active'=> true
        ]));

        $dept = Department::create(['name' => 'Engineering']);
        $this->employee = Employee::create([
            'employee_id'   => 'EMP101',
            'first_name'    => 'Elon',
            'last_name'     => 'Musk',
            'department_id' => $dept->id,
            'position'      => 'Chief Engineer',
            'hire_date'     => '2026-01-01',
            'status'        => 'active',
            'salary'        => 50000.00
        ]);
    }

    public function test_approve_salary_advance_with_one_off_recovery(): void
    {
        $advance = SalaryAdvanceRequest::create([
            'employee_id' => $this->employee->id,
            'amount' => 2000.00,
            'reason' => 'School fees',
            'target_payroll_month' => '2026-06',
            'status' => 'pending'
        ]);

        $response = $this->postJson("/api/hr/advances/{$advance->id}/approve", [
            'hr_remarks' => 'Approved one-off recovery',
            'split_installments' => false
        ]);

        $response->assertStatus(200);

        $advance->refresh();
        $this->assertSame('approved', $advance->status);
        $this->assertNotNull($advance->ledger_id);

        $ledger = PayrollLedger::findOrFail($advance->ledger_id);
        $this->assertSame('Salary Advance Recovery', $ledger->name);
        $this->assertEquals(2000.00, $ledger->amount_value);
        $this->assertSame('2026-06', $ledger->ledger_month);
        $this->assertFalse((bool) $ledger->is_recurring);
        $this->assertNull($ledger->recurring_end_month);
        $this->assertStringContainsString('Total Principal: KES 2,000.00', $ledger->description);
    }

    public function test_approve_salary_advance_with_even_split_recovery(): void
    {
        $advance = SalaryAdvanceRequest::create([
            'employee_id' => $this->employee->id,
            'amount' => 2000.00,
            'reason' => 'Emergency',
            'target_payroll_month' => '2026-06',
            'status' => 'pending'
        ]);

        $response = $this->postJson("/api/hr/advances/{$advance->id}/approve", [
            'hr_remarks' => 'Approved 2-month split',
            'split_installments' => true,
            'monthly_installment' => 1000.00
        ]);

        $response->assertStatus(200);

        $advance->refresh();
        $this->assertSame('approved', $advance->status);

        // Should have created exactly 1 ledger since it is evenly split
        $ledgers = PayrollLedger::where('employee_id', $this->employee->id)->get();
        $this->assertCount(1, $ledgers);

        $ledger = $ledgers->first();
        $this->assertStringContainsString('Split KES 1,000/mo', $ledger->name);
        $this->assertEquals(1000.00, $ledger->amount_value);
        $this->assertSame('2026-06', $ledger->ledger_month);
        $this->assertTrue((bool) $ledger->is_recurring);
        $this->assertSame('2026-07', $ledger->recurring_end_month);
        $this->assertStringContainsString('Total Principal: KES 2,000.00', $ledger->description);
    }

    public function test_approve_salary_advance_with_uneven_split_recovery(): void
    {
        $advance = SalaryAdvanceRequest::create([
            'employee_id' => $this->employee->id,
            'amount' => 2500.00,
            'reason' => 'Car repairs',
            'target_payroll_month' => '2026-06',
            'status' => 'pending'
        ]);

        $response = $this->postJson("/api/hr/advances/{$advance->id}/approve", [
            'hr_remarks' => 'Approved recovery with remainder',
            'split_installments' => true,
            'monthly_installment' => 1000.00
        ]);

        $response->assertStatus(200);

        $advance->refresh();
        $this->assertSame('approved', $advance->status);

        // Should have created exactly 2 ledgers (1 recurring, 1 remainder one-off)
        $ledgers = PayrollLedger::where('employee_id', $this->employee->id)->orderBy('ledger_month')->get();
        $this->assertCount(2, $ledgers);

        $baseLedger = $ledgers->first();
        $this->assertStringContainsString('Split KES 1,000/mo', $baseLedger->name);
        $this->assertEquals(1000.00, $baseLedger->amount_value);
        $this->assertSame('2026-06', $baseLedger->ledger_month);
        $this->assertTrue((bool) $baseLedger->is_recurring);
        $this->assertSame('2026-07', $baseLedger->recurring_end_month);
        $this->assertStringContainsString('Total Principal: KES 2,500.00', $baseLedger->description);

        $remainderLedger = $ledgers->last();
        $this->assertStringContainsString('Remainder', $remainderLedger->name);
        $this->assertEquals(500.00, $remainderLedger->amount_value);
        $this->assertSame('2026-08', $remainderLedger->ledger_month);
        $this->assertFalse((bool) $remainderLedger->is_recurring);
        $this->assertNull($remainderLedger->recurring_end_month);
        $this->assertStringContainsString('Total Principal: KES 2,500.00', $remainderLedger->description);
    }
}
