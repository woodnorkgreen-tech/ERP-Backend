<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
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
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 2B Wave 3 — W3 expense controls on the petty-cash rail and the W5
 * petty-cash controls. The accounting assertions measure totals across every
 * journal entry the flow produces, the same way the STAB-7 tests do.
 */
class Wave3PettyCashControlsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $financeUser;
    private int $departmentId;
    private int $expenseCodeId;
    private int $overheadExpenseCodeId;
    private int $paymentSourceId;
    private int $wipAccountId;
    private int $staffAdvanceAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\PaymentSourceSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder::class);

        foreach ([
            Permissions::FINANCE_PETTY_CASH_CREATE, Permissions::FINANCE_PETTY_CASH_UPDATE,
            Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS, Permissions::FINANCE_PETTY_CASH_CUSTODY,
            Permissions::FINANCE_PETTY_CASH_REVIEW_CASH_COUNT, Permissions::FINANCE_PETTY_CASH_ADVANCE_EXCEPTION,
            Permissions::FINANCE_EXPENSE_DUPLICATE_OVERRIDE, Permissions::FINANCE_JOURNALS_REVERSE,
            Permissions::FINANCE_SPEND_VOUCHERS_APPROVE,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = User::factory()->create(['is_active' => true]);
        \Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'web');
        $this->financeUser = User::factory()->create(['is_active' => true]);
        $this->financeUser->assignRole('Super Admin');

        $this->departmentId = DB::table('departments')->insertGetId([
            'name' => 'Operations', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expenseCodeId = (int) DB::table('expense_codes')->where('job_id_rule', '!=', 'not_allowed')->value('id');
        $this->overheadExpenseCodeId = (int) DB::table('expense_codes')->where('job_id_rule', 'not_allowed')->value('id');
        $this->paymentSourceId = (int) DB::table('payment_sources')->where('code', 'PC-MAIN')->value('id');
        $this->wipAccountId = (int) DB::table('expense_codes')->where('id', $this->expenseCodeId)->value('default_debit_account_id');
        $this->staffAdvanceAccountId = (int) ChartOfAccount::where('code', '1300')->value('id');

        PettyCashTopUp::create([
            'amount' => 500000.00, 'payment_method' => 'cash',
            'date_topped_up' => now()->subMonth()->toDateString(), 'created_by' => $this->financeUser->id,
        ]);
        PettyCashBalance::current()->update(['current_balance' => 500000.00]);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function enquiry(string $jobNumber): int
    {
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Activation', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-'.uniqid(), 'job_number' => $jobNumber,
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function requisition(?int $enquiryId, float $amount, string $number, string $status = 'approved', array $extra = []): PettyCashRequisition
    {
        $type = PettyCashRequisitionType::create([
            'code' => 'T-'.uniqid(), 'name' => 'Site Materials '.uniqid(),
            'default_expense_code_id' => $enquiryId ? $this->expenseCodeId : $this->overheadExpenseCodeId, 'is_active' => true,
        ]);

        return PettyCashRequisition::create([
            'requisition_number' => $number, 'user_id' => $this->user->id,
            'department_id' => $this->departmentId, 'category' => 'Site Materials',
            'requisition_type_id' => $type->id, 'purpose' => 'Site spend',
            'total_amount' => $amount, 'status' => $status, 'enquiry_id' => $enquiryId,
            'payee_name' => 'John Field Worker', ...$extra,
        ]);
    }

    private function disbursedAdvance(string $jobNumber, float $amount, string $number): PettyCashRequisition
    {
        $requisition = $this->requisition($this->enquiry($jobNumber), $amount, $number);
        app(PettyCashCostProducer::class)->commitFor($requisition);

        $this->actingAs($this->financeUser)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/disburse", [
            'idempotency_key' => (string) Str::uuid(), 'expense_code_id' => $this->expenseCodeId,
            'payment_source_id' => $this->paymentSourceId, 'payment_method' => 'cash', 'amount' => $amount,
            'payee_name' => 'John Field Worker', 'description' => 'Site float',
            'date_disbursed' => now()->toDateString(), 'receipt_type' => 'none',
        ])->assertOk();

        return $requisition->fresh();
    }

    private function item(float $amount, array $extra = []): array
    {
        return ['expense_code_id' => $this->expenseCodeId, 'amount' => $amount, 'tax_amount' => 0,
            'receipt_type' => 'non_etr', 'supplier_name' => 'Hardware Shop', 'description' => 'Nails', ...$extra];
    }

    private function surrender(PettyCashRequisition $requisition, array $items, float $cashReturned = 0, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender", [
            'items' => $items, 'cash_returned_amount' => $cashReturned,
        ]);
    }

    private function net(int $accountId): string
    {
        $debit = (string) DB::table('journal_lines')->where('account_id', $accountId)->where('entry_type', 'debit')->sum('amount');
        $credit = (string) DB::table('journal_lines')->where('account_id', $accountId)->where('entry_type', 'credit')->sum('amount');

        return bcsub($debit, $credit, 2);
    }

    private function approveSetting(string $key, string $value): void
    {
        DB::table('finance_settings')->updateOrInsert(['key' => $key, 'effective_from' => '2020-01-01'], [
            'value' => $value, 'label' => $key, 'approved_by' => $this->financeUser->id, 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── W3-6 Return for Correction ─────────────────────────────────────────

    public function test_a_returned_surrender_is_corrected_and_then_recognised_once(): void
    {
        $requisition = $this->disbursedAdvance('WNG-W36', 2000.00, 'PCR-W36');
        $this->surrender($requisition, [$this->item(2000.00, ['description' => 'Unclear'])])->assertOk();

        // The requester cannot review their own surrender; a reason is required.
        $this->actingAs($this->user)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender/return", ['reason' => 'Receipt is illegible'])->assertForbidden();
        $this->actingAs($this->financeUser)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender/return", [])->assertStatus(422);

        $this->actingAs($this->financeUser)
            ->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender/return", ['reason' => 'Receipt is illegible — attach the original'])
            ->assertOk()->assertJsonPath('data.status', 'surrender_returned');

        // Returned is not reconcilable and nothing has posted.
        $this->actingAs($this->financeUser)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/reconcile")->assertStatus(422);
        $this->assertSame('0.00', $this->net($this->wipAccountId));

        $this->surrender($requisition, [$this->item(2000.00, ['description' => 'Nails, 2 boxes', 'receipt_number' => 'R-1'])])->assertOk();
        $requisition->refresh();
        $this->assertSame('surrender_pending', $requisition->status);
        $this->assertSame((int) $this->user->id, (int) $requisition->surrender_resubmitted_by);

        $this->actingAs($this->financeUser)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/reconcile")->assertOk();

        $this->assertSame('2000.00', $this->net($this->wipAccountId));
        $this->assertSame('0.00', $this->net($this->staffAdvanceAccountId));
        $history = DB::table('petty_cash_surrender_reviews')->where('petty_cash_requisition_id', $requisition->id)->orderBy('id')->get();
        $this->assertSame(['returned', 'resubmitted'], $history->pluck('action')->all());
        // The original submission is preserved in the return snapshot.
        $this->assertSame('Unclear', json_decode($history[0]->snapshot, true)['items'][0]['description']);
    }

    // ── W3-5 Duplicate detection ───────────────────────────────────────────

    public function test_the_same_receipt_cannot_be_claimed_twice_without_an_authorised_override(): void
    {
        $first = $this->disbursedAdvance('WNG-DUP-A', 2000.00, 'PCR-DUP-A');
        $second = $this->disbursedAdvance('WNG-DUP-B', 2000.00, 'PCR-DUP-B');
        $receipt = ['receipt_number' => 'R-100'];

        $this->surrender($first, [$this->item(2000.00, $receipt)])->assertOk();

        // Same supplier + receipt + amount on another claim: blocked.
        $this->surrender($second, [$this->item(2000.00, $receipt)])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['duplicate_receipt']]);
        // A reason alone is not enough without the authority.
        $this->surrender($second, [$this->item(2000.00, [...$receipt, 'duplicate_override_reason' => 'Supplier reissued the same number'])])
            ->assertStatus(422);
        // A different amount on the same receipt number is not this rule's match.
        $this->surrender($second, [$this->item(1999.00, $receipt)])->assertOk();

        // One receipt cannot be two lines of one claim, whoever submits it.
        $this->surrender($second, [$this->item(1000.00, ['receipt_number' => 'R-200']), $this->item(1000.00, ['receipt_number' => 'R-200'])], 0, $this->financeUser)
            ->assertStatus(422)->assertJsonStructure(['errors' => ['duplicate_receipt']]);

        // Authorised override: recorded with source, reason, actor and time.
        $this->surrender($second, [$this->item(2000.00, [...$receipt, 'duplicate_override_reason' => 'Supplier reissued the same number'])], 0, $this->financeUser)
            ->assertOk();
        $item = PettyCashSurrenderItem::where('requisition_id', $second->id)->firstOrFail();
        $this->assertSame(
            (int) PettyCashSurrenderItem::where('requisition_id', $first->id)->value('id'),
            (int) $item->duplicate_of_surrender_item_id
        );
        $this->assertSame('Supplier reissued the same number', $item->duplicate_override_reason);
        $this->assertSame((int) $this->financeUser->id, (int) $item->duplicate_overridden_by);
        $this->assertNotNull($item->duplicate_overridden_at);
    }

    // ── W3-7 Controlled reversal (with STAB-7 invariants) ──────────────────

    public function test_a_reversed_surrender_is_corrected_and_the_real_spend_recognised_once(): void
    {
        $requisition = $this->disbursedAdvance('WNG-W37', 2000.00, 'PCR-W37');
        $this->assertSame('498000.00', (string) PettyCashBalance::current()->fresh()->current_balance);

        // Posted with the wrong split: 1,800 spent, 200 returned.
        $this->surrender($requisition, [$this->item(1800.00)], 200.00)->assertOk();
        $this->actingAs($this->financeUser)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/reconcile")->assertOk();
        $this->assertSame('1800.00', $this->net($this->wipAccountId));
        $this->assertSame('498200.00', (string) PettyCashBalance::current()->fresh()->current_balance);
        $original = JournalEntry::findOrFail($requisition->fresh()->surrender_journal_entry_id);

        // Only a journal-reversal holder, never the requester, and with a reason.
        $this->actingAs($this->user)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender/reverse", ['reason' => 'Wrong amount'])->assertForbidden();
        $this->actingAs($this->financeUser)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender/reverse", [])->assertStatus(422);

        $this->actingAs($this->financeUser)
            ->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender/reverse", ['reason' => 'Receipt was 1,700 not 1,800'])
            ->assertOk()->assertJsonPath('data.status', 'surrender_returned');

        $requisition->refresh();
        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertTrue(JournalEntry::where('reversal_of_id', $original->id)->exists());
        $this->assertSame(1, (int) $requisition->surrender_posting_generation);
        $this->assertSame('0.00', $this->net($this->wipAccountId), 'Reversal did not take the cost back out.');
        $this->assertSame('498000.00', (string) PettyCashBalance::current()->fresh()->current_balance, 'Returned cash was not taken back out of the float.');
        $retired = PettyCashSurrenderItem::where('requisition_id', $requisition->id)->firstOrFail();
        $this->assertNotNull($retired->superseded_at);
        $this->assertSame(CostLine::STATUS_REVERSED, CostLine::findOrFail($retired->cost_line_id)->status);

        // Never twice.
        $this->actingAs($this->financeUser)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender/reverse", ['reason' => 'Again, by mistake'])->assertStatus(422);

        // The corrected surrender posts a new generation; the same receipt is not its own duplicate.
        $this->surrender($requisition, [$this->item(1700.00)], 300.00)->assertOk();
        $this->actingAs($this->financeUser)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/reconcile")->assertOk();

        $corrected = JournalEntry::findOrFail($requisition->fresh()->surrender_journal_entry_id);
        $this->assertSame('JE-PCS-'.str_pad((string) $requisition->id, 7, '0', STR_PAD_LEFT).'-G2', $corrected->entry_no);
        $this->assertSame('1700.00', $this->net($this->wipAccountId));
        $this->assertSame('0.00', $this->net($this->staffAdvanceAccountId));
        $this->assertSame('498300.00', (string) PettyCashBalance::current()->fresh()->current_balance);
        $this->assertSame('1700.00', number_format((float) CostLine::where('nature', CostLine::NATURE_ACTUAL)
            ->where('status', CostLine::STATUS_VERIFIED)->sum('amount'), 2, '.', ''));
        $this->assertSame(1, (int) DB::table('petty_cash_surrender_reviews')
            ->where('petty_cash_requisition_id', $requisition->id)->where('action', 'reversed')->count());
    }

    // ── W3-1 Walk-in cash purchase ─────────────────────────────────────────

    public function test_a_walk_in_cash_purchase_is_classified_on_its_payment(): void
    {
        $this->user->givePermissionTo(Permissions::FINANCE_PETTY_CASH_CREATE);
        $payload = [
            'idempotency_key' => (string) Str::uuid(), 'payee_name' => 'Corner Hardware',
            'expense_code_id' => $this->overheadExpenseCodeId, 'payment_source_id' => $this->paymentSourceId,
            'payment_method' => 'cash', 'amount' => 750, 'transaction_cost' => 0,
            'description' => 'Padlock for the store', 'direct_payment_reason' => 'Bought at the counter, no requisition raised.',
            'date_disbursed' => now()->toDateString(), 'receipt_type' => 'none', 'tax_amount' => 0,
            'transaction_classification' => 'cash_purchase',
        ];

        $requestId = $this->actingAs($this->user, 'sanctum')->postJson('/api/finance/petty-cash/disbursements', $payload)
            ->assertStatus(202)->json('data.id');
        $this->actingAs($this->financeUser, 'sanctum')
            ->postJson("/api/finance/petty-cash/direct-disbursement-requests/{$requestId}/approve")->assertOk();

        $payment = Payment::query()->latest('id')->firstOrFail();
        $this->assertSame('cash_purchase', $payment->transaction_classification);
        $this->assertSame('admin', $payment->classification, 'The client-segment classification is untouched.');
        $this->actingAs($this->financeUser, 'sanctum')
            ->getJson('/api/finance/petty-cash/disbursements?transaction_classification=cash_purchase')
            ->assertOk()->assertJsonCount(1, 'data');

        // A purchase made under a requisition had a prior requisition, by definition.
        $requisition = $this->requisition(null, 500.00, 'PCR-W31');
        $this->actingAs($this->financeUser, 'sanctum')->postJson('/api/finance/petty-cash/disbursements', [
            ...$payload, 'idempotency_key' => (string) Str::uuid(), 'requisition_id' => $requisition->id,
        ])->assertStatus(422);
    }

    // ── W5-2 Approval signals ──────────────────────────────────────────────

    public function test_the_direct_payment_approver_sees_float_history_advances_and_duplicates(): void
    {
        $this->user->givePermissionTo(Permissions::FINANCE_PETTY_CASH_CREATE);
        $this->requisition(null, 1200.00, 'PCR-SIG', 'disbursed');
        $base = [
            'payee_name' => 'Corner Hardware', 'expense_code_id' => $this->overheadExpenseCodeId,
            'payment_source_id' => $this->paymentSourceId, 'payment_method' => 'cash', 'amount' => 750,
            'transaction_cost' => 0, 'description' => 'Padlock', 'direct_payment_reason' => 'Counter purchase, no requisition raised.',
            'date_disbursed' => now()->toDateString(), 'receipt_type' => 'non_etr', 'receipt_number' => 'CH-77', 'tax_amount' => 0,
        ];
        $first = $this->actingAs($this->user, 'sanctum')->postJson('/api/finance/petty-cash/disbursements', [...$base, 'idempotency_key' => (string) Str::uuid()])
            ->assertStatus(202)->json('data.id');
        $this->actingAs($this->financeUser, 'sanctum')->postJson("/api/finance/petty-cash/direct-disbursement-requests/{$first}/approve")->assertOk();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/finance/petty-cash/disbursements', [...$base, 'idempotency_key' => (string) Str::uuid()])
            ->assertStatus(202);

        $signals = $this->actingAs($this->financeUser, 'sanctum')->getJson('/api/finance/petty-cash/direct-disbursement-requests')
            ->assertOk()->json('data.data.0.approval_signals');

        $this->assertSame((string) PettyCashBalance::current()->fresh()->current_balance, $signals['float_available']);
        $this->assertSame(1, $signals['recent_direct_requests']);
        $this->assertSame('PCR-SIG', $signals['outstanding_advances'][0]['requisition_number']);
        $this->assertSame('payment', $signals['duplicate_receipt_match']['type']);
    }

    // ── W5-9 Outstanding advance guard ─────────────────────────────────────

    public function test_an_overdue_advance_blocks_another_until_an_authorised_exception_is_recorded(): void
    {
        $this->requisition(null, 1000.00, 'PCR-OLD', 'disbursed', ['surrender_due_at' => now()->subDay()->toDateString()]);
        $new = $this->requisition(null, 800.00, 'PCR-NEW', 'pending');

        $approver = User::factory()->create(['is_active' => true]);
        $approver->givePermissionTo(Permissions::FINANCE_PETTY_CASH_UPDATE);

        $this->actingAs($approver)->postJson("/api/finance/petty-cash/requisitions/{$new->id}/approve")
            ->assertStatus(422)->assertJsonPath('code', 'OVERDUE_ADVANCE_EXISTS')->assertJsonPath('can_authorize_exception', false);

        $approver->givePermissionTo(Permissions::FINANCE_PETTY_CASH_ADVANCE_EXCEPTION);
        $this->actingAs($approver)->postJson("/api/finance/petty-cash/requisitions/{$new->id}/approve")
            ->assertStatus(422)->assertJsonPath('can_authorize_exception', true);
        $this->actingAs($approver)->postJson("/api/finance/petty-cash/requisitions/{$new->id}/approve", [
            'outstanding_advance_exception_reason' => 'Site emergency; old advance is being surrendered today',
        ])->assertOk();

        $exception = $new->fresh()->outstanding_advance_exception;
        $this->assertSame('approved', $new->fresh()->status);
        $this->assertSame((int) $approver->id, (int) $exception['authorised_by']);
        $this->assertSame('PCR-OLD', $exception['outstanding_advances'][0]['requisition_number']);
        $this->assertSame('800.00', $exception['new_amount']);
    }

    public function test_an_outstanding_advance_that_is_not_overdue_is_shown_but_does_not_block(): void
    {
        $this->requisition(null, 1000.00, 'PCR-OPEN', 'disbursed', ['surrender_due_at' => now()->addDays(3)->toDateString()]);
        $new = $this->requisition(null, 800.00, 'PCR-NEXT', 'pending');
        $approver = User::factory()->create(['is_active' => true]);
        $approver->givePermissionTo(Permissions::FINANCE_PETTY_CASH_UPDATE);

        $this->actingAs($approver)->postJson("/api/finance/petty-cash/requisitions/{$new->id}/approve")
            ->assertOk()->assertJsonPath('outstanding_advances.0.requisition_number', 'PCR-OPEN');
    }

    public function test_an_advance_that_becomes_overdue_after_approval_is_blocked_again_at_disbursement(): void
    {
        $old = $this->requisition(null, 1000.00, 'PCR-LATER', 'disbursed', [
            'surrender_due_at' => now()->addDay()->toDateString(),
        ]);
        $new = $this->requisition(null, 800.00, 'PCR-PAYOUT', 'pending');

        $this->actingAs($this->financeUser)
            ->postJson("/api/finance/petty-cash/requisitions/{$new->id}/approve")
            ->assertOk();
        $this->assertNull($new->fresh()->outstanding_advance_exception);

        // The control state changes between approval and payout. The payout
        // endpoint must not rely on the earlier check.
        $old->update(['surrender_due_at' => now()->subDay()->toDateString()]);
        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'expense_code_id' => $this->overheadExpenseCodeId,
            'payment_source_id' => $this->paymentSourceId,
            'payment_method' => 'cash',
            'amount' => 800.00,
            'payee_name' => 'John Field Worker',
            'description' => 'Approved site payout',
            'date_disbursed' => now()->toDateString(),
        ];

        $this->actingAs($this->financeUser)
            ->postJson("/api/finance/petty-cash/requisitions/{$new->id}/disburse", $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'OVERDUE_ADVANCE_EXISTS')
            ->assertJsonPath('outstanding_advances.0.requisition_number', 'PCR-LATER');
        $this->assertDatabaseMissing('payments', ['requisition_id' => $new->id]);

        $this->actingAs($this->financeUser)->postJson(
            "/api/finance/petty-cash/requisitions/{$new->id}/disburse",
            [...$payload, 'idempotency_key' => (string) Str::uuid(),
                'outstanding_advance_exception_reason' => 'Urgent site payout approved while surrender is followed up'],
        )->assertOk();

        $exception = $new->fresh()->outstanding_advance_exception;
        $this->assertSame('disbursement', $exception['stage']);
        $this->assertSame('PCR-LATER', $exception['outstanding_advances'][0]['requisition_number']);
    }

    // ── W5-8 Surrender ageing ──────────────────────────────────────────────

    public function test_surrender_due_date_comes_from_approved_policy_and_ages_into_overdue(): void
    {
        $noPolicy = $this->disbursedAdvance('WNG-AGE-0', 500.00, 'PCR-AGE-0');
        $this->assertNull($noPolicy->surrender_due_at, 'No deadline is invented without an approved policy.');

        $this->approveSetting('petty_cash_surrender_due_days', '7');
        $advance = $this->disbursedAdvance('WNG-AGE-1', 500.00, 'PCR-AGE-1');
        $this->assertSame(now()->addDays(7)->toDateString(), $advance->surrender_due_at->toDateString());

        $states = fn () => collect($this->actingAs($this->financeUser)->getJson('/api/finance/petty-cash/advances/outstanding')
            ->assertOk()->json('data.advances'))->pluck('state', 'requisition_number');
        $this->assertSame('awaiting_surrender', $states()['PCR-AGE-1']);

        $advance->update(['surrender_due_at' => now()->subDay()->toDateString()]);
        $this->assertSame('overdue', $states()['PCR-AGE-1']);

        $this->actingAs($this->user)->getJson('/api/finance/petty-cash/advances/outstanding')->assertForbidden();
    }

    // ── W5-3 Custody and W5-7 Cash count ───────────────────────────────────

    public function test_custody_handover_is_confirmed_by_the_incoming_custodian(): void
    {
        $custodian = User::factory()->create(['is_active' => true]);

        $handoverId = $this->actingAs($this->financeUser)->postJson('/api/finance/petty-cash/custody/handovers', [
            'incoming_custodian_user_id' => $custodian->id, 'physical_cash' => 500000,
        ])->assertCreated()->json('data.id');

        $this->assertSame((int) $custodian->id, (int) PettyCashBalance::current()->fresh()->held_by);
        $this->actingAs($this->financeUser)->postJson("/api/finance/petty-cash/custody/handovers/{$handoverId}/confirm")->assertStatus(422);
        $this->actingAs($custodian)->postJson("/api/finance/petty-cash/custody/handovers/{$handoverId}/confirm")->assertOk();
        $this->actingAs($this->financeUser)->getJson('/api/finance/petty-cash/custody/current')
            ->assertOk()->assertJsonPath('data.held_by.id', $custodian->id)
            ->assertJsonPath('data.handovers.0.confirmed_by', $custodian->id);
    }

    public function test_a_cash_count_records_and_explains_a_variance_without_touching_the_ledger(): void
    {
        $custodian = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->financeUser)->postJson('/api/finance/petty-cash/custody/handovers', [
            'incoming_custodian_user_id' => $custodian->id,
        ])->assertCreated();
        $ledgerRows = DB::table('petty_cash_ledger_entries')->count();
        $journals = JournalEntry::count();

        $this->actingAs($this->financeUser)->postJson('/api/finance/petty-cash/cash-counts', ['physical_cash' => 499900])
            ->assertStatus(422)->assertJsonValidationErrors('explanation');

        $count = $this->actingAs($this->financeUser)->postJson('/api/finance/petty-cash/cash-counts', [
            'physical_cash' => 499900, 'explanation' => 'KES 100 note missing; custodian to account',
        ])->assertCreated()->json('data');

        $this->assertSame('-100.00', $count['variance']);
        $this->assertSame('500000.00', $count['system_balance']);
        $this->assertSame((int) $custodian->id, (int) $count['custodian_user_id']);
        $this->assertArrayHasKey('outstanding_advances', $count['components']);
        $this->assertSame($ledgerRows, DB::table('petty_cash_ledger_entries')->count(), 'A count must not move the float.');
        $this->assertSame($journals, JournalEntry::count(), 'A count must not post to the GL.');
        $this->assertSame('500000.00', (string) PettyCashBalance::current()->fresh()->current_balance);

        // A supported adjustment that covers the gap needs no explanation.
        $this->actingAs($this->financeUser)->postJson('/api/finance/petty-cash/cash-counts', [
            'physical_cash' => 499900, 'supported_adjustments' => 100,
        ])->assertCreated();

        // Reviewer independence: not the counter, not the custodian.
        $custodian->givePermissionTo(Permissions::FINANCE_PETTY_CASH_REVIEW_CASH_COUNT);
        $this->actingAs($this->financeUser)->postJson("/api/finance/petty-cash/cash-counts/{$count['id']}/review")->assertStatus(422);
        $this->actingAs($custodian)->postJson("/api/finance/petty-cash/cash-counts/{$count['id']}/review")->assertStatus(422);
        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->givePermissionTo(Permissions::FINANCE_PETTY_CASH_REVIEW_CASH_COUNT);
        $this->actingAs($reviewer)->postJson("/api/finance/petty-cash/cash-counts/{$count['id']}/review")
            ->assertOk()->assertJsonPath('data.reviewed_by', $reviewer->id);
    }

    // ── W5-5 Configurable thresholds ───────────────────────────────────────

    public function test_float_alert_thresholds_follow_an_approved_setting_and_fall_back_safely(): void
    {
        $balance = PettyCashBalance::current();
        $balance->update(['current_balance' => 3000]);
        $this->assertFalse($balance->fresh()->isLow(), 'Legacy default 1,000 applies until Finance approves a value.');

        $this->approveSetting('petty_cash_low_balance_threshold', '5000');
        $this->assertTrue($balance->fresh()->isLow());
        $this->assertFalse($balance->fresh()->isCritical());
    }
}
