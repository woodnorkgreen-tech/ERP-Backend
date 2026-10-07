<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Critical Risk C5 (finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md):
 * a petty-cash disbursement whose advance journal failed to post used to
 * disappear into a log line, with the disbursement itself still reported as
 * a success. These tests pin the replacement behaviour confirmed in
 * STAB-4 (finance-redesign/phase-2/03_WNG_FINANCE_DECISION_REGISTER.md):
 * the disbursement still proceeds (the cash fact is real and already
 * committed), but the requisition is visibly flagged, Finance is alerted,
 * and a controlled retry exists that cannot double-post.
 */
class PettyCashAdvancePostingTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\VerifiedFinancialRequisitionFixture;

    private User $requester;
    private User $disburser;
    private int $departmentId;
    private int $topUpId;
    private int $expenseCodeId;
    private int $paymentSourceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\PaymentSourceSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder::class);

        $this->requester = User::factory()->create(['is_active' => true]);

        Role::findOrCreate('Super Admin', 'web');
        $this->disburser = User::factory()->create(['is_active' => true]);
        $this->disburser->assignRole('Super Admin');

        $this->departmentId = DB::table('departments')->insertGetId([
            'name' => 'Operations', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expenseCodeId = (int) DB::table('expense_codes')->where('job_id_rule', 'not_allowed')->value('id');
        $this->paymentSourceId = (int) DB::table('payment_sources')->where('code', 'PC-MAIN')->value('id');

        $this->topUpId = PettyCashTopUp::create([
            'amount' => 500000.00, 'payment_method' => 'cash',
            'date_topped_up' => now()->subMonth()->toDateString(),
            'created_by' => $this->disburser->id,
        ])->id;
        PettyCashBalance::current()->update(['current_balance' => 500000.00]);
    }

    private function approvedRequisition(): PettyCashRequisition
    {
        $type = PettyCashRequisitionType::create([
            'code' => 'MAT-'.uniqid(), 'name' => 'Site Materials',
            'default_expense_code_id' => $this->expenseCodeId, 'is_active' => true,
        ]);

        return $this->verifiedRequisitionFixture(PettyCashRequisition::create([
            'requisition_number' => 'PCR-'.uniqid(),
            'user_id' => $this->requester->id,
            'department_id' => $this->departmentId,
            'category' => 'Site Materials',
            'requisition_type_id' => $type->id,
            'purpose' => 'Buying timber and nails for site',
            'total_amount' => 10000.00,
            'status' => 'approved',
            'payee_name' => 'John Field Worker',
        ]));
    }

    private function disburse(PettyCashRequisition $requisition)
    {
        return $this->actingAs($this->disburser)->postJson(
            "/api/finance/petty-cash/requisitions/{$requisition->id}/disburse",
            [
                'idempotency_key' => (string) Str::uuid(),
                'expense_code_id' => $this->expenseCodeId,
                'payment_source_id' => $this->paymentSourceId,
                'payment_method' => 'cash',
                'amount' => 10000.00,
                'payee_name' => 'John Field Worker',
                'description' => 'Disbursing site float',
                'date_disbursed' => now()->toDateString(),
                'receipt_type' => 'none',
            ],
        );
    }

    public function test_a_gl_posting_failure_still_completes_the_disbursement_but_flags_it_and_alerts_finance(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_PETTY_CASH_UPDATE, 'web');
        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->givePermissionTo(Permissions::FINANCE_PETTY_CASH_UPDATE);

        // Simulate an installation whose chart cannot currently post the
        // advance leg — exactly the shape of Critical Risk C1.
        ChartOfAccount::where('code', '1300')->update(['is_postable' => false]);

        $requisition = $this->approvedRequisition();
        $response = $this->disburse($requisition);

        // The cash-side fact is real and must not be rolled back for an
        // unrelated GL misconfiguration.
        $response->assertStatus(200);
        $requisition->refresh();
        $this->assertSame('disbursed', $requisition->status);

        // But it is now visibly, persistently flagged — not just logged.
        $this->assertNull($requisition->advance_journal_entry_id);
        $this->assertNotNull($requisition->advance_gl_posting_failed_at);
        $this->assertStringContainsString('1300', (string) $requisition->advance_gl_posting_error);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $reviewer->id,
            'type' => 'petty_cash_advance_posting_failed',
        ]);
    }

    public function test_retrying_after_the_chart_is_fixed_posts_the_advance_and_clears_the_flag(): void
    {
        ChartOfAccount::where('code', '1300')->update(['is_postable' => false]);
        $requisition = $this->approvedRequisition();
        $this->disburse($requisition)->assertStatus(200);
        $this->assertNotNull($requisition->fresh()->advance_gl_posting_failed_at);

        // Finance corrects the chart, then retries.
        ChartOfAccount::where('code', '1300')->update(['is_postable' => true]);

        $retry = $this->actingAs($this->disburser)
            ->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/retry-advance-posting");

        $retry->assertStatus(200)->assertJsonPath('success', true);

        $requisition->refresh();
        $this->assertNotNull($requisition->advance_journal_entry_id);
        $this->assertNull($requisition->advance_gl_posting_failed_at);
        $this->assertNull($requisition->advance_gl_posting_error);
        $this->assertSame('10000.00', $requisition->advanceJournalEntry->total_debit);
    }

    public function test_retrying_an_already_posted_advance_does_not_duplicate_the_journal_entry(): void
    {
        $requisition = $this->approvedRequisition();
        $this->disburse($requisition)->assertStatus(200);
        $requisition->refresh();
        $this->assertNotNull($requisition->advance_journal_entry_id);
        $originalEntryId = $requisition->advance_journal_entry_id;

        $retry = $this->actingAs($this->disburser)
            ->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/retry-advance-posting");

        $retry->assertStatus(200);
        $this->assertSame($originalEntryId, $requisition->fresh()->advance_journal_entry_id);

        // Specifically the advance entry's own number — not "every journal
        // entry this disbursement is party to", since createDisbursement()
        // separately posts its own direct-payment entry for the same
        // Payment id, which is correct and unrelated to this test.
        $advanceEntryNo = 'JE-PCA-'.str_pad((string) $requisition->disbursement->id, 7, '0', STR_PAD_LEFT);
        $this->assertSame(
            1,
            \App\Modules\Finance\Models\JournalEntry::where('entry_no', $advanceEntryNo)->count(),
        );
    }

    public function test_retry_still_fails_visibly_if_the_underlying_problem_is_not_yet_fixed(): void
    {
        ChartOfAccount::where('code', '1300')->update(['is_postable' => false]);
        $requisition = $this->approvedRequisition();
        $this->disburse($requisition)->assertStatus(200);

        $retry = $this->actingAs($this->disburser)
            ->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/retry-advance-posting");

        $retry->assertStatus(422)->assertJsonPath('success', false);
        $this->assertNotNull($requisition->fresh()->advance_gl_posting_failed_at);
    }
}
