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

    /**
     * config() mutates the application config array directly, which
     * RefreshDatabase does not touch and PHPUnit does not reset between
     * tests sharing one process — a map entry set in one test would
     * otherwise leak into every test that runs after it in the same run.
     */
    protected function tearDown(): void
    {
        config(['finance_accounts.map' => []]);
        parent::tearDown();
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

    /**
     * Critical Risk C1 (finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md):
     * every literal reference-chart code payroll posts against must be
     * translated through ChartAccountMap before it is looked up, so an
     * installation whose real chart uses different codes for these accounts
     * still resolves correctly instead of throwing at posting time.
     */
    public function test_payroll_accrual_resolves_accounts_through_the_chart_account_map(): void
    {
        $this->actingAsPayrollManager();
        $this->financeConfiguration();
        $employee = $this->employee();

        // Simulate an installation whose real chart uses a mnemonic code for
        // Salaries & Wages, distinct from the reference chart's 7550. Both
        // rows exist: 7550 stays present but unused, proving resolution truly
        // followed the map rather than happening to find 7550 anyway.
        $mappedSalaries = \App\Modules\Finance\Models\ChartOfAccount::create([
            'code' => 'SAL-001', 'name' => 'Salaries & Wages (local chart)',
            'category' => 'expense', 'account_type' => 'opex',
            'normal_balance' => 'debit', 'is_postable' => true, 'is_active' => true,
        ]);
        config(['finance_accounts.map.7550' => 'SAL-001']);

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

        $accrual = app(PayrollFinancePostingService::class)->postAccrual($run);

        $this->assertTrue($accrual->isBalanced());
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $accrual->id,
            'account_id' => $mappedSalaries->id,
            'entry_type' => 'debit',
        ]);
        $this->assertDatabaseMissing('journal_lines', [
            'journal_entry_id' => $accrual->id,
            'account_id' => ChartOfAccount::where('code', '7550')->value('id'),
        ]);
    }

    /**
     * The companion case: an installation that has NOT mapped 7550 (the
     * default in development and in every other test in this file) must
     * resolve exactly as it always has. This is what makes the fix additive
     * rather than a behaviour change for every existing deployment.
     */
    public function test_payroll_accrual_still_resolves_to_the_reference_code_when_unmapped(): void
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

        $accrual = app(PayrollFinancePostingService::class)->postAccrual($run);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $accrual->id,
            'account_id' => ChartOfAccount::where('code', '7550')->value('id'),
            'entry_type' => 'debit',
        ]);
    }

    /**
     * Critical Risk C7 (finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md):
     * payroll's cash-out used to create only a JournalEntry and reference
     * columns on the run itself — no Payment row, so payroll was invisible
     * to every Payment-based control (fund custody, reversal, bank
     * reconciliation). This pins that a real, correctly-linked Payment now
     * exists, through the same settlement engine every other payment uses.
     */
    public function test_marking_a_run_paid_creates_a_real_payment_record(): void
    {
        $this->actingAsPayrollManager();
        $this->financeConfiguration();
        $run = $this->runWithPayslip(80000);
        $source = PaymentSource::firstOrFail();
        $posting = app(PayrollFinancePostingService::class);
        $posting->postAccrual($run);

        $posting->postPayment($run->fresh(), $source, '2026-08-31', 'BANK-2026-08');

        $run->refresh();
        $this->assertNotNull($run->payment_id);

        $payment = \App\Modules\Finance\Models\Payment::find($run->payment_id);
        $this->assertNotNull($payment, 'markPaid() must create an independent Payment row, not just a journal entry.');
        $this->assertSame('80000.00', (string) $payment->amount);
        $this->assertSame($source->id, $payment->payment_source_id);
        $this->assertSame('active', $payment->status);
        $this->assertSame(\App\Modules\HR\Models\PayrollRun::class, $payment->source_document_type);
        $this->assertSame($run->id, $payment->source_document_id);
        $this->assertNotNull($payment->payment_no);

        // Retrying postPayment() for the same run must not mint a second
        // Payment — the funnel's own idempotency (entry_no) already made the
        // journal side safe to retry; the settlement idempotency_key does
        // the same job for the cash side.
        $again = $posting->postPayment($run->fresh(), $source, '2026-08-31', 'DUPLICATE');
        $this->assertSame(1, \App\Modules\Finance\Models\Payment::where('source_document_type', \App\Modules\HR\Models\PayrollRun::class)
            ->where('source_document_id', $run->id)->count());
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
            // Wording moved when payroll stopped writing journals itself and
            // started handing its legs to JournalPostingService (2026-09-08).
            // The rule is unchanged and is now enforced for every document
            // rather than only this one; what this test cares about is that an
            // inactive bank is refused and nothing partial survives, which the
            // two assertions below prove.
            $this->assertStringContainsString('non-postable account', $exception->getMessage());
        }
        $this->assertNull($run->fresh()->payment_journal_entry_id);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_the_preparer_cannot_also_lock_their_own_payroll_run(): void
    {
        $this->financeConfiguration();
        $preparer = $this->actingAsPayrollManager();

        $run = app(PayrollService::class)->initializeRun('2026-08');
        app(PayrollService::class)->processEmployee($this->employee(), $run);
        $run->update(['status' => 'processing']);

        Sanctum::actingAs($preparer);
        $response = $this->postJson("/api/hr/payroll/runs/{$run->id}/lock");

        $response->assertStatus(403);
        $this->assertStringContainsString('cannot also lock it', $response->json('message'));
        $this->assertSame('processing', $run->fresh()->status);
    }

    public function test_a_different_finance_user_can_lock_a_run_someone_else_prepared(): void
    {
        $this->financeConfiguration();
        $this->actingAsPayrollManager();

        $run = app(PayrollService::class)->initializeRun('2026-08');
        app(PayrollService::class)->processEmployee($this->employee(), $run);
        $run->update(['status' => 'processing']);

        $checker = $this->actingAsPayrollManager();
        $response = $this->postJson("/api/hr/payroll/runs/{$run->id}/lock");

        $response->assertOk();
        $run->refresh();
        $this->assertSame('locked', $run->status);
        $this->assertSame($checker->id, $run->locked_by);
    }

    public function test_the_locker_cannot_also_mark_their_own_run_paid(): void
    {
        $this->financeConfiguration();
        $locker = $this->actingAsPayrollManager();
        $run = PayrollRun::create([
            'payroll_month' => '2026-08', 'status' => 'locked', 'locked_by' => $locker->id,
            'total_gross' => 100000, 'total_net' => 80000, 'total_statutory' => 20000,
        ]);
        Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $this->employee()->id,
            'payroll_month' => '2026-08', 'basic_salary' => 100000,
            'gross_pay' => 100000, 'net_pay' => 80000,
            'tax_breakdown' => ['paye' => 15000, 'nssf' => 1000, 'shif' => 2500, 'housing_levy' => 1500],
            'ledger_breakdown' => [], 'status' => 'locked',
        ]);
        $source = PaymentSource::firstOrFail();
        app(PayrollFinancePostingService::class)->postAccrual($run->fresh());

        Sanctum::actingAs($locker);
        $response = $this->postJson("/api/hr/payroll/runs/{$run->id}/mark-paid", [
            'payment_source_id' => $source->id,
            'payment_date' => '2026-08-31',
            'payment_reference' => 'BANK-2026-08',
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('cannot also mark it paid', $response->json('message'));
        $this->assertSame('locked', $run->fresh()->status);
    }

    public function test_a_different_finance_user_can_mark_paid_a_run_someone_else_locked(): void
    {
        $this->financeConfiguration();
        $locker = $this->actingAsPayrollManager();
        $run = PayrollRun::create([
            'payroll_month' => '2026-08', 'status' => 'locked', 'locked_by' => $locker->id,
            'total_gross' => 100000, 'total_net' => 80000, 'total_statutory' => 20000,
        ]);
        Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $this->employee()->id,
            'payroll_month' => '2026-08', 'basic_salary' => 100000,
            'gross_pay' => 100000, 'net_pay' => 80000,
            'tax_breakdown' => ['paye' => 15000, 'nssf' => 1000, 'shif' => 2500, 'housing_levy' => 1500],
            'ledger_breakdown' => [], 'status' => 'locked',
        ]);
        $source = PaymentSource::firstOrFail();
        app(PayrollFinancePostingService::class)->postAccrual($run->fresh());

        $payer = $this->actingAsPayrollManager();
        $response = $this->postJson("/api/hr/payroll/runs/{$run->id}/mark-paid", [
            'payment_source_id' => $source->id,
            'payment_date' => '2026-08-31',
            'payment_reference' => 'BANK-2026-08',
        ]);

        $response->assertOk();
        $run->refresh();
        $this->assertSame('paid', $run->status);
        $this->assertSame($payer->id, $run->paid_by);
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
