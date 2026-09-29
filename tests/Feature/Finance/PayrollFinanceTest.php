<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Database\Seeders\PaymentSourceSeeder;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Models\Payslip;
use App\Modules\HR\Services\Payroll\PayrollFinancePostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Stream F — Payroll Finance (Report 67).
 */
class PayrollFinanceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentSource $bank;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([FinanceDimensionSeeder::class, ChartOfAccountSeeder::class, AccountingPeriodSeeder::class, PaymentSourceSeeder::class] as $seeder) {
            $this->seed($seeder);
        }
        $this->bank = PaymentSource::where('type', 'bank')->where('is_active', true)->whereNotNull('gl_account_id')->firstOrFail();
    }

    protected function month(): string
    {
        return now()->format('Y-m');
    }

    protected function user(array $permissions = [], string $name = 'Finance User'): User
    {
        $user = User::factory()->create(['is_active' => true, 'name' => $name]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    protected function employee(?Department $department = null, ?float $salary = 50000, string $status = 'active'): Employee
    {
        $department ??= Department::create(['name' => 'Dept '.uniqid()]);

        return Employee::create([
            'employee_id' => 'PF-'.uniqid(), 'first_name' => 'Pay', 'last_name' => 'Roll', 'department_id' => $department->id,
            'position' => 'Staff', 'hire_date' => '2025-01-01', 'status' => $status, 'salary' => $salary,
        ]);
    }

    /** A locked run with one payslip: gross 100,000, PAYE 15,000, net 80,000, other deductions 5,000. */
    protected function lockedRun(?Employee $employee = null, ?User $creator = null): PayrollRun
    {
        $run = PayrollRun::create(['payroll_month' => $this->month(), 'status' => 'locked', 'created_by' => $creator?->id,
            'total_gross' => 100000, 'total_net' => 80000]);
        Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => ($employee ?? $this->employee())->id, 'payroll_month' => $this->month(),
            'basic_salary' => 100000, 'gross_pay' => 100000, 'net_pay' => 80000,
            'tax_breakdown' => ['paye' => 15000], 'ledger_breakdown' => [], 'status' => 'locked',
        ]);

        return $run;
    }

    // ── Reversal: refused until a payroll reversal policy exists ───────────

    public function test_a_payroll_payment_cannot_be_reversed_through_the_generic_payment_reversal(): void
    {
        $run = $this->lockedRun();
        $this->actingAs($this->user([], 'Payer'), 'sanctum');
        $posting = app(PayrollFinancePostingService::class);
        $posting->postAccrual($run);
        $posting->postPayment($run->fresh(), $this->bank, now()->toDateString(), 'BANK-PAY-1');
        $run->forceFill(['status' => 'paid'])->save();
        $payment = Payment::where('source_document_type', PayrollRun::class)->where('source_document_id', $run->id)->firstOrFail();
        $journals = JournalEntry::where('source_type', PayrollRun::class)->where('source_id', $run->id)->pluck('status', 'entry_no')->all();

        $reverser = $this->user([Permissions::FINANCE_PAYMENTS_REVERSE]);
        $this->actingAs($reverser, 'sanctum')->postJson("/api/finance/payments/{$payment->id}/reverse", ['reason' => 'Paid to the wrong account'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'payroll'));

        // Nothing moved: the cash record and the ledger still agree.
        $this->assertSame('active', $payment->fresh()->status);
        $this->assertSame($journals, JournalEntry::where('source_type', PayrollRun::class)->where('source_id', $run->id)->pluck('status', 'entry_no')->all());
        $this->assertSame('paid', $run->fresh()->status);
    }
}
