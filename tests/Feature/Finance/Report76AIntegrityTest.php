<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Events\PettyCashDisbursementPaid;
use App\Events\PettyCashDisbursementVoided;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\CostCollector\Services\CostAccountService;
use App\Modules\Finance\CostCollector\Services\CostVerificationService;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\CostCollector\Services\StoresCostProducer;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\FinanceEventPosting;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use App\Modules\Finance\Services\FinanceEventPoster;
use App\Modules\Finance\Services\PaymentReversalService;
use App\Modules\Finance\Services\PaymentSettlementService;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Report 76A Part 1 — regression cover for the code-only integrity defects
 * found by Report 76: P0-1, P0-2, P0-8, P0-9, P1-11 and the P0-7 posting
 * reliability change. Every assertion on a journal names the ACCOUNT, because
 * "a journal exists" is what the old tests checked while P0-1 posted to
 * Accounts Payable.
 */
class Report76AIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $verifier;
    private int $topUpId;
    private int $jobCodeId;
    private PaymentSource $float;
    private PaymentSource $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceReferenceSeeder::class);

        foreach ([
            Permissions::FINANCE_PAYMENTS_REVERSE, Permissions::FINANCE_COSTS_CREATE, Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_COSTS_VERIFY, Permissions::FINANCE_REPORTS_VIEW, Permissions::FINANCE_COSTS_PORTFOLIO,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->givePermissionTo([
            Permissions::FINANCE_PAYMENTS_REVERSE, Permissions::FINANCE_COSTS_CREATE, Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_REPORTS_VIEW, Permissions::FINANCE_COSTS_PORTFOLIO,
        ]);
        $this->verifier = User::factory()->create(['is_active' => true]);
        $this->verifier->givePermissionTo([Permissions::FINANCE_COSTS_VERIFY, Permissions::FINANCE_REPORTS_VIEW]);
        $this->actingAs($this->user, 'sanctum');

        $this->jobCodeId = (int) DB::table('expense_codes')->where('job_id_rule', '!=', 'not_allowed')
            ->whereNotNull('default_debit_account_id')->value('id');
        $this->float = PaymentSource::where('code', 'PC-MAIN')->firstOrFail();
        $this->bank = PaymentSource::where('type', 'bank')->where('is_active', true)->whereNotNull('gl_account_id')->firstOrFail();

        $this->topUpId = PettyCashTopUp::create([
            'amount' => 500000.00, 'payment_method' => 'cash',
            'date_topped_up' => now()->subMonth()->toDateString(), 'created_by' => $this->user->id,
        ])->id;
        PettyCashBalance::current()->update(['current_balance' => 500000.00]);
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private function enquiry(string $job): int
    {
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Job '.$job, 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-'.uniqid(), 'job_number' => $job,
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function account(string $function): int
    {
        return (int) ChartOfAccount::where('code', ChartAccountMap::local($function))->value('id');
    }

    /** @return array<int, string> "debit 1200 1000.00" */
    private function legs(?int $journalEntryId): array
    {
        return DB::table('journal_lines as jl')->join('chart_of_accounts as c', 'c.id', '=', 'jl.account_id')
            ->where('jl.journal_entry_id', $journalEntryId)->orderBy('jl.id')
            ->get(['c.code', 'jl.entry_type', 'jl.amount'])
            ->map(fn ($row) => "{$row->entry_type} {$row->code} {$row->amount}")->all();
    }

    /** Net movement on one account across every posted or reversed journal. */
    private function balance(int $accountId): string
    {
        return number_format((float) DB::table('journal_lines')->where('account_id', $accountId)
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE -amount END), 0) AS b")->value('b'), 2, '.', '');
    }

    private function projectActual(int $enquiryId): string
    {
        return number_format((float) CostLine::where('project_enquiry_id', $enquiryId)->counting()
            ->where('nature', CostLine::NATURE_ACTUAL)->sum('net_amount'), 2, '.', '');
    }

    private function floatBalance(): string
    {
        return number_format((float) PettyCashBalance::current()->fresh()->current_balance, 2, '.', '');
    }

    /** A direct petty-cash payment charged to a job: the payment IS the cost. */
    private function directlyCostedPayment(string $job): Payment
    {
        $result = app(PettyCashService::class)->createDisbursement([
            'expense_code_id' => $this->jobCodeId, 'payment_source_id' => $this->float->id, 'top_up_id' => $this->topUpId,
            'amount' => 4500.00, 'payee_name' => 'Bolt', 'account' => 'Cost of Sales:Transport & Delivery',
            'description' => 'Site transport', 'classification' => 'operations', 'payment_method' => 'cash',
            'job_number' => $job, 'date_disbursed' => now()->toDateString(),
        ]);

        return $result['data'];
    }

    /** What a reversal must leave behind, in one comparable shape. */
    private function economicState(Payment $payment, int $enquiryId): array
    {
        $line = CostLine::where('source_type', Payment::class)->where('source_id', $payment->id)->firstOrFail();

        return [
            'payment' => $payment->fresh()->status,
            'cost_line' => $line->status,
            'cost_journal' => JournalEntry::find($line->journal_entry_id)?->status,
            'cost_journal_reversals' => JournalEntry::where('reversal_of_id', $line->journal_entry_id)->count(),
            'project_actual' => $this->projectActual($enquiryId),
            'float_ledger' => $this->balance((int) $this->float->gl_account_id),
            'float_custody' => $this->floatBalance(),
        ];
    }

    private function overheadCode(): ExpenseCode
    {
        return ExpenseCode::create([
            'code' => 'T76A-'.uniqid(), 'accounting_class' => 'Overhead', 'expense_family' => 'Site Operations',
            'expense_type' => 'Utilities', 'simple_meaning' => 'Utilities',
            'job_id_rule' => ExpenseCode::JOB_NOT_ALLOWED, 'cash_flow_class' => 'operating', 'is_active' => true,
            'default_debit_account_id' => ChartOfAccount::where('category', 'expense')->where('is_postable', true)->firstOrFail()->id,
            'minimum_evidence' => [], 'extra_operational_data' => [],
            'requires_supplier' => false, 'requires_asset_record' => false, 'is_capex_review' => false,
        ]);
    }

    private function companyPaidCost(PaymentSource $source, string $amount = '4500.00'): CostLine
    {
        $response = $this->postJson('/api/costs', [
            'expense_code' => $this->overheadCode()->code, 'amount' => $amount,
            'payee_name' => 'Kenya Power', 'description' => 'Electricity bill',
            'funding_mode' => 'company_paid', 'payment_source_id' => $source->id,
        ])->assertCreated();

        return app(CostVerificationService::class)->verify(CostLine::findOrFail($response->json('data.id')), $this->verifier);
    }

    private function invoice(int $enquiryId, string $net, string $vat, ?int $credits = null): int
    {
        $journalId = DB::table('journal_entries')->insertGetId([
            'entry_no' => 'JE-T-'.uniqid(), 'posting_date' => now()->toDateString(),
            'accounting_period_id' => AccountingPeriod::forDate(now())->id,
            'description' => 'test', 'total_debit' => 0, 'total_credit' => 0, 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('project_invoices')->insertGetId([
            'invoice_number' => 'INV-T-'.uniqid(), 'project_enquiry_id' => $enquiryId, 'credits_invoice_id' => $credits,
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => $net, 'tax_amount' => $vat, 'total_amount' => bcadd($net, $vat, 2),
            'status' => 'issued', 'journal_entry_id' => $journalId, 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function actual(int $enquiryId, string $net): void
    {
        CostLine::create([
            'ref' => 'CL-T'.uniqid(), 'project_enquiry_id' => $enquiryId, 'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_VERIFIED, 'amount' => $net, 'tax_amount' => '0.00',
            'net_amount' => $net, 'base_net_amount' => $net,
        ]);
    }

    /* ── P0-1: stock issue reversal ──────────────────────────────────────── */

    public function test_a_stock_issue_reversal_restores_inventory_and_never_touches_accounts_payable(): void
    {
        $enquiryId = $this->enquiry('WNG-76A-001');
        $projectId = DB::table('projects')->insertGetId([
            'enquiry_id' => $enquiryId, 'project_id' => 'WNG-76A-001', 'status' => 'in_progress',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $workstation = DB::table('workstations')->insertGetId(['name' => 'W', 'code' => 'W-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $material = LibraryMaterial::create([
            'workstation_id' => $workstation, 'material_name' => 'Board', 'material_code' => 'M-'.uniqid(),
            'category' => 'Materials', 'unit_of_measure' => 'sheet', 'unit_cost' => 500, 'item_status' => 'Active',
        ]);
        $producer = app(StoresCostProducer::class);
        $inventory = $this->account(FinanceAccountFunctions::INVENTORY);
        $payable = $this->account(FinanceAccountFunctions::ACCOUNTS_PAYABLE);

        $issue = InventoryLog::create([
            'material_id' => $material->id, 'user_id' => $this->user->id, 'type' => 'check_out',
            'batch_number' => 'ISS-'.uniqid(), 'quantity' => -2, 'receipt_unit_cost' => 500, 'balance_after' => 8,
            'project_id' => $projectId, 'reference_no' => 'WNG-76A-001', 'logged_at' => now(),
        ]);
        $cost = $producer->postStockIssue($issue);
        $issueJournal = JournalEntry::with('lines')->findOrFail($cost->journal_entry_id);
        $wip = (int) $issueJournal->lines->firstWhere('entry_type', 'debit')->account_id;

        // Issue: Dr WIP / Cr Inventory.
        $this->assertSame($inventory, (int) $issueJournal->lines->firstWhere('entry_type', 'credit')->account_id);
        $this->assertSame('-1000.00', $this->balance($inventory));

        $reversal = InventoryLog::create([
            'material_id' => $material->id, 'user_id' => $this->user->id, 'type' => 'reversal',
            'batch_number' => 'REV-'.$issue->id, 'quantity' => 2, 'balance_after' => 10, 'project_id' => $projectId,
            'original_issue_log_id' => $issue->id, 'reversal_of_log_id' => $issue->id,
            'reference_no' => 'REV-WNG-76A-001', 'logged_at' => now(),
        ]);
        $credit = $producer->postStockIssueReversal($reversal);
        $again = $producer->postStockIssueReversal($reversal);

        // Reversal: Dr Inventory / Cr the account the issue debited.
        $reversalJournal = JournalEntry::with('lines')->findOrFail($credit->journal_entry_id);
        $this->assertCount(2, $reversalJournal->lines);
        $this->assertSame($inventory, (int) $reversalJournal->lines->firstWhere('entry_type', 'debit')->account_id);
        $this->assertSame($wip, (int) $reversalJournal->lines->firstWhere('entry_type', 'credit')->account_id);
        $this->assertSame('1000.00', (string) $reversalJournal->lines->firstWhere('entry_type', 'debit')->amount);

        // Net effect: inventory whole, WIP clear, Accounts Payable never moved.
        $this->assertSame('0.00', $this->balance($inventory));
        $this->assertSame('0.00', $this->balance($wip));
        $this->assertSame('0.00', $this->balance($payable));
        $this->assertSame(0, DB::table('journal_lines')->where('account_id', $payable)->count());

        // Exactly once, however often it is asked for.
        $this->assertSame($credit->id, $again->id);
        $this->assertSame(1, CostLine::where('source_id', $reversal->id)->where('source_ref', 'stock-issue-reversal')->count());
        $this->assertSame(1, JournalEntry::where('cost_line_id', $credit->id)->count());
        $this->assertSame('0.00', $this->projectActual($enquiryId));
        $this->assertSame($cost->id, (int) $credit->reversal_of_id);
        $this->assertSame(CostLine::STATUS_VERIFIED, $cost->fresh()->status, 'the original movement and its cost stay on record');
    }

    public function test_every_stores_movement_reference_settles_against_inventory(): void
    {
        foreach (['stock-issue', 'stock-return', 'stock-issue-reversal'] as $ref) {
            $this->assertContains($ref, \App\Modules\Finance\Services\JournalPostingService::STOCK_MOVEMENT_REFS);
        }
    }

    /* ── P0-2: one payment reversal ──────────────────────────────────────── */

    public function test_both_reversal_entry_points_leave_a_directly_costed_payment_in_the_same_state(): void
    {
        $enquiryA = $this->enquiry('WNG-01-2026-761');
        $enquiryB = $this->enquiry('WNG-01-2026-762');
        $viaFinance = $this->directlyCostedPayment('WNG-01-2026-761');
        $viaPettyCash = $this->directlyCostedPayment('WNG-01-2026-762');

        $this->assertSame('4500.00', $this->projectActual($enquiryA));
        $this->assertSame('4500.00', $this->projectActual($enquiryB));
        $this->assertSame('491000.00', $this->floatBalance());

        // Entry point 1: the Finance "Reverse payment" action.
        $this->postJson("/api/finance/payments/{$viaFinance->id}/reverse", ['reason' => 'Paid in error — Finance screen'])->assertOk();
        $afterFinance = $this->economicState($viaFinance, $enquiryA);

        // Entry point 2: the petty-cash void.
        $this->assertTrue(app(PettyCashService::class)->voidDisbursement($viaPettyCash->fresh(), 'Paid in error — petty cash'));
        $afterPettyCash = $this->economicState($viaPettyCash, $enquiryB);

        $expected = [
            'payment' => 'voided', 'cost_line' => CostLine::STATUS_REVERSED, 'cost_journal' => 'reversed',
            'cost_journal_reversals' => 1, 'project_actual' => '0.00',
        ];
        $this->assertSame($expected, array_intersect_key($afterFinance, $expected));
        $this->assertSame($expected, array_intersect_key($afterPettyCash, $expected));

        // Money: ledger float and custody float both whole again.
        $this->assertSame('0.00', $this->balance((int) $this->float->gl_account_id));
        $this->assertSame('500000.00', $this->floatBalance());

        // History is kept: the original cost line and journal are still there.
        $this->assertSame(2, CostLine::where('source_type', Payment::class)->count());
        $this->assertNotNull($viaFinance->fresh()->voided_by);
        $this->assertSame('Paid in error — Finance screen', $viaFinance->fresh()->void_reason);
    }

    public function test_a_payment_reversal_is_idempotent(): void
    {
        $enquiryId = $this->enquiry('WNG-01-2026-763');
        $payment = $this->directlyCostedPayment('WNG-01-2026-763');

        $this->postJson("/api/finance/payments/{$payment->id}/reverse", ['reason' => 'First reversal'])->assertOk();
        $state = $this->economicState($payment, $enquiryId);
        $journals = JournalEntry::count();

        $this->postJson("/api/finance/payments/{$payment->id}/reverse", ['reason' => 'Second reversal'])->assertStatus(422);
        // The listener behind a replayed void finds nothing left to do.
        event(new PettyCashDisbursementVoided($payment->id, $this->user->id, 'Replayed'));

        $this->assertSame($state, $this->economicState($payment, $enquiryId));
        $this->assertSame($journals, JournalEntry::count());
    }

    public function test_reversing_a_payment_that_created_no_project_cost_invents_none(): void
    {
        $enquiryId = $this->enquiry('WNG-01-2026-764');
        $this->actual($enquiryId, '60000.00');

        // A settlement with no cost link at all — the shape of a supplier
        // payment, a requisition advance or a voucher payment.
        $payment = app(PaymentSettlementService::class)->settle([
            'payment_no' => 'PAY-T-'.uniqid(), 'payment_type' => 'direct', 'payment_source_id' => $this->bank->id,
            'payee_name' => 'Supplier Ltd', 'account' => 'Supplier invoice payment', 'amount' => '9800.00',
            'description' => 'Payment for invoice', 'date_disbursed' => now()->toDateString(),
            'classification' => 'operations', 'project_enquiry_id' => $enquiryId, 'job_number' => 'WNG-01-2026-764',
            'tax' => 'no_etr', 'receipt_type' => 'none', 'created_by' => $this->user->id,
        ]);

        $this->assertCount(0, PaymentReversalService::directCostLines($payment));

        $this->postJson("/api/finance/payments/{$payment->id}/reverse", ['reason' => 'Wrong supplier paid'])->assertOk();

        $this->assertSame('voided', $payment->fresh()->status);
        $this->assertSame('60000.00', $this->projectActual($enquiryId), 'the project cost is the goods, not the payment');
        $this->assertSame(1, CostLine::count());
    }

    public function test_a_closed_period_refuses_the_whole_reversal(): void
    {
        $enquiryId = $this->enquiry('WNG-01-2026-765');
        $payment = $this->directlyCostedPayment('WNG-01-2026-765');
        $before = $this->economicState($payment, $enquiryId);

        AccountingPeriod::forDate(now())->forceFill(['status' => 'closed'])->save();

        $this->postJson("/api/finance/payments/{$payment->id}/reverse", ['reason' => 'After the month closed'])->assertStatus(422);

        // Nothing half-done: payment, cost and float exactly as they were.
        $this->assertSame($before, $this->economicState($payment, $enquiryId));
    }

    /* ── P0-9: company-paid cost capture ─────────────────────────────────── */

    public function test_a_company_paid_cost_from_the_bank_is_one_cost_one_payment_one_expense(): void
    {
        $line = $this->companyPaidCost($this->bank);

        // One Payment, through the settlement engine, linked both ways.
        $payments = Payment::where('source_document_type', CostLine::class)->where('source_document_id', $line->id)->get();
        $this->assertCount(1, $payments);
        $payment = $payments->first();
        $this->assertSame('active', $payment->status);
        $this->assertSame('4500.00', (string) $payment->amount);
        $this->assertSame($this->bank->id, (int) $payment->payment_source_id);
        $this->assertSame($payment->id, (int) $line->fresh()->settled_by_payment_id);
        $this->assertSame('cost-line:'.$line->id, $payment->idempotency_key);
        $this->assertNotEmpty($payment->payment_no);

        // One cost line; the Payment made no second one.
        $this->assertSame(1, CostLine::count());
        $this->assertSame(0, CostLine::where('source_type', Payment::class)->count());

        // One journal: Dr expense / Cr the bank. No journal sourced to the Payment.
        $this->assertSame(1, JournalEntry::count());
        $journal = JournalEntry::with('lines')->findOrFail($line->journal_entry_id);
        $this->assertSame((int) $this->bank->gl_account_id, (int) $journal->lines->firstWhere('entry_type', 'credit')->account_id);
        $this->assertSame('-4500.00', $this->balance((int) $this->bank->gl_account_id));
        $this->assertSame(0, JournalEntry::where('source_type', Payment::class)->count());

        // A bank payment does not touch petty-cash custody, and is there for
        // reconciliation to match: an active payment on the bank's account.
        $this->assertSame('500000.00', $this->floatBalance());
        $this->assertSame(0, DB::table('finance_statement_matches')->where('payment_id', $payment->id)->count());
        $this->assertTrue(Payment::where('payment_source_id', $this->bank->id)->where('status', 'active')->whereKey($payment->id)->exists());

        // Neither the backfill nor a replayed "paid" event may cost it again.
        $this->assertSame('skipped_cost_capture_settlement', app(PettyCashCostProducer::class)->postFor($payment));
        event(new PettyCashDisbursementPaid($payment->id));
        $this->assertSame(1, CostLine::count());
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_a_company_paid_cost_from_petty_cash_moves_custody(): void
    {
        $line = $this->companyPaidCost($this->float, '1200.00');
        $payment = Payment::where('source_document_id', $line->id)->where('source_document_type', CostLine::class)->firstOrFail();

        $this->assertSame('498800.00', $this->floatBalance());
        $this->assertTrue(DB::table('petty_cash_ledger_entries')->where('source_type', 'disbursement')
            ->where('source_id', $payment->id)->where('type', 'debit')->exists());
        $this->assertSame('-1200.00', $this->balance((int) $this->float->gl_account_id));
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_company_paid_is_refused_when_the_float_cannot_cover_it(): void
    {
        PettyCashBalance::current()->update(['current_balance' => 100.00]);

        $response = $this->postJson('/api/costs', [
            'expense_code' => $this->overheadCode()->code, 'amount' => '1200.00', 'payee_name' => 'Kiosk',
            'description' => 'Airtime', 'funding_mode' => 'company_paid', 'payment_source_id' => $this->float->id,
        ])->assertCreated();
        $line = CostLine::findOrFail($response->json('data.id'));

        try {
            app(CostVerificationService::class)->verify($line, $this->verifier);
            $this->fail('A cost the float cannot pay must not be verified as paid from it.');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        // The verification rolled back with the refused payment.
        $this->assertSame(CostLine::STATUS_SUBMITTED, $line->fresh()->status);
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_a_liability_or_unmapped_account_cannot_be_named_as_the_paying_account(): void
    {
        $payable = PaymentSource::where('type', 'payable')->first()
            ?? PaymentSource::create(['code' => 'AP-T', 'name' => 'Supplier Credit', 'type' => 'payable', 'is_active' => true,
                'gl_account_id' => $this->account(FinanceAccountFunctions::ACCOUNTS_PAYABLE), 'currency' => 'KES']);
        $payable->forceFill(['is_active' => true])->save();
        $unmapped = PaymentSource::create(['code' => 'BANK-T', 'name' => 'Unmapped bank', 'type' => 'bank', 'is_active' => true, 'currency' => 'KES']);

        foreach ([$payable, $unmapped] as $source) {
            $this->postJson('/api/costs', [
                'expense_code' => $this->overheadCode()->code, 'amount' => '4500.00', 'payee_name' => 'Kenya Power',
                'description' => 'Electricity', 'funding_mode' => 'company_paid', 'payment_source_id' => $source->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('payment_source_id');
        }
        $this->assertSame(0, CostLine::count());
    }

    public function test_reversing_a_company_paid_cost_or_its_payment_undoes_both_from_either_side(): void
    {
        // From the payment side (Finance "Reverse payment").
        $first = $this->companyPaidCost($this->float, '1200.00');
        $firstPayment = Payment::where('source_document_id', $first->id)->where('source_document_type', CostLine::class)->firstOrFail();
        $this->postJson("/api/finance/payments/{$firstPayment->id}/reverse", ['reason' => 'Recorded twice'])->assertOk();

        // From the cost side (cost reversal).
        $second = $this->companyPaidCost($this->float, '800.00');
        $secondPayment = Payment::where('source_document_id', $second->id)->where('source_document_type', CostLine::class)->firstOrFail();
        app(CostVerificationService::class)->reverse($second->fresh(), $this->verifier, 'Wrong cost recorded');

        foreach ([[$first, $firstPayment], [$second, $secondPayment]] as [$line, $payment]) {
            $this->assertSame(CostLine::STATUS_REVERSED, $line->fresh()->status);
            $this->assertSame('voided', $payment->fresh()->status);
            $this->assertSame('reversed', JournalEntry::find($line->journal_entry_id)->status);
            $this->assertSame(1, JournalEntry::where('reversal_of_id', $line->journal_entry_id)->count());
        }
        $this->assertSame('0.00', $this->balance((int) $this->float->gl_account_id));
        $this->assertSame('500000.00', $this->floatBalance());
    }

    /* ── P0-8: margin basis ──────────────────────────────────────────────── */

    public function test_project_margin_is_measured_on_revenue_net_of_output_vat(): void
    {
        $enquiryId = $this->enquiry('WNG-76A-MARGIN');
        $this->actual($enquiryId, '60000.00');
        $invoiceId = $this->invoice($enquiryId, '100000.00', '16000.00');

        $margin = app(CostAccountService::class)->forEnquiry(\App\Models\ProjectEnquiry::findOrFail($enquiryId))['margin'];

        $this->assertSame('100000.00', $margin['billed_revenue']);
        $this->assertSame('116000.00', $margin['billed_gross']);
        $this->assertSame('16000.00', $margin['output_vat']);
        $this->assertSame('60000.00', $margin['cost_of_sales']);
        $this->assertSame('40000.00', $margin['margin'], 'not 56,000: output VAT is not revenue');
        $this->assertSame(40.0, $margin['margin_percent']);
        $this->assertSame('net_of_output_vat', $margin['revenue_basis']);
        $this->assertSame('direct', $margin['margin_type']);
        $this->assertSame('provisional', $margin['margin_status']);
        $this->assertFalse($margin['fully_loaded_available']);

        // A credit note reduces net revenue on the same basis.
        $this->invoice($enquiryId, '-10000.00', '-1600.00', $invoiceId);
        $margin = app(CostAccountService::class)->forEnquiry(\App\Models\ProjectEnquiry::findOrFail($enquiryId))['margin'];

        $this->assertSame('90000.00', $margin['billed_revenue']);
        $this->assertSame('104400.00', $margin['billed_gross']);
        $this->assertSame('30000.00', $margin['margin']);

        // The legal documents are untouched by any of this.
        $this->assertSame('116000.00', (string) DB::table('project_invoices')->where('id', $invoiceId)->value('total_amount'));
    }

    public function test_portfolio_margin_uses_the_same_basis(): void
    {
        $enquiryId = $this->enquiry('WNG-76A-PORT');
        $this->actual($enquiryId, '60000.00');
        $this->invoice($enquiryId, '100000.00', '16000.00');

        $single = app(CostAccountService::class)->forEnquiry(\App\Models\ProjectEnquiry::findOrFail($enquiryId))['margin'];
        $portfolio = app(CostAccountService::class)->portfolioMargin([$enquiryId])[$enquiryId];

        foreach (['billed_revenue', 'billed_gross', 'output_vat', 'cost_of_sales', 'margin', 'margin_percent', 'revenue_basis', 'margin_type', 'margin_status'] as $key) {
            $this->assertSame($single[$key], $portfolio[$key], $key);
        }
        $this->assertSame('40000.00', $portfolio['margin']);
    }

    /* ── P1-11: the void listener ────────────────────────────────────────── */

    /** A void that reached the event without going through the reversal service. */
    private function voidedOutsideTheService(string $job): array
    {
        $enquiryId = $this->enquiry($job);
        $payment = $this->directlyCostedPayment($job);
        $payment->void($this->user->id, 'Voided by a path that skipped the service');

        return [$payment, CostLine::where('source_type', Payment::class)->where('source_id', $payment->id)->firstOrFail(), $enquiryId];
    }

    private function reversalPosting(Payment $payment): FinanceEventPosting
    {
        return FinanceEventPosting::where('posting_type', FinanceEventPoster::PAYMENT_COST_REVERSAL)
            ->where('subject_id', $payment->id)->firstOrFail();
    }

    public function test_the_void_listener_reverses_a_standing_cost_when_the_actor_is_known(): void
    {
        [$payment, $line] = $this->voidedOutsideTheService('WNG-01-2026-771');

        event(new PettyCashDisbursementVoided($payment->id, $this->user->id, 'Paid twice'));

        $this->assertSame(CostLine::STATUS_REVERSED, $line->fresh()->status);
        $this->assertSame((int) $this->user->id, (int) $line->fresh()->verified_by);
        $this->assertSame(FinanceEventPosting::STATUS_POSTED, $this->reversalPosting($payment)->status);
    }

    public function test_the_void_listener_with_no_actor_fails_visibly_and_invents_no_user(): void
    {
        [$payment, $line] = $this->voidedOutsideTheService('WNG-01-2026-772');

        // Must not throw into the caller, and must not die on an undefined variable.
        event(new PettyCashDisbursementVoided($payment->id, null, 'Voided by a job with no user'));

        $posting = $this->reversalPosting($payment);
        $this->assertSame(FinanceEventPosting::STATUS_FAILED, $posting->status);
        $this->assertStringContainsString($line->ref, $posting->last_error);
        $this->assertStringContainsString('no identified user', $posting->last_error);
        $this->assertSame(CostLine::STATUS_VERIFIED, $line->fresh()->status, 'reported as still standing, not silently reversed');
        $this->assertNull($line->fresh()->verified_by);

        // A named Finance user retries it; the cost is reversed once.
        $this->actingAs($this->verifier, 'sanctum');
        $posting->forceFill(['payload' => ['voided_by_user_id' => $this->verifier->id, 'reason' => 'Voided by a job with no user']])->save();
        $this->postJson("/api/finance/postings/{$posting->id}/retry")->assertOk();
        $this->assertSame(CostLine::STATUS_REVERSED, $line->fresh()->status);
        $this->assertSame(1, JournalEntry::where('reversal_of_id', $line->journal_entry_id)->count());
    }

    public function test_the_void_listener_is_a_no_op_for_an_already_reversed_or_uncosted_payment(): void
    {
        $this->enquiry('WNG-01-2026-773');
        $costed = $this->directlyCostedPayment('WNG-01-2026-773');
        app(PettyCashService::class)->voidDisbursement($costed, 'Reversed properly');
        $journals = JournalEntry::count();

        // Already reversed by the service: the listener (already run once by the
        // void itself) changes nothing when replayed, with or without an actor.
        event(new PettyCashDisbursementVoided($costed->id, null, 'Replay'));
        $this->assertSame(FinanceEventPosting::STATUS_POSTED, $this->reversalPosting($costed)->status);
        $this->assertSame($journals, JournalEntry::count());

        // No cost line at all.
        $uncosted = Payment::create([
            'top_up_id' => $this->topUpId, 'amount' => 300, 'payee_name' => 'Kiosk', 'account' => 'Airtime',
            'description' => 'Airtime', 'classification' => 'admin', 'payment_method' => 'cash', 'status' => 'voided',
            'date_disbursed' => now()->toDateString(), 'created_by' => $this->user->id,
        ]);
        event(new PettyCashDisbursementVoided($uncosted->id, null, 'Nothing to reverse'));
        $this->assertSame(FinanceEventPosting::STATUS_POSTED, $this->reversalPosting($uncosted)->status);
        $this->assertSame('no project cost to reverse', $this->reversalPosting($uncosted)->outcome);
    }

    public function test_a_cost_that_cannot_be_reversed_is_a_failed_posting_not_a_silent_log_line(): void
    {
        [$payment, $line] = $this->voidedOutsideTheService('WNG-01-2026-774');
        AccountingPeriod::forDate(now())->forceFill(['status' => 'closed'])->save();

        event(new PettyCashDisbursementVoided($payment->id, $this->user->id, 'After close'));

        $posting = $this->reversalPosting($payment);
        $this->assertSame(FinanceEventPosting::STATUS_FAILED, $posting->status);
        $this->assertStringContainsString($line->ref, $posting->last_error);
        $this->assertSame(CostLine::STATUS_VERIFIED, $line->fresh()->status);
    }

    /* ── P0-7: postings do not depend on a queue worker ──────────────────── */

    public function test_a_payment_reaches_the_cost_ledger_with_an_asynchronous_queue_and_no_worker(): void
    {
        // The production shape: an async driver, and nothing draining it.
        config(['queue.default' => 'database']);
        $enquiryId = $this->enquiry('WNG-01-2026-781');

        $payment = $this->directlyCostedPayment('WNG-01-2026-781');

        // The cost exists now, in this request — not "a moment later".
        $this->assertSame('4500.00', $this->projectActual($enquiryId));
        $posting = FinanceEventPosting::where('posting_type', FinanceEventPoster::PAYMENT_COST)->where('subject_id', $payment->id)->firstOrFail();
        $this->assertSame(FinanceEventPosting::STATUS_POSTED, $posting->status);
        $this->assertSame('posted', $posting->outcome);
        $this->assertSame(1, $posting->attempts);

        // And nothing was left on the queue for a worker to find.
        $this->assertSame(0, DB::table('jobs')->where('payload', 'like', '%RecordPettyCashCost%')->count());
    }

    public function test_no_cost_chain_listener_is_queued(): void
    {
        // All eight listeners Report 76 named, and nothing else.
        $this->assertCount(8, FinanceEventPoster::HANDLERS);
        foreach (FinanceEventPoster::HANDLERS as $type => $handler) {
            $this->assertNotInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, app($handler), "{$type} must not wait on a worker");
            $this->assertTrue(method_exists($handler, 'post'), "{$handler} must be retryable through post()");
        }
    }

    public function test_a_failed_posting_is_visible_retryable_and_posts_exactly_once(): void
    {
        $enquiryId = $this->enquiry('WNG-01-2026-782');
        $period = AccountingPeriod::forDate(now());
        $payment = Payment::create([
            'top_up_id' => $this->topUpId, 'amount' => 4500.00, 'payee_name' => 'Bolt', 'account' => 'Transport',
            'description' => 'Site transport', 'classification' => 'operations', 'payment_method' => 'cash', 'status' => 'active',
            'expense_code_id' => $this->jobCodeId, 'payment_source_id' => $this->float->id, 'job_number' => 'WNG-01-2026-782',
            'date_disbursed' => now()->toDateString(), 'created_by' => $this->user->id,
        ]);

        // The books cannot take it: the month is shut.
        $period->forceFill(['status' => 'closed'])->save();
        event(new PettyCashDisbursementPaid($payment->id));   // must not throw

        $posting = FinanceEventPosting::where('posting_type', FinanceEventPoster::PAYMENT_COST)->where('subject_id', $payment->id)->firstOrFail();
        $this->assertSame(FinanceEventPosting::STATUS_FAILED, $posting->status);
        $this->assertNotEmpty($posting->last_error);
        $this->assertSame('0.00', $this->projectActual($enquiryId));
        $this->assertNotNull($payment->fresh()->cost_gl_posting_failed_at);

        // Visible to Finance…
        $this->actingAs($this->verifier, 'sanctum');
        $list = $this->getJson('/api/finance/postings')->assertOk();
        $this->assertSame(1, $list->json('meta.needing_attention'));
        $this->assertSame($posting->id, $list->json('data.0.id'));
        $this->assertTrue($list->json('data.0.can_retry'));

        // …a retry while the cause stands still fails, and says so…
        $this->postJson("/api/finance/postings/{$posting->id}/retry")->assertStatus(422);
        $this->assertSame(0, CostLine::where('source_type', Payment::class)->count());

        // …and once the cause is fixed it posts — once, however often it is retried.
        $period->forceFill(['status' => 'open'])->save();
        $this->postJson("/api/finance/postings/{$posting->id}/retry")->assertOk();
        $this->postJson("/api/finance/postings/{$posting->id}/retry")->assertOk();
        app(FinanceEventPoster::class)->attempt($posting);

        $this->assertSame(FinanceEventPosting::STATUS_POSTED, $posting->fresh()->status);
        $this->assertSame((int) $this->verifier->id, (int) $posting->fresh()->last_retried_by);
        $this->assertSame(1, CostLine::where('source_type', Payment::class)->where('source_id', $payment->id)->count());
        $this->assertSame('4500.00', $this->projectActual($enquiryId));
        $this->assertNull($payment->fresh()->cost_gl_posting_failed_at);
        $this->assertSame(0, $this->getJson('/api/finance/postings')->json('meta.needing_attention'));
    }

    public function test_a_budget_projection_runs_after_the_response_in_the_same_process_not_on_a_queue(): void
    {
        config(['queue.default' => 'database']);
        // As an HTTP request sees it: there is a response to run after.
        $this->app->bind(FinanceEventPoster::class, fn () => new class extends FinanceEventPoster {
            protected function servingAnHttpRequest(): bool
            {
                return true;
            }
        });

        event(new \App\Events\BudgetLinesChanged(424242, $this->user->id));

        $posting = FinanceEventPosting::where('posting_type', FinanceEventPoster::BUDGET_PROJECTION)->where('subject_id', 424242)->firstOrFail();
        $this->assertSame(FinanceEventPosting::STATUS_PENDING, $posting->status, 'owed on record before the response is sent');
        $this->assertSame(0, $posting->attempts);

        // What the framework does once the response has gone.
        app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();

        $posting->refresh();
        $this->assertSame(FinanceEventPosting::STATUS_POSTED, $posting->status);
        $this->assertSame('no budget data to project yet', $posting->outcome);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_retrying_a_posting_needs_the_cost_verification_permission(): void
    {
        $posting = FinanceEventPosting::create([
            'posting_type' => FinanceEventPoster::GRN_ACCRUAL, 'subject_id' => 999999, 'status' => FinanceEventPosting::STATUS_FAILED,
        ]);

        // $this->user may view reports but may not verify costs.
        $this->getJson('/api/finance/postings')->assertOk();
        $this->postJson("/api/finance/postings/{$posting->id}/retry")->assertForbidden();
    }

    public function test_a_posting_that_cannot_run_never_reaches_the_business_caller(): void
    {
        // A goods receipt that does not exist: the handler throws.
        event(new \App\Events\GoodsReceiptRecorded(999999));

        $posting = FinanceEventPosting::where('posting_type', FinanceEventPoster::GRN_ACCRUAL)->where('subject_id', 999999)->firstOrFail();
        $this->assertSame(FinanceEventPosting::STATUS_FAILED, $posting->status);
        $this->assertSame(1, $posting->attempts);
    }
}
