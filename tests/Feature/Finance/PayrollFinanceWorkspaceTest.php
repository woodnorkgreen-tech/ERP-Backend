<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSalaryHistory;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Models\Payslip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PayrollFinanceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([Permissions::FINANCE_PAYROLL_READ, Permissions::FINANCE_PAYROLL_PAY, Permissions::FINANCE_PAYROLL_LABOUR_CLASSIFICATION_MANAGE] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->reader = User::factory()->create(['is_active' => true]);
        $this->reader->givePermissionTo(Permissions::FINANCE_PAYROLL_READ);
    }

    public function test_summary_permission_exposes_aggregate_payroll_without_employee_salary_or_bank_data(): void
    {
        $department = Department::create(['name' => 'Private payroll']);
        $employee = Employee::create([
            'employee_id' => 'PAY-PRIVACY', 'first_name' => 'Private', 'last_name' => 'Person',
            'department_id' => $department->id, 'position' => 'Tester', 'hire_date' => '2025-01-01', 'status' => 'active',
            'salary' => 99000, 'email' => 'private@example.test', 'phone' => '0700000000',
            'bank_name' => 'Secret Bank', 'account_number' => '123456789',
        ]);
        $run = PayrollRun::create(['payroll_month' => '2026-09', 'status' => 'processing', 'total_gross' => 123000, 'total_net' => 70000, 'total_statutory' => 29000]);
        Payslip::create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'payroll_month' => '2026-09', 'basic_salary' => 99000, 'gross_pay' => 123000, 'net_pay' => 70000, 'tax_breakdown' => ['paye' => 20000], 'ledger_breakdown' => [], 'status' => 'processed']);

        $response = $this->actingAs($this->reader, 'sanctum')->getJson('/api/finance/payroll')->assertOk();
        $json = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('99000.00', $json, 'The overview must not disclose an individual salary.');
        $this->assertStringNotContainsString('private@example.test', $json);
        $this->assertStringNotContainsString('Secret Bank', $json);
        $this->assertStringNotContainsString('123456789', $json);
        $this->assertSame(1, $response->json('data.runs.0.employees'));
        $this->assertArrayNotHasKey('payslips', $response->json('data.runs.0'));
    }

    public function test_payroll_finance_endpoints_require_the_finance_read_permission(): void
    {
        $nobody = User::factory()->create(['is_active' => true]);
        foreach (['/api/finance/payroll', '/api/finance/payroll/readiness', '/api/finance/payroll/liabilities', '/api/finance/payroll/payments', '/api/finance/payroll/labour-classification'] as $url) {
            $this->actingAs($nobody, 'sanctum')->getJson($url)->assertForbidden();
        }
    }

    public function test_readiness_distinguishes_missing_zero_stale_and_configured_salary_records(): void
    {
        $department = Department::create(['name' => 'Readiness']);
        $missing = $this->employee($department, 'MISSING', null);
        $zero = $this->employee($department, 'ZERO', 0);
        $stale = $this->employee($department, 'STALE', 50000);
        $configured = $this->employee($department, 'READY', 60000);
        EmployeeSalaryHistory::where('employee_id', $stale->id)->delete();
        EmployeeSalaryHistory::create(['employee_id' => $configured->id, 'salary' => 60000, 'valid_from' => '2026-01-01']);
        AccountingPeriod::create(['year' => now()->year, 'month' => now()->month, 'starts_on' => now()->startOfMonth(), 'ends_on' => now()->endOfMonth(), 'status' => 'open']);
        foreach (['7550', '5200', '2160', '2130', '2140'] as $code) {
            ChartOfAccount::firstOrCreate(['code' => $code], [ 'name' => $code, 'category' => str_starts_with($code, '7') || str_starts_with($code, '5') ? 'expense' : 'liability', 'account_type' => str_starts_with($code, '7') || str_starts_with($code, '5') ? ($code === '5200' ? 'direct_cost' : 'opex') : 'balance_sheet', 'normal_balance' => str_starts_with($code, '7') || str_starts_with($code, '5') ? 'debit' : 'credit', 'is_postable' => true, 'is_active' => true]);
        }

        $data = $this->actingAs($this->reader, 'sanctum')->getJson('/api/finance/payroll/readiness')->assertOk()->json('data');
        $this->assertSame(4, $data['salary']['active']);
        $this->assertSame(2, $data['salary']['configured']);
        $this->assertSame(1, $data['salary']['missing']);
        $this->assertSame(1, $data['salary']['zero_review']);
        $this->assertSame(1, $data['salary']['stale']);
        $this->assertSame('not_ready', $data['summary']['state']);
        $this->assertArrayNotHasKey('employees', $data);
    }

    public function test_labour_classification_is_effective_dated_and_audited(): void
    {
        // A fixed date keeps the future-effective policy test stable after October 1.
        $this->travelTo(\Carbon\Carbon::parse('2026-09-30'));
        $manager = User::factory()->create(['is_active' => true]);
        $manager->givePermissionTo([Permissions::FINANCE_PAYROLL_READ, Permissions::FINANCE_PAYROLL_LABOUR_CLASSIFICATION_MANAGE]);
        $department = Department::create(['name' => 'Production']);

        $this->actingAs($manager, 'sanctum')->postJson("/api/finance/payroll/labour-classification/{$department->id}", [
            'classification' => 'direct', 'effective_from' => '2026-10-01', 'reason' => 'Approved Finance policy decision.',
        ])->assertOk();

        $this->assertDatabaseHas('department_labour_classifications', [
            'department_id' => $department->id, 'classification' => 'direct', 'effective_from' => '2026-10-01',
            'set_by' => $manager->id, 'reason' => 'Approved Finance policy decision.',
        ]);
        $row = $this->actingAs($manager, 'sanctum')->getJson('/api/finance/payroll/labour-classification')->assertOk()->json('data.0');
        $this->assertSame('unclassified', $row['classification']);
        $this->assertSame('direct', $row['history'][0]['classification']);
        $this->assertSame($manager->name, $row['history'][0]['changed_by']['name']);
    }

    private function employee(Department $department, string $code, ?int $salary): Employee
    {
        return Employee::create(['employee_id' => 'PAY-'.$code, 'first_name' => $code, 'last_name' => 'Employee', 'department_id' => $department->id, 'position' => 'Tester', 'hire_date' => '2025-01-01', 'status' => 'active', 'salary' => $salary]);
    }
}
