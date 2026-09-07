<?php

namespace Tests\Feature\Hr;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Models\PayrollTaxBand;
use App\Modules\HR\Models\PayrollVariable;
use App\Modules\HR\Models\Payslip;
use App\Modules\HR\Services\Payroll\PayrollService;
use App\Modules\HR\Services\Payroll\PayrollFinancePostingService;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\PaymentSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PayrollIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_payroll_api_requires_manage_payroll_permission(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/hr/payroll/runs')->assertForbidden();
    }

    public function test_run_snapshot_uses_only_active_rules(): void
    {
        $this->actingAsPayrollManager();
        PayrollVariable::create(['name' => 'ACTIVE_RATE', 'value' => 0.1, 'is_active' => true]);
        PayrollVariable::create(['name' => 'DISABLED_RATE', 'value' => 0.9, 'is_active' => false]);
        PayrollTaxBand::create(['name' => 'Active band', 'min_amount' => 0, 'rate' => 0.1, 'sort_order' => 1, 'is_active' => true]);
        PayrollTaxBand::create(['name' => 'Disabled band', 'min_amount' => 0, 'rate' => 0.9, 'sort_order' => 2, 'is_active' => false]);

        $run = app(PayrollService::class)->initializeRun('2026-08');

        $this->assertArrayHasKey('ACTIVE_RATE', $run->snapshot_settings['variables']);
        $this->assertArrayNotHasKey('DISABLED_RATE', $run->snapshot_settings['variables']);
        $this->assertSame(['Active band'], collect($run->snapshot_settings['tax_bands'])->pluck('name')->all());
    }

    public function test_processing_persists_a_payslip_against_the_run(): void
    {
        $this->actingAsPayrollManager();
        $employee = $this->employee();
        $run = app(PayrollService::class)->initializeRun('2026-08');

        $payslip = app(PayrollService::class)->processEmployee($employee, $run);

        $this->assertSame($run->id, $payslip->payroll_run_id);
        $this->assertSame($employee->id, $payslip->employee_id);
        $this->assertSame('2026-08', $payslip->payroll_month);
    }

    public function test_employee_payslip_cannot_be_moved_to_another_run_for_same_month(): void
    {
        $this->actingAsPayrollManager();
        $employee = $this->employee();
        $locked = PayrollRun::create(['payroll_month' => '2026-08', 'status' => 'locked']);
        $draft = PayrollRun::create(['payroll_month' => '2026-08', 'status' => 'draft', 'snapshot_settings' => []]);
        Payslip::create([
            'payroll_run_id' => $locked->id,
            'employee_id' => $employee->id,
            'payroll_month' => '2026-08',
            'basic_salary' => 50000,
            'gross_pay' => 50000,
            'net_pay' => 45000,
            'tax_breakdown' => [],
            'ledger_breakdown' => [],
        ]);

        $this->expectException(\DomainException::class);
        app(PayrollService::class)->processEmployee($employee, $draft);
    }

    public function test_paid_run_cannot_be_rolled_back(): void
    {
        $this->actingAsPayrollManager();
        $run = PayrollRun::create(['payroll_month' => '2026-08', 'status' => 'paid']);

        $this->postJson("/api/hr/payroll/runs/{$run->id}/rollback")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('paid', $run->fresh()->status);
    }

    public function test_payroll_accrual_and_payment_create_balanced_traceable_journals(): void
    {
        $this->actingAsPayrollManager();
        $this->financeConfiguration();
        $employee = $this->employee();
        $run = PayrollRun::create([
            'payroll_month' => '2026-08', 'status' => 'locked',
            'total_gross' => 100000, 'total_net' => 80000, 'total_statutory' => 20000,
        ]);
        Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'payroll_month' => '2026-08', 'basic_salary' => 100000,
            'gross_pay' => 100000, 'net_pay' => 80000,
            'tax_breakdown' => ['paye' => 15000, 'nssf' => 1000, 'shif' => 2500, 'housing_levy' => 1500],
            'ledger_breakdown' => [], 'status' => 'locked',
        ]);
        $source = PaymentSource::firstOrFail();
        $posting = app(PayrollFinancePostingService::class);

        $accrual = $posting->postAccrual($run);
        $payment = $posting->postPayment($run->fresh(), $source, '2026-08-31', 'BANK-2026-08');

        $this->assertTrue($accrual->isBalanced());
        $this->assertTrue($payment->isBalanced());
        $this->assertSame('100000.00', $accrual->total_debit);
        $this->assertSame('80000.00', $payment->total_debit);
        $this->assertSame(PayrollRun::class, $accrual->source_type);
        $this->assertSame($run->id, $payment->source_id);
        $this->assertSame($accrual->id, $run->fresh()->accrual_journal_entry_id);
        $this->assertSame($payment->id, $run->fresh()->payment_journal_entry_id);
        $this->assertSame($accrual->id, $posting->postAccrual($run->fresh())->id);
        $this->assertSame($payment->id, $posting->postPayment($run->fresh(), $source, '2026-08-31', 'DUPLICATE')->id);
    }

    public function test_inconsistent_payslips_cannot_create_a_balanced_looking_header(): void
    {
        $this->actingAsPayrollManager();
        $this->financeConfiguration();
        $run = $this->runWithPayslip(110000);

        try {
            app(PayrollFinancePostingService::class)->postAccrual($run);
            $this->fail('Net pay above gross must not reach the ledger.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('do not reconcile', $exception->getMessage());
        }
        $this->assertNull($run->fresh()->accrual_journal_entry_id);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_payroll_payment_cannot_use_an_inactive_bank_account(): void
    {
        $this->actingAsPayrollManager();
        $this->financeConfiguration();
        $run = $this->runWithPayslip(80000);
        $posting = app(PayrollFinancePostingService::class);
        $posting->postAccrual($run);
        ChartOfAccount::where('code', '1010')->update(['is_active' => false]);

        try {
            $posting->postPayment($run->fresh(), PaymentSource::firstOrFail(), '2026-08-31', 'INACTIVE-BANK');
            $this->fail('An inactive bank account accepted a payroll payment.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('active postable accounts', $exception->getMessage());
        }
        $this->assertNull($run->fresh()->payment_journal_entry_id);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    private function runWithPayslip(int $net): PayrollRun
    {
        $run = PayrollRun::create(['payroll_month' => '2026-08', 'status' => 'locked']);
        Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $this->employee()->id,
            'payroll_month' => '2026-08', 'basic_salary' => 100000,
            'gross_pay' => 100000, 'net_pay' => $net,
            'tax_breakdown' => ['paye' => 15000], 'ledger_breakdown' => [], 'status' => 'locked',
        ]);

        return $run;
    }

    private function actingAsPayrollManager(): User
    {
        $user = $this->user();
        $permission = Permission::findOrCreate(Permissions::HR_MANAGE_PAYROLL, 'web');
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user);

        return $user;
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Payroll Test User',
            'email' => uniqid('payroll_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
    }

    private function employee(): Employee
    {
        $department = Department::create(['name' => 'Payroll Test Department']);

        return Employee::create([
            'employee_id' => 'PAY-' . uniqid(),
            'first_name' => 'Payroll',
            'last_name' => 'Employee',
            'department_id' => $department->id,
            'position' => 'Tester',
            'hire_date' => '2026-01-01',
            'status' => 'active',
            'salary' => 50000,
        ]);
    }

    private function financeConfiguration(): void
    {
        foreach ([
            ['1010', 'Bank', 'asset', 'balance_sheet', 'debit'],
            ['2130', 'PAYE Payable', 'liability', 'balance_sheet', 'credit'],
            ['2140', 'Statutory Deductions Payable', 'liability', 'balance_sheet', 'credit'],
            ['2160', 'Net Payroll Payable', 'liability', 'balance_sheet', 'credit'],
            ['7550', 'Salaries & Wages', 'expense', 'opex', 'debit'],
        ] as [$code, $name, $category, $type, $balance]) {
            ChartOfAccount::updateOrCreate(['code' => $code], [
                'name' => $name, 'category' => $category, 'account_type' => $type,
                'normal_balance' => $balance, 'is_postable' => true, 'is_active' => true,
            ]);
        }
        AccountingPeriod::create([
            'year' => 2026, 'month' => 8, 'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31', 'status' => AccountingPeriod::STATUS_OPEN,
        ]);
        PaymentSource::create([
            'code' => 'BANK-TEST', 'name' => 'Test bank', 'type' => 'bank',
            'gl_account_id' => ChartOfAccount::where('code', '1010')->value('id'),
            'currency' => 'KES', 'is_active' => true,
        ]);
    }
}
