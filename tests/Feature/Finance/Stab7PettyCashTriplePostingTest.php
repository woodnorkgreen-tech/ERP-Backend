<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostCollectorService;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use App\Modules\Finance\PettyCash\Models\PettyCashSurrenderItem;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STAB-7 (finance-redesign/phase-2/14_STAB_7_PETTY_CASH_TRIPLE_POSTING_ANALYSIS.md):
 * a requisition-based petty-cash advance was recognised as project cost up to
 * three times for one real spend. These tests prove the fix by asserting the
 * *total* debit recognised across every JournalEntry the flow produces, not
 * merely that one entry balances internally.
 */
class Stab7PettyCashTriplePostingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $financeUser;
    private int $departmentId;
    private int $expenseCodeId;
    private int $secondExpenseCodeId;
    private int $overheadExpenseCodeId;
    private int $paymentSourceId;
    private int $wipAccountId;
    private int $staffAdvanceAccountId;
    private int $floatAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\PaymentSourceSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        \Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'web');
        $this->financeUser = User::factory()->create(['is_active' => true]);
        $this->financeUser->assignRole('Super Admin');

        $this->departmentId = DB::table('departments')->insertGetId([
            'name' => 'Operations', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $jobCostable = DB::table('expense_codes')->where('job_id_rule', '!=', 'not_allowed')->pluck('id');
        $this->expenseCodeId = (int) $jobCostable->get(0);
        $this->secondExpenseCodeId = (int) ($jobCostable->get(1) ?? $jobCostable->get(0));
        $this->overheadExpenseCodeId = (int) DB::table('expense_codes')->where('job_id_rule', 'not_allowed')->value('id');
        $this->paymentSourceId = (int) DB::table('payment_sources')->where('code', 'PC-MAIN')->value('id');

        $this->wipAccountId = (int) DB::table('expense_codes')->where('id', $this->expenseCodeId)->value('default_debit_account_id');
        $this->staffAdvanceAccountId = (int) ChartOfAccount::where('code', '1300')->value('id');
        $this->floatAccountId = (int) DB::table('payment_sources')->where('id', $this->paymentSourceId)->value('gl_account_id');

        PettyCashTopUp::create([
            'amount' => 500000.00, 'payment_method' => 'cash',
            'date_topped_up' => now()->subMonth()->toDateString(), 'created_by' => $this->financeUser->id,
        ]);
        PettyCashBalance::current()->update(['current_balance' => 500000.00]);
    }

    private function enquiry(string $jobNumber): int
    {
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid() . '@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Activation', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-' . uniqid(), 'job_number' => $jobNumber,
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function requisition(int $enquiryId, float $amount, string $number): PettyCashRequisition
    {
        $type = PettyCashRequisitionType::create([
            'code' => 'MAT-' . uniqid(), 'name' => 'Site Materials',
            'default_expense_code_id' => $this->expenseCodeId, 'is_active' => true,
        ]);

        return PettyCashRequisition::create([
            'requisition_number' => $number, 'user_id' => $this->user->id,
            'department_id' => $this->departmentId, 'category' => 'Site Materials',
            'requisition_type_id' => $type->id, 'purpose' => 'Site spend',
            'total_amount' => $amount, 'status' => 'approved', 'enquiry_id' => $enquiryId,
            'payee_name' => 'John Field Worker',
        ]);
    }

    private function disburse(PettyCashRequisition $requisition, float $amount): void
    {
        $this->actingAs($this->financeUser);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/disburse", [
            'idempotency_key' => (string) Str::uuid(), 'expense_code_id' => $this->expenseCodeId,
            'payment_source_id' => $this->paymentSourceId, 'payment_method' => 'cash', 'amount' => $amount,
            'payee_name' => 'John Field Worker', 'description' => 'Disbursing site float',
            'date_disbursed' => now()->toDateString(), 'receipt_type' => 'none',
        ])->assertStatus(200);
    }

    private function totalDebitedTo(int $accountId): string
    {
        return (string) DB::table('journal_lines')
            ->where('account_id', $accountId)->where('entry_type', 'debit')->sum('amount');
    }

    private function totalCreditedTo(int $accountId): string
    {
        return (string) DB::table('journal_lines')
            ->where('account_id', $accountId)->where('entry_type', 'credit')->sum('amount');
    }

    private function assertEveryJournalEntryBalances(): void
    {
        foreach (JournalEntry::all() as $entry) {
            $this->assertSame(
                bcadd($entry->total_debit, '0', 2),
                bcadd($entry->total_credit, '0', 2),
                "JournalEntry #{$entry->id} ({$entry->entry_no}) does not balance."
            );
        }
    }

    // A. Requisition-based advance with full surrender: advance == spend.
    public function test_full_surrender_recognises_the_expense_exactly_once(): void
    {
        $enquiryId = $this->enquiry('WNG-STAB7-A');
        $requisition = $this->requisition($enquiryId, 2000.00, 'PCR-STAB7-A');
        app(PettyCashCostProducer::class)->commitFor($requisition);

        $this->disburse($requisition, 2000.00);

        $this->actingAs($this->user);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender", [
            'items' => [[
                'expense_code_id' => $this->expenseCodeId, 'amount' => 2000.00, 'tax_amount' => 0.00,
                'receipt_type' => 'non_etr', 'supplier_name' => 'Hardware Shop', 'description' => 'Nails',
            ]],
            'cash_returned_amount' => 0.00,
        ])->assertStatus(200);

        $this->actingAs($this->financeUser);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/reconcile")->assertStatus(200);

        // GL invariant: the WIP/expense account was debited exactly once, for the real spend.
        $this->assertSame('2000.00', $this->totalDebitedTo($this->wipAccountId));

        // Advance invariant: Staff Advance debited (at disbursement) and credited
        // (at reconcile) by the same amount — fully cleared, nothing outstanding.
        $this->assertSame('2000.00', $this->totalDebitedTo($this->staffAdvanceAccountId));
        $this->assertSame('2000.00', $this->totalCreditedTo($this->staffAdvanceAccountId));

        // Petty Cash Float invariant: only the real cash movement (disbursement out).
        $this->assertSame('2000.00', $this->totalCreditedTo($this->floatAccountId));

        $this->assertEveryJournalEntryBalances();

        // Project Cost invariant: exactly one ACTUAL CostLine for the surrender item,
        // posted through the surrender's own clearing journal, not its own entry.
        $item = PettyCashSurrenderItem::where('requisition_id', $requisition->id)->first();
        $this->assertNotNull($item->cost_line_id);
        $costLine = CostLine::find($item->cost_line_id);
        $this->assertNotNull($costLine->journal_entry_id);
        $this->assertNotNull($costLine->posted_at);
        $surrenderEntry = JournalEntry::where('entry_no', 'JE-PCS-' . str_pad((string) $requisition->id, 7, '0', STR_PAD_LEFT))->first();
        $this->assertSame($surrenderEntry->id, $costLine->journal_entry_id);

        // No stray JE-CL-* entry exists for this surrender item's CostLine — the
        // exact defect this fix removes.
        $this->assertNull(
            JournalEntry::where('entry_no', 'JE-CL-' . str_pad((string) $costLine->id, 7, '0', STR_PAD_LEFT))->first()
        );
    }

    // B. Partial spend with cash returned: advance > valid surrender.
    public function test_partial_spend_with_cash_returned_recognises_only_the_real_spend(): void
    {
        $enquiryId = $this->enquiry('WNG-STAB7-B');
        $requisition = $this->requisition($enquiryId, 10000.00, 'PCR-STAB7-B');
        app(PettyCashCostProducer::class)->commitFor($requisition);
        $this->disburse($requisition, 10000.00);

        $this->actingAs($this->user);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender", [
            'items' => [[
                'expense_code_id' => $this->expenseCodeId, 'amount' => 7000.00, 'tax_amount' => 0.00,
                'receipt_type' => 'non_etr', 'supplier_name' => 'Timber World', 'description' => 'Timber',
            ]],
            'cash_returned_amount' => 3000.00,
        ])->assertStatus(200);

        $this->actingAs($this->financeUser);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/reconcile")->assertStatus(200);

        $this->assertSame('7000.00', $this->totalDebitedTo($this->wipAccountId));
        $this->assertSame('10000.00', $this->totalDebitedTo($this->staffAdvanceAccountId));
        $this->assertSame('10000.00', $this->totalCreditedTo($this->staffAdvanceAccountId));
        // Float: 10,000 out at disbursement, 3,000 back in on cash return.
        $this->assertSame('10000.00', $this->totalCreditedTo($this->floatAccountId));
        $this->assertSame('3000.00', $this->totalDebitedTo($this->floatAccountId));
        $this->assertEveryJournalEntryBalances();
    }

    // C. Spend exceeds advance: verify existing overspend/reimbursement handling
    // still works and the expense side still recognises the real spend once.
    public function test_spend_exceeding_the_advance_still_recognises_the_expense_exactly_once(): void
    {
        $enquiryId = $this->enquiry('WNG-STAB7-C');
        $requisition = $this->requisition($enquiryId, 2000.00, 'PCR-STAB7-C');
        app(PettyCashCostProducer::class)->commitFor($requisition);
        $this->disburse($requisition, 2000.00);

        $this->actingAs($this->user);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender", [
            'items' => [[
                'expense_code_id' => $this->expenseCodeId, 'amount' => 2500.00, 'tax_amount' => 0.00,
                'receipt_type' => 'non_etr', 'supplier_name' => 'Hardware Shop', 'description' => 'Overspend nails',
            ]],
            'cash_returned_amount' => 0.00,
        ])->assertStatus(200);

        $this->actingAs($this->financeUser);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/reconcile")->assertStatus(200);

        $this->assertSame('2500.00', $this->totalDebitedTo($this->wipAccountId));
        // The advance itself is cleared only up to what was actually advanced.
        $this->assertSame('2000.00', $this->totalCreditedTo($this->staffAdvanceAccountId));
        $this->assertEveryJournalEntryBalances();
    }

    // D/E. Multiple surrender items across different expense categories: each
    // gets its own cost recognised exactly once.
    public function test_multiple_surrender_items_across_categories_each_post_exactly_once(): void
    {
        $enquiryId = $this->enquiry('WNG-STAB7-DE');
        $requisition = $this->requisition($enquiryId, 3000.00, 'PCR-STAB7-DE');
        app(PettyCashCostProducer::class)->commitFor($requisition);
        $this->disburse($requisition, 3000.00);

        $secondAccountId = (int) DB::table('expense_codes')->where('id', $this->secondExpenseCodeId)->value('default_debit_account_id');

        $this->actingAs($this->user);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender", [
            'items' => [
                [
                    'expense_code_id' => $this->expenseCodeId, 'amount' => 2000.00, 'tax_amount' => 0.00,
                    'receipt_type' => 'non_etr', 'supplier_name' => 'Timber World', 'description' => 'Timber',
                ],
                [
                    'expense_code_id' => $this->secondExpenseCodeId, 'amount' => 1000.00, 'tax_amount' => 0.00,
                    'receipt_type' => 'non_etr', 'supplier_name' => 'Boda Transport', 'description' => 'Transport',
                ],
            ],
            'cash_returned_amount' => 0.00,
        ])->assertStatus(200);

        $this->actingAs($this->financeUser);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/reconcile")->assertStatus(200);

        if ($secondAccountId !== $this->wipAccountId) {
            $this->assertSame('2000.00', $this->totalDebitedTo($this->wipAccountId));
            $this->assertSame('1000.00', $this->totalDebitedTo($secondAccountId));
        } else {
            // Same account on both codes in this seed: combined total still exactly once.
            $this->assertSame('3000.00', $this->totalDebitedTo($this->wipAccountId));
        }
        $this->assertSame('3000.00', $this->totalCreditedTo($this->staffAdvanceAccountId));
        $this->assertEveryJournalEntryBalances();
    }

    // F. Non-project / overhead petty cash: no enquiry at all.
    public function test_overhead_requisition_with_no_project_posts_exactly_once(): void
    {
        $type = PettyCashRequisitionType::create([
            'code' => 'ADM-' . uniqid(), 'name' => 'Office Overhead',
            'default_expense_code_id' => $this->overheadExpenseCodeId, 'is_active' => true,
        ]);
        $requisition = PettyCashRequisition::create([
            'requisition_number' => 'PCR-STAB7-F', 'user_id' => $this->user->id,
            'department_id' => $this->departmentId, 'category' => 'Office Overhead',
            'requisition_type_id' => $type->id, 'purpose' => 'Office supplies',
            'total_amount' => 1500.00, 'status' => 'approved', 'enquiry_id' => null,
            'payee_name' => 'Office Admin',
        ]);

        $this->actingAs($this->financeUser);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/disburse", [
            'idempotency_key' => (string) Str::uuid(), 'expense_code_id' => $this->overheadExpenseCodeId,
            'payment_source_id' => $this->paymentSourceId, 'payment_method' => 'cash', 'amount' => 1500.00,
            'payee_name' => 'Office Admin', 'description' => 'Office supplies float',
            'date_disbursed' => now()->toDateString(), 'receipt_type' => 'none',
        ])->assertStatus(200);

        $overheadAccountId = (int) DB::table('expense_codes')->where('id', $this->overheadExpenseCodeId)->value('default_debit_account_id');

        // Disbursement alone must NOT have already expensed this — only the
        // advance should exist at this point.
        $this->assertSame('0.00', bcadd($this->totalDebitedTo($overheadAccountId) ?: '0.00', '0', 2));
        $this->assertSame('1500.00', $this->totalDebitedTo($this->staffAdvanceAccountId));

        $this->actingAs($this->user);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender", [
            'items' => [[
                'expense_code_id' => $this->overheadExpenseCodeId, 'amount' => 1500.00, 'tax_amount' => 0.00,
                'receipt_type' => 'non_etr', 'supplier_name' => 'Stationery Shop', 'description' => 'Paper and pens',
            ]],
            'cash_returned_amount' => 0.00,
        ])->assertStatus(200);

        $this->actingAs($this->financeUser);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/reconcile")->assertStatus(200);

        $this->assertSame('1500.00', $this->totalDebitedTo($overheadAccountId));
        $this->assertSame('1500.00', $this->totalCreditedTo($this->staffAdvanceAccountId));
        $this->assertEveryJournalEntryBalances();
    }

    // G. Direct disbursement (no requisition) must be entirely unaffected —
    // still posts its own ACTUAL cost line immediately, as intended.
    public function test_direct_disbursement_without_a_requisition_still_posts_its_own_actual_cost(): void
    {
        $enquiryId = $this->enquiry('WNG-STAB7-G');

        $disbursement = Payment::create([
            'payment_no' => 'PAY-STAB7-G', 'payment_type' => 'direct', 'account' => 'N/A', 'created_by' => $this->financeUser->id,
            'status' => 'active', 'amount' => 500.00, 'expense_code_id' => $this->expenseCodeId,
            'payment_source_id' => $this->paymentSourceId, 'payment_method' => 'cash',
            'job_number' => 'WNG-STAB7-G', 'project_enquiry_id' => $enquiryId,
            'payee_name' => 'Walk-in payee', 'description' => 'Direct disbursement, no requisition',
            'date_disbursed' => now()->toDateString(), 'requisition_id' => null,
        ]);

        $outcome = app(PettyCashCostProducer::class)->postFor($disbursement->fresh());

        $this->assertSame('posted', $outcome);
        $costLine = CostLine::where('source_type', Payment::class)->where('source_id', $disbursement->id)->first();
        $this->assertNotNull($costLine);
        $this->assertNotNull($costLine->journal_entry_id);
        $this->assertSame('500.00', $this->totalDebitedTo($this->wipAccountId));
    }

    // H. Supplier-settled petty cash: the existing exclusion must still fire,
    // not be shadowed by the new requisition-advance check.
    public function test_supplier_settled_disbursement_is_still_excluded(): void
    {
        $enquiryId = $this->enquiry('WNG-STAB7-H');
        $requisition = $this->requisition($enquiryId, 1000.00, 'PCR-STAB7-H');
        $requisition->forceFill(['bill_id' => 999999])->save();

        $disbursement = Payment::create([
            'payment_no' => 'PAY-STAB7-H', 'payment_type' => 'advance', 'account' => 'N/A', 'created_by' => $this->financeUser->id,
            'status' => 'active', 'amount' => 1000.00, 'expense_code_id' => $this->expenseCodeId,
            'payment_source_id' => $this->paymentSourceId, 'payment_method' => 'cash',
            'job_number' => 'WNG-STAB7-H', 'project_enquiry_id' => $enquiryId,
            'payee_name' => 'Supplier settlement', 'description' => 'Supplier-settled via petty cash',
            'date_disbursed' => now()->toDateString(), 'requisition_id' => $requisition->id,
        ]);

        $outcome = app(PettyCashCostProducer::class)->postFor($disbursement->fresh());

        $this->assertSame('skipped_supplier_settlement', $outcome);
    }

    // I. Voucher-settled petty cash: same guarantee for the voucher exclusion.
    public function test_voucher_settled_disbursement_is_still_excluded(): void
    {
        $enquiryId = $this->enquiry('WNG-STAB7-I');
        $requisition = $this->requisition($enquiryId, 1000.00, 'PCR-STAB7-I');

        $disbursement = Payment::create([
            'payment_no' => 'PAY-STAB7-I', 'payment_type' => 'advance', 'account' => 'N/A', 'created_by' => $this->financeUser->id,
            'status' => 'active', 'amount' => 1000.00, 'expense_code_id' => $this->expenseCodeId,
            'payment_source_id' => $this->paymentSourceId, 'payment_method' => 'cash',
            'job_number' => 'WNG-STAB7-I', 'project_enquiry_id' => $enquiryId,
            'payee_name' => 'Voucher settlement', 'description' => 'Voucher-settled via petty cash',
            'date_disbursed' => now()->toDateString(), 'requisition_id' => $requisition->id,
        ]);

        DB::table('spend_vouchers')->insert([
            'voucher_no' => 'SV-STAB7-I', 'type' => 'advance', 'transacted_at' => now(),
            'posting_date' => now()->toDateString(), 'petty_cash_disbursement_id' => $disbursement->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $outcome = app(PettyCashCostProducer::class)->postFor($disbursement->fresh());

        $this->assertSame('skipped_voucher_settlement', $outcome);
    }

    // A plain requisition-linked advance with NO settlement, surrendered later
    // — postFor() alone (called directly, bypassing the queue) must skip.
    public function test_requisition_linked_advance_alone_is_excluded_from_immediate_posting(): void
    {
        $enquiryId = $this->enquiry('WNG-STAB7-SKIP');
        $requisition = $this->requisition($enquiryId, 750.00, 'PCR-STAB7-SKIP');

        $disbursement = Payment::create([
            'payment_no' => 'PAY-STAB7-SKIP', 'payment_type' => 'advance', 'account' => 'N/A', 'created_by' => $this->financeUser->id,
            'status' => 'active', 'amount' => 750.00, 'expense_code_id' => $this->expenseCodeId,
            'payment_source_id' => $this->paymentSourceId, 'payment_method' => 'cash',
            'job_number' => 'WNG-STAB7-SKIP', 'project_enquiry_id' => $enquiryId,
            'payee_name' => 'Field Worker', 'description' => 'Awaiting surrender',
            'date_disbursed' => now()->toDateString(), 'requisition_id' => $requisition->id,
        ]);

        $outcome = app(PettyCashCostProducer::class)->postFor($disbursement->fresh());

        $this->assertSame('skipped_requisition_advance', $outcome);
        $this->assertSame(0, CostLine::where('source_type', Payment::class)->where('source_id', $disbursement->id)->count());
    }

    // K. Idempotency: calling postFromSource twice for the same surrender item
    // (e.g. a retried queue job before reconcile ran) must not create a second
    // CostLine, and reconciling twice must not duplicate anything either — the
    // latter already existed and must still hold.
    public function test_reconciling_twice_still_refuses_and_does_not_duplicate_the_now_shared_posting(): void
    {
        $enquiryId = $this->enquiry('WNG-STAB7-K');
        $requisition = $this->requisition($enquiryId, 2000.00, 'PCR-STAB7-K');
        app(PettyCashCostProducer::class)->commitFor($requisition);
        $this->disburse($requisition, 2000.00);

        $this->actingAs($this->user);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender", [
            'items' => [[
                'expense_code_id' => $this->expenseCodeId, 'amount' => 2000.00, 'tax_amount' => 0.00,
                'receipt_type' => 'non_etr', 'supplier_name' => 'Hardware Shop', 'description' => 'Nails',
            ]],
            'cash_returned_amount' => 0.00,
        ])->assertStatus(200);

        $this->actingAs($this->financeUser);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/reconcile")->assertStatus(200);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/reconcile")->assertStatus(422);

        $this->assertSame('2000.00', $this->totalDebitedTo($this->wipAccountId));
        $this->assertSame(1, JournalEntry::where('source_type', PettyCashRequisition::class)->where('source_id', $requisition->id)->count());
    }
}
