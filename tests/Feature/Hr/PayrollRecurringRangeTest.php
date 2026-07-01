<?php

namespace Tests\Feature\Hr;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PayrollLedger;
use App\Modules\HR\Services\Payroll\PayrollEmployeeDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollRecurringRangeTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'Engineering']);
        $this->employee = Employee::create([
            'employee_id' => 'EMP101',
            'first_name' => 'Elon',
            'last_name' => 'Musk',
            'department_id' => $dept->id,
            'position' => 'Chief Engineer',
            'hire_date' => '2026-01-01',
            'status' => 'active',
            'salary' => 50000.00
        ]);
    }

    public function test_recurring_ledger_range_filtering(): void
    {
        // 1. One-off addition for 2026-06
        PayrollLedger::create([
            'employee_id' => $this->employee->id,
            'ledger_month' => '2026-06',
            'type' => 'addition',
            'amount_type' => 'fixed',
            'amount_value' => 5000.00,
            'name' => 'One-off Bonus',
            'is_recurring' => false,
            'recurring_end_month' => null
        ]);

        // 2. Indefinite recurring deduction starting 2026-05
        PayrollLedger::create([
            'employee_id' => $this->employee->id,
            'ledger_month' => '2026-05',
            'type' => 'deduction',
            'amount_type' => 'fixed',
            'amount_value' => 1500.00,
            'name' => 'Indefinite Benefit Recovery',
            'is_recurring' => true,
            'recurring_end_month' => null
        ]);

        // 3. Split recovery starting 2026-06 and ending 2026-07
        PayrollLedger::create([
            'employee_id' => $this->employee->id,
            'ledger_month' => '2026-06',
            'type' => 'deduction',
            'amount_type' => 'fixed',
            'amount_value' => 1000.00,
            'name' => 'Salary Advance Recovery (Split)',
            'is_recurring' => true,
            'recurring_end_month' => '2026-07'
        ]);

        // --- Verify Month 2026-05 ---
        // Expected: Only "Indefinite Benefit Recovery" (starts 2026-05)
        $dto05 = PayrollEmployeeDTO::fromModel($this->employee, '2026-05');
        $ledgerNames05 = $dto05->ledgers->pluck('name')->toArray();
        $this->assertCount(1, $ledgerNames05);
        $this->assertContains('Indefinite Benefit Recovery', $ledgerNames05);

        // --- Verify Month 2026-06 ---
        // Expected: All 3 ledgers are active
        $dto06 = PayrollEmployeeDTO::fromModel($this->employee, '2026-06');
        $ledgerNames06 = $dto06->ledgers->pluck('name')->toArray();
        $this->assertCount(3, $ledgerNames06);
        $this->assertContains('One-off Bonus', $ledgerNames06);
        $this->assertContains('Indefinite Benefit Recovery', $ledgerNames06);
        $this->assertContains('Salary Advance Recovery (Split)', $ledgerNames06);

        // --- Verify Month 2026-07 ---
        // Expected: "Indefinite Benefit Recovery" & "Salary Advance Recovery (Split)" (ends 2026-07)
        $dto07 = PayrollEmployeeDTO::fromModel($this->employee, '2026-07');
        $ledgerNames07 = $dto07->ledgers->pluck('name')->toArray();
        $this->assertCount(2, $ledgerNames07);
        $this->assertContains('Indefinite Benefit Recovery', $ledgerNames07);
        $this->assertContains('Salary Advance Recovery (Split)', $ledgerNames07);
        $this->assertNotContains('One-off Bonus', $ledgerNames07);

        // --- Verify Month 2026-08 ---
        // Expected: Only "Indefinite Benefit Recovery" (Split has expired)
        $dto08 = PayrollEmployeeDTO::fromModel($this->employee, '2026-08');
        $ledgerNames08 = $dto08->ledgers->pluck('name')->toArray();
        $this->assertCount(1, $ledgerNames08);
        $this->assertContains('Indefinite Benefit Recovery', $ledgerNames08);
        $this->assertNotContains('Salary Advance Recovery (Split)', $ledgerNames08);
    }
}
