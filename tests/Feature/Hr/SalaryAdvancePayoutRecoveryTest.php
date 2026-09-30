<?php

namespace Tests\Feature\Hr;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\SalaryAdvanceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * W3-2 (payout creates a real Payment, no invented GL treatment) and W3-8
 * (operational recovery tracking and the offboarding flag).
 */
class SalaryAdvancePayoutRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;
    private User $finance;
    private PaymentSource $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        Permission::findOrCreate(Permissions::FINANCE_SPEND_VOUCHERS_POST, 'web');
        $this->finance = User::factory()->create(['is_active' => true]);
        $this->finance->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_POST);

        $dept = Department::create(['name' => 'Engineering']);
        $this->employee = Employee::create([
            'employee_id' => 'EMP201', 'first_name' => 'Ada', 'last_name' => 'Wanjiru',
            'department_id' => $dept->id, 'position' => 'Technician', 'hire_date' => '2026-01-01',
            'status' => 'active', 'salary' => 50000.00,
        ]);
        $this->bank = PaymentSource::create([
            'name' => 'Operating Bank', 'code' => 'BANK-SA', 'type' => 'bank',
            'gl_account_id' => ChartOfAccount::where('code', '1010')->value('id'), 'is_active' => true,
        ]);
    }

    private function approvedAdvance(float $amount = 6000.00): SalaryAdvanceRequest
    {
        return SalaryAdvanceRequest::create([
            'employee_id' => $this->employee->id, 'amount' => $amount, 'reason' => 'School fees',
            'target_payroll_month' => now()->format('Y-m'), 'status' => 'approved',
        ]);
    }

    private function disburse(SalaryAdvanceRequest $advance, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->finance)->postJson("/api/hr/advances/{$advance->id}/disburse", [
            'payment_source_id' => $this->bank->id, 'payment_method' => 'bank_transfer',
            'payment_reference' => 'EFT-123', 'payment_date' => now()->toDateString(),
        ]);
    }

    public function test_payout_creates_one_payment_and_posts_no_unconfirmed_journal(): void
    {
        $advance = $this->approvedAdvance();
        $journals = JournalEntry::count();

        $this->disburse($advance)->assertOk();
        $this->disburse($advance)->assertStatus(422);

        $advance->refresh();
        $this->assertSame('disbursed', $advance->status);
        $this->assertSame(1, Payment::where('source_document_type', SalaryAdvanceRequest::class)->where('source_document_id', $advance->id)->count());
        $payment = Payment::findOrFail($advance->payment_id);
        $this->assertSame('6000.00', (string) $payment->amount);
        $this->assertSame('Ada Wanjiru', $payment->payee_name);
        // Approval ≠ Payment ≠ Recovery; and no Staff-Advance treatment is assumed.
        $this->assertSame($journals, JournalEntry::count());
        $this->assertSame('paid', $advance->recovery_status);
    }

    public function test_an_employee_cannot_record_the_payout_of_their_own_advance(): void
    {
        $this->finance->forceFill(['employee_id' => $this->employee->id])->save();

        $this->disburse($this->approvedAdvance())->assertStatus(422);
        $this->assertSame(0, Payment::count());
    }

    public function test_only_an_approved_advance_is_paid_and_only_by_a_payment_poster(): void
    {
        $advance = $this->approvedAdvance();
        $this->disburse($advance, User::factory()->create(['is_active' => true]))->assertForbidden();

        $advance->update(['status' => 'pending']);
        $this->disburse($advance)->assertStatus(422);
    }

    public function test_recovery_is_tracked_against_the_advance_and_never_exceeds_it(): void
    {
        $advance = $this->approvedAdvance(6000.00);
        $this->disburse($advance)->assertOk();
        $url = "/api/hr/advances/{$advance->id}/recoveries";

        $this->actingAs($this->finance)->postJson($url, ['amount' => 2000, 'recovered_on' => now()->toDateString(), 'reference' => 'Payslip Sept'])->assertOk();
        $this->assertSame('recovery_in_progress', $advance->fresh()->recovery_status);
        $this->assertSame('4000.00', $advance->fresh()->outstanding_balance);

        $this->actingAs($this->finance)->postJson($url, ['amount' => 4000.01, 'recovered_on' => now()->toDateString()])->assertStatus(422);

        $this->actingAs($this->finance)->postJson($url, ['amount' => 4000, 'recovered_on' => now()->toDateString()])->assertOk();
        $advance->refresh();
        $this->assertSame('fully_recovered', $advance->recovery_status);
        $this->assertNotNull($advance->fully_recovered_at);
        $this->assertCount(2, $advance->recoveries);
    }

    public function test_an_unpaid_advance_has_nothing_to_recover(): void
    {
        $advance = $this->approvedAdvance();
        $this->actingAs($this->finance)->postJson("/api/hr/advances/{$advance->id}/recoveries", [
            'amount' => 100, 'recovered_on' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_outstanding_advances_are_available_to_offboarding(): void
    {
        $advance = $this->approvedAdvance(6000.00);
        $this->disburse($advance)->assertOk();
        $this->actingAs($this->finance)->postJson("/api/hr/advances/{$advance->id}/recoveries", [
            'amount' => 1000, 'recovered_on' => now()->toDateString(),
        ])->assertOk();

        $outstanding = SalaryAdvanceRequest::outstandingFor($this->employee->id);
        $this->assertCount(1, $outstanding);
        $this->assertSame('5000.00', $outstanding->first()->outstanding_balance);
        $this->assertCount(0, SalaryAdvanceRequest::outstandingFor(null));
    }
}
