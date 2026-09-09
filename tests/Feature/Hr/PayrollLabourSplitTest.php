<?php

namespace Tests\Feature\Hr;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Models\Payslip;
use App\Modules\HR\Services\Payroll\PayrollFinancePostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Stage 2: payroll stops being entirely office overhead, and the employer's own
 * statutory cost reaches the accounts.
 *
 * Two defects, both silent:
 *
 * 1. Every shilling of gross pay debited `7550 Salaries & Wages`, an operating
 *    expense — so a technician building a client's stand was recorded exactly
 *    like an accounts clerk, and gross margin could not be computed at all.
 * 2. The employer's share of the National Social Security Fund and the
 *    Affordable Housing Levy was computed and then recorded nowhere, so the cost
 *    of employing people was understated by that amount every month.
 *
 * What these tests deliberately DO NOT assert is labour reaching individual
 * jobs. WNG records no hours against jobs — `attendance_records` and
 * `task_time_entries` are both empty — so job-level labour cost is not
 * available, and inventing an allocation would produce job margins that look
 * precise and are fiction.
 */
class PayrollLabourSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAsPayrollManager();
        $this->financeConfiguration();
    }

    public function test_unclassified_departments_behave_exactly_as_before(): void
    {
        // The safety property. An installation that has classified nothing must
        // post precisely what it posted before the split existed, so shipping
        // this cannot restate a month anybody has already reported on.
        $run = $this->runFor($this->employeeIn('Accounts', null), 100000, 70000);

        app(PayrollFinancePostingService::class)->postAccrual($run);

        $this->assertSame(100000.0, $this->debitOn('7550'));
        $this->assertSame(0.0, $this->debitOn('5200'));
    }

    public function test_a_department_marked_direct_charges_cost_of_sales_instead(): void
    {
        $run = $this->runFor($this->employeeIn('Production', 'direct'), 100000, 70000);

        app(PayrollFinancePostingService::class)->postAccrual($run);

        // The people delivering client work are a cost of that work, not the
        // cost of running an office.
        $this->assertSame(100000.0, $this->debitOn('5200'));
        $this->assertSame(0.0, $this->debitOn('7550'));
    }

    public function test_a_mixed_payroll_splits_between_the_two(): void
    {
        $run = PayrollRun::create(['payroll_month' => '2026-08', 'status' => 'locked']);
        $this->payslipFor($run, $this->employeeIn('Production', 'direct'), 100000, 70000);
        $this->payslipFor($run, $this->employeeIn('Finance', 'indirect'), 60000, 45000);

        app(PayrollFinancePostingService::class)->postAccrual($run);

        $this->assertSame(100000.0, $this->debitOn('5200'));
        $this->assertSame(60000.0, $this->debitOn('7550'));
    }

    public function test_the_employer_statutory_cost_is_now_recorded_as_a_cost_and_a_debt(): void
    {
        $employer = 4500.00;
        $run = $this->runFor(
            $this->employeeIn('Production', 'direct'),
            100000, 70000,
            ['paye' => 15000, 'employer_total' => $employer],
        );

        app(PayrollFinancePostingService::class)->postAccrual($run);

        // WNG has incurred a cost on top of gross pay...
        $this->assertSame(100000.0 + $employer, $this->debitOn('5200'));

        // ...and owes it to the Authority. The liability carries the employee
        // deductions too, so assert that the employer share is included rather
        // than that it is the whole balance.
        $this->assertGreaterThanOrEqual($employer, $this->creditOn('2140'));
    }

    public function test_employer_cost_follows_the_salary_that_caused_it(): void
    {
        $run = PayrollRun::create(['payroll_month' => '2026-08', 'status' => 'locked']);
        $this->payslipFor($run, $this->employeeIn('Production', 'direct'), 100000, 70000, ['paye' => 15000, 'employer_total' => 6000]);
        $this->payslipFor($run, $this->employeeIn('Finance', 'indirect'), 100000, 70000, ['paye' => 15000, 'employer_total' => 6000]);

        app(PayrollFinancePostingService::class)->postAccrual($run);

        // Equal gross on both sides, so the 12,000 of employer cost divides
        // evenly and lands beside the pay that caused it.
        $this->assertSame(106000.0, $this->debitOn('5200'));
        $this->assertSame(106000.0, $this->debitOn('7550'));
    }

    public function test_the_accrual_still_balances_with_the_employer_legs_added(): void
    {
        $run = PayrollRun::create(['payroll_month' => '2026-08', 'status' => 'locked']);
        $this->payslipFor($run, $this->employeeIn('Production', 'direct'), 100000, 70000, ['paye' => 15000, 'employer_total' => 4500]);
        $this->payslipFor($run, $this->employeeIn('Finance', null), 60000, 45000, ['paye' => 9000, 'employer_total' => 2700]);

        $entry = app(PayrollFinancePostingService::class)->postAccrual($run);
        $entry->load('lines');

        $debit = $entry->lines->where('entry_type', 'debit')->sum(fn ($l) => (float) $l->amount);
        $credit = $entry->lines->where('entry_type', 'credit')->sum(fn ($l) => (float) $l->amount);

        $this->assertSame($debit, $credit);
        $this->assertTrue($entry->isBalanced());

        // Gross 160,000 plus 7,200 of employer cost.
        $this->assertSame(167200.0, $debit);
    }

    public function test_payslips_that_do_not_reconcile_are_still_refused(): void
    {
        // The check that survived the rewrite: a header that balances while
        // disagreeing with the payslips underneath it is a payroll problem.
        $run = $this->runFor($this->employeeIn('Production', 'direct'), 100000, 110000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('do not reconcile');

        app(PayrollFinancePostingService::class)->postAccrual($run);
    }

    public function test_the_classification_screen_lists_departments_with_their_headcount(): void
    {
        // Without this endpoint the split could never be switched on: the column
        // existed, the posting logic existed, and nothing could set the value.
        $this->employeeIn('Production', 'direct');
        $this->employeeIn('Finance', null);

        $user = User::create([
            'name' => 'Finance Config', 'email' => uniqid('cfg_') . '@test.local',
            'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $user->givePermissionTo(Permission::findOrCreate(Permissions::FINANCE_EXPENSE_CODES_MANAGE, 'web'));

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/finance/labour-classification')->assertOk();

        $this->assertSame(1, $response->json('summary.direct'));

        // Headcount is what makes the decision concrete — "Production, 1 person"
        // is answerable where a bare department name is not.
        $production = collect($response->json('data'))->firstWhere('labour_classification', 'direct');
        $this->assertSame(1, $production['active_employees']);

        // An unclassified department reports as overhead, which is what payroll
        // actually does with it.
        $unset = collect($response->json('data'))->firstWhere('is_explicit', false);
        $this->assertSame('indirect', $unset['labour_classification']);
    }

    public function test_classifying_a_department_changes_where_its_payroll_posts(): void
    {
        $employee = $this->employeeIn('Production', null);

        $user = User::create([
            'name' => 'Finance Config', 'email' => uniqid('cfg_') . '@test.local',
            'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $user->givePermissionTo(Permission::findOrCreate(Permissions::FINANCE_EXPENSE_CODES_MANAGE, 'web'));

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/finance/labour-classification/{$employee->department_id}", [
                'labour_classification' => 'direct',
            ])->assertOk();

        // The whole point of the setting: the next payroll posts differently.
        $run = $this->runFor($employee, 100000, 70000);
        app(PayrollFinancePostingService::class)->postAccrual($run);

        $this->assertSame(100000.0, $this->debitOn('5200'));
        $this->assertSame(0.0, $this->debitOn('7550'));
    }

    public function test_changing_the_classification_needs_the_finance_permission(): void
    {
        $employee = $this->employeeIn('Production', null);

        $outsider = User::create([
            'name' => 'Outsider', 'email' => uniqid('out_') . '@test.local',
            'password' => bcrypt('secret'), 'is_active' => true,
        ]);

        $this->actingAs($outsider, 'sanctum')
            ->patchJson("/api/finance/labour-classification/{$employee->department_id}", [
                'labour_classification' => 'direct',
            ])->assertForbidden();
    }

    // ---------------------------------------------------------------- helpers

    private function debitOn(string $code): float
    {
        return (float) JournalLine::where('account_id', ChartOfAccount::where('code', $code)->value('id'))
            ->where('entry_type', 'debit')->sum('amount');
    }

    private function creditOn(string $code): float
    {
        return (float) JournalLine::where('account_id', ChartOfAccount::where('code', $code)->value('id'))
            ->where('entry_type', 'credit')->sum('amount');
    }

    private function employeeIn(string $department, ?string $classification): Employee
    {
        $dept = Department::create([
            'name' => $department . ' ' . uniqid(),
            'labour_classification' => $classification,
        ]);

        return Employee::create([
            'employee_id' => 'PAY-' . uniqid(),
            'first_name' => 'Payroll', 'last_name' => 'Employee',
            'department_id' => $dept->id, 'position' => 'Tester',
            'hire_date' => '2026-01-01', 'status' => 'active', 'salary' => 50000,
        ]);
    }

    private function runFor(Employee $employee, int $gross, int $net, ?array $breakdown = null): PayrollRun
    {
        $run = PayrollRun::create(['payroll_month' => '2026-08', 'status' => 'locked']);
        $this->payslipFor($run, $employee, $gross, $net, $breakdown);

        return $run;
    }

    private function payslipFor(PayrollRun $run, Employee $employee, int $gross, int $net, ?array $breakdown = null): void
    {
        Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'payroll_month' => '2026-08', 'basic_salary' => $gross,
            'gross_pay' => $gross, 'net_pay' => $net,
            'tax_breakdown' => $breakdown ?? ['paye' => 15000],
            'ledger_breakdown' => [], 'status' => 'locked',
        ]);
    }

    private function actingAsPayrollManager(): User
    {
        $user = User::create([
            'name' => 'Payroll Split User',
            'email' => uniqid('split_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $user->givePermissionTo(Permission::findOrCreate(Permissions::HR_MANAGE_PAYROLL, 'web'));
        Sanctum::actingAs($user);

        return $user;
    }

    private function financeConfiguration(): void
    {
        foreach ([
            ['1010', 'Bank', 'asset', 'balance_sheet', 'debit'],
            ['2130', 'PAYE Payable', 'liability', 'balance_sheet', 'credit'],
            ['2140', 'Statutory Deductions Payable', 'liability', 'balance_sheet', 'credit'],
            ['2160', 'Net Payroll Payable', 'liability', 'balance_sheet', 'credit'],
            ['5200', 'Cost of Sales – Direct Labour', 'expense', 'direct_cost', 'debit'],
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
    }
}
