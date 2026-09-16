<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Services\PaymentReversalService;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillPayment;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Supplier;
use App\Modules\ProcurementStores\Services\SupplierPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The supplier rail, from invoice to cash.
 *
 * Goods receipt posted Dr Inventory / Cr Accrued Expenses and the chain stopped
 * there: no workflow ever moved that liability onto a named supplier invoice,
 * and no supplier payment ever credited the account the money left. Accrued
 * Expenses only grew, Accounts Payable was never credited by anything, and
 * there was no creditors ledger to age.
 *
 * These assert the two entries that close it, and the two cases that must post
 * nothing rather than post something plausible.
 */
class SupplierLedgerRailTest extends TestCase
{
    use RefreshDatabase;

    private const ACCRUED = '2150';
    private const PAYABLE = '2100';
    private const BANK = '1010';

    private User $accounts;
    private Supplier $supplier;
    private PurchaseOrder $order;
    private PurchaseOrderItem $orderItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\PaymentSourceSeeder::class);

        Role::findOrCreate('Accounts', 'web');
        $this->accounts = User::create([
            'name' => 'Accounts Clerk',
            'email' => uniqid('accounts_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $this->accounts->assignRole('Accounts');
        Sanctum::actingAs($this->accounts);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Timber & Board Ltd',
            'contact_person' => 'Supplier Contact',
            'phone' => '0700000002',
            'email' => uniqid('supplier_') . '@test.local',
            'address' => 'Industrial Area',
            'payment_terms' => '30 days',
            'status' => 'Active',
            'user_id' => $this->accounts->id,
        ]);

        $this->order = PurchaseOrder::create([
            'po_number' => 'PO-TEST-' . uniqid(),
            'date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store',
            'description' => 'Board stock',
            'total_amount' => 50000,
            'status' => 'approved',
            'user_id' => $this->accounts->id,
            'approved_at' => now(),
            'approved_by' => $this->accounts->id,
        ]);

        $this->orderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $this->order->id,
            'custom_description' => 'MDF 18mm sheet',
            'quantity' => 10,
            'unit_price' => 5000,
            'total' => 50000,
        ]);
    }

    /** A delivery Stores has accepted and confirmed — what makes an invoice matchable. */
    private function deliverAndConfirm(): void
    {
        $note = GoodsReceiptNote::create([
            'grn_number' => 'GRN-TEST-' . uniqid(),
            'date' => now()->toDateString(),
            'purchase_order_id' => $this->order->id,
            'batch_number' => 'BATCH-' . uniqid(),
            'store_location' => 'Karen Village Store',
            'quality_check' => 'pass',
            'store_status' => 'confirmed',
            'received_by' => $this->accounts->id,
        ]);

        GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id,
            'purchase_order_item_id' => $this->orderItem->id,
            'ordered_quantity' => 10,
            'received_quantity' => 10,
            'condition' => 'good',
            'accepted' => true,
            'store_status' => 'confirmed',
            'stock_status' => 'posted',
            'unit_price' => 5000,
        ]);
    }

    private function bill(float $amount = 50000): Bill
    {
        return Bill::create([
            'bill_number' => Bill::generateBillNumber(),
            'purchase_order_id' => $this->order->id,
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => $amount,
            'status' => 'pending',
            'supplier_invoice_number' => 'SINV-' . uniqid(),
            'user_id' => $this->accounts->id,
        ]);
    }

    /** The account a supplier payment leaves. How it is sent is a separate fact. */
    private function payingAccount(): PaymentSource
    {
        return PaymentSource::where('code', 'BANK-MAIN')->firstOrFail();
    }

    /** The signed movement on one account across every posted entry. */
    private function movementOn(string $code): string
    {
        $accountId = ChartOfAccount::where('code', $code)->value('id');

        $debits = (string) JournalLine::where('account_id', $accountId)
            ->where('entry_type', 'debit')->sum('amount');
        $credits = (string) JournalLine::where('account_id', $accountId)
            ->where('entry_type', 'credit')->sum('amount');

        return bcsub($debits ?: '0', $credits ?: '0', 2);
    }

    private function entryFor(Bill $bill): ?JournalEntry
    {
        return JournalEntry::with('lines')
            ->where('entry_no', 'JE-BILL-' . str_pad((string) $bill->id, 7, '0', STR_PAD_LEFT))
            ->first();
    }

    public function test_verifying_a_matched_invoice_moves_the_accrual_onto_accounts_payable(): void
    {
        $this->deliverAndConfirm();
        $bill = $this->bill();

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")
            ->assertOk();

        $entry = $this->entryFor($bill);
        $this->assertNotNull($entry, 'Verification must post a journal entry.');
        $this->assertSame('50000.00', (string) $entry->total_debit);
        $this->assertSame((string) $entry->total_debit, (string) $entry->total_credit);

        // Dr Accrued Expenses — the receipt's liability is discharged.
        $this->assertSame('50000.00', $this->movementOn(self::ACCRUED));
        // Cr Accounts Payable — and reappears as a named creditor.
        $this->assertSame('-50000.00', $this->movementOn(self::PAYABLE));
    }

    /**
     * A GRN accrual that a bill's three-way match clears must stop being a
     * Payment Voucher liability — otherwise it stays "verified, posted,
     * unsettled" forever and can be paid a second time. This happened live:
     * see erp-grn-accrual-double-payment memory for the real figures.
     */
    public function test_verifying_a_bill_closes_the_grn_accrual_it_supersedes(): void
    {
        $this->deliverAndConfirm();

        $period = AccountingPeriod::forDate(now());
        $accruedAccountId = ChartOfAccount::where('code', self::ACCRUED)->value('id');
        $bankAccountId = ChartOfAccount::where('code', self::BANK)->value('id');

        $costLine = CostLine::create([
            'ref' => 'CL-TEST-GRN-' . uniqid(),
            'nature' => CostLine::NATURE_ACCRUED,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => '50000.00',
            'net_amount' => '50000.00',
            'tax_amount' => '0.00',
            'base_net_amount' => '50000.00',
            'fx_rate' => '1.00',
            'accounting_period_id' => $period->id,
            'submitted_by_user_id' => $this->accounts->id,
            'source_type' => GoodsReceiptNoteItem::class,
            'source_id' => 999999,
            'source_ref' => 'accrual',
            'details' => ['purchase_order_item_id' => $this->orderItem->id, 'grn_number' => 'GRN-TEST'],
            'payee_id' => $this->supplier->id,
            'description' => 'Accepted goods: GRN-TEST',
        ]);

        $entry = JournalEntry::create([
            'entry_no' => 'JE-CL-TEST-' . $costLine->id,
            'posting_date' => now()->toDateString(),
            'accounting_period_id' => $period->id,
            'cost_line_id' => $costLine->id,
            'source_type' => CostLine::class,
            'source_id' => $costLine->id,
            'source_ref' => $costLine->ref,
            'description' => 'Test GRN accrual',
            'total_debit' => '50000.00',
            'total_credit' => '50000.00',
            'status' => 'posted',
            'created_by' => $this->accounts->id,
            'posted_at' => now(),
        ]);
        JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => $bankAccountId, 'entry_type' => 'debit', 'amount' => '50000.00', 'base_amount' => '50000.00', 'currency' => 'KES', 'fx_rate' => 1]);
        JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => $accruedAccountId, 'entry_type' => 'credit', 'amount' => '50000.00', 'base_amount' => '50000.00', 'currency' => 'KES', 'fx_rate' => 1]);
        $costLine->forceFill(['journal_entry_id' => $entry->id, 'posted_at' => now()])->save();

        \Spatie\Permission\Models\Permission::findOrCreate(Permissions::FINANCE_SPEND_VOUCHERS_CREATE, 'web');
        $this->accounts->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_CREATE);

        $before = $this->getJson('/api/finance/spend-vouchers/eligible-liabilities')->assertOk();
        $this->assertTrue(collect($before->json('data'))->contains('id', $costLine->id));

        $bill = $this->bill();
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        $this->assertSame($bill->id, $costLine->fresh()->settled_by_bill_id);

        $after = $this->getJson('/api/finance/spend-vouchers/eligible-liabilities')->assertOk();
        $this->assertFalse(collect($after->json('data'))->contains('id', $costLine->id));

        // The deeper gate: even a direct attempt to allocate it is refused.
        $source = $this->payingAccount();
        $this->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment',
            'payee_name' => 'Timber & Board Ltd',
            'total_amount' => 1000,
            'payment_source_id' => $source->id,
            'allocations' => [['cost_line_id' => $costLine->id, 'amount' => 1000]],
        ])->assertStatus(422)->assertJsonPath(
            'message',
            "Cost line {$costLine->ref} was already settled when its bill was verified against the goods receipt. It is no longer this voucher's liability to pay.",
        );
    }

    public function test_verification_posts_once_however_many_times_it_runs(): void
    {
        $this->deliverAndConfirm();
        $bill = $this->bill();

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        $this->assertSame(1, JournalEntry::where('source_type', Bill::class)
            ->where('source_id', $bill->id)->count());
        $this->assertSame('50000.00', $this->movementOn(self::ACCRUED));
    }

    /**
     * A legacy invoice predates the three-way match and was never accrued
     * through the receipt relay. Clearing an accrual it never raised would
     * drive the control account negative by exactly the grandfathered balance.
     */
    public function test_a_legacy_invoice_posts_nothing(): void
    {
        $this->deliverAndConfirm();
        $bill = $this->bill();
        $bill->forceFill([
            'verified_at' => now(),
            'verified_by' => $this->accounts->id,
            'verification_basis' => 'legacy',
        ])->save();

        $this->assertNull(
            app(\App\Modules\Finance\Services\JournalPostingService::class)
                ->postSupplierInvoice($bill->fresh())
        );
        $this->assertSame('0.00', $this->movementOn(self::ACCRUED));
        $this->assertSame('0.00', $this->movementOn(self::PAYABLE));
    }

    /**
     * A credit purchase that never went through Requisition→PO→GRN — no
     * order, no receipt, no three-way match — still belongs on the same
     * supplier rail, taxed and paid the same way, rather than living as a
     * separate Cost Collector `unpaid_invoice` cost line. It debits its own
     * expense classification instead of clearing a receipt accrual that was
     * never raised.
     */
    public function test_a_direct_bill_posts_to_its_own_expense_code_not_the_accrual(): void
    {
        $expenseAccountId = ChartOfAccount::where('code', '5100')->value('id');
        $expenseCode = ExpenseCode::create([
            'code' => 'TEST-DIRECT-001',
            'simple_meaning' => 'Test direct expense',
            'accounting_class' => 'expense',
            'expense_family' => 'operations',
            'expense_type' => 'direct',
            'default_debit_account_id' => $expenseAccountId,
            'job_id_rule' => 'not_allowed',
            'cash_flow_class' => 'operating',
            'requires_asset_record' => false,
            'requires_supplier' => true,
            'is_capex_review' => false,
            'is_active' => true,
            'is_procurable' => true,
            'sort_order' => 1,
        ]);

        $response = $this->postJson('/api/procurement-stores/bills', [
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 11600, // 10,000 net + 16% VAT, non-recoverable by default treatment
            'supplier_invoice_number' => 'SINV-DIRECT-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'expense_code_id' => $expenseCode->id,
        ])->assertStatus(201);
        $billId = $response->json('data.id') ?? $response->json('id');
        $bill = Bill::findOrFail($billId);

        $this->assertNull($bill->purchase_order_id);
        $this->assertTrue($bill->isDirect());

        $verifyResponse = $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();
        $bill->refresh();

        $this->assertSame('direct', $bill->verification_basis);
        $this->assertNotNull($bill->verified_at);

        $entry = $this->entryFor($bill);
        $this->assertNotNull($entry, 'Verifying a direct bill must post a journal entry.');
        $this->assertSame((string) $entry->total_debit, (string) $entry->total_credit);

        // The expense code's own account was debited — 2150 Accrued was never
        // touched, because no receipt ever credited it for this invoice.
        $this->assertSame('0.00', $this->movementOn(self::ACCRUED));
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'account_id' => $expenseAccountId,
            'entry_type' => 'debit',
        ]);

        // Payable is credited for what the supplier is actually owed, exactly
        // as a PO-backed invoice would be.
        $this->assertSame((string) bcmul('-1', (string) $bill->payableAmount(), 2), $this->movementOn(self::PAYABLE));

        // And it settles through the same rail as any other bill.
        $source = $this->payingAccount();
        BillPayment::create([
            'bill_id' => $bill->id,
            'amount_paid' => $bill->payableAmount(),
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'payment_source_id' => $source->id,
            'reference_number' => 'TRX-DIRECT-001',
            'user_id' => $this->accounts->id,
        ]);
        $this->assertSame('0.00', $this->movementOn(self::PAYABLE));
    }

    /** A direct bill still needs a supplier and an expense code before it can be verified. */
    public function test_a_direct_bill_cannot_be_verified_without_an_expense_code(): void
    {
        $bill = Bill::create([
            'bill_number' => Bill::generateBillNumber(),
            'purchase_order_id' => null,
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 5000,
            'status' => 'pending',
            'supplier_invoice_number' => 'SINV-NOCODE-' . uniqid(),
            'user_id' => $this->accounts->id,
        ]);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")
            ->assertStatus(422)
            ->assertJsonPath('blockers.0', 'Expense classification recorded');

        $this->assertNull($bill->fresh()->verified_at);
    }

    /**
     * Staff allowances, stores issues, VAT remitted and the like are real
     * expense-catalogue rows — the cost collector and petty cash both post
     * them — but a bill asks a supplier for something, exactly like a
     * requisition line does, so the same `is_procurable` gate
     * RequisitionApprovalCheck enforces for a purchase order must apply here.
     */
    public function test_a_direct_bill_refuses_a_non_procurable_expense_code(): void
    {
        $nonProcurableCode = ExpenseCode::create([
            'code' => 'TEST-NONPROC-001',
            'simple_meaning' => 'Staff welfare (not a purchase)',
            'accounting_class' => 'expense',
            'expense_family' => 'staff',
            'expense_type' => 'allowance',
            'default_debit_account_id' => ChartOfAccount::where('code', '5100')->value('id'),
            'job_id_rule' => 'not_allowed',
            'cash_flow_class' => 'operating',
            'requires_asset_record' => false,
            'requires_supplier' => false,
            'is_capex_review' => false,
            'is_active' => true,
            'is_procurable' => false,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/procurement-stores/bills', [
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 5000,
            'supplier_invoice_number' => 'SINV-BADCODE-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'expense_code_id' => $nonProcurableCode->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'error.expense_code_id.0',
                'Choose a category for a service or overhead invoice. Staff payments, stores issues and non-purchase categories cannot classify a bill, and a materials purchase belongs on a purchase order, not a direct invoice.',
            );

        $this->assertSame(0, Bill::count());
    }

    /**
     * `is_procurable` alone still lets through every granular fabrication
     * material — 42 of 76 procurable codes at the time this was written. A
     * real materials purchase should go through Requisition→PO→GRN for the
     * budget commitment and three-way match that gives; a direct bill (one
     * line, no receipt to check against) is for the exception a PO doesn't
     * fit, not a shortcut around it.
     */
    public function test_a_direct_bill_refuses_a_direct_materials_expense_code(): void
    {
        $materialsCode = ExpenseCode::create([
            'code' => 'TEST-MATERIALS-001',
            'simple_meaning' => 'Test raw material',
            'accounting_class' => 'expense',
            'expense_family' => 'Direct materials',
            'expense_type' => 'timber',
            'default_debit_account_id' => ChartOfAccount::where('code', '5100')->value('id'),
            'job_id_rule' => 'required',
            'cash_flow_class' => 'operating',
            'requires_asset_record' => false,
            'requires_supplier' => false,
            'is_capex_review' => false,
            'is_active' => true,
            'is_procurable' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/procurement-stores/bills', [
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 5000,
            'supplier_invoice_number' => 'SINV-MATERIALS-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'expense_code_id' => $materialsCode->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'error.expense_code_id.0',
                'Choose a category for a service or overhead invoice. Staff payments, stores issues and non-purchase categories cannot classify a bill, and a materials purchase belongs on a purchase order, not a direct invoice.',
            );

        $this->assertSame(0, Bill::count());
    }

    /**
     * A supplier credit invoice now has two doors — a direct bill, and the
     * Cost Collector's unpaid_invoice capture — and nothing structural ties
     * them together. Both directions of the guard, in one pair of tests.
     */
    public function test_a_direct_bill_refuses_a_supplier_invoice_already_captured_as_a_cost_line(): void
    {
        CostLine::create([
            'ref' => 'CL-DUP-001',
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_SUBMITTED,
            'amount' => '5000.00',
            'tax_amount' => '0.00',
            'net_amount' => '5000.00',
            'base_net_amount' => '5000.00',
            'fx_rate' => '1.00',
            'source_ref' => 'manual',
            'payee_id' => $this->supplier->id,
            'submitted_by_user_id' => $this->accounts->id,
            'details' => ['funding_mode' => 'unpaid_invoice', 'external_ref' => 'DUP-INV-001'],
        ]);

        $expenseCode = ExpenseCode::create([
            'code' => 'TEST-DUP-BILL-001',
            'simple_meaning' => 'Test service',
            'accounting_class' => 'expense',
            'expense_family' => 'operations',
            'expense_type' => 'service',
            'default_debit_account_id' => ChartOfAccount::where('code', '5100')->value('id'),
            'job_id_rule' => 'not_allowed',
            'cash_flow_class' => 'operating',
            'requires_asset_record' => false,
            'requires_supplier' => false,
            'is_capex_review' => false,
            'is_active' => true,
            'is_procurable' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/procurement-stores/bills', [
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 5000,
            'supplier_invoice_number' => 'dup-inv-001', // case-insensitive match
            'supplier_id' => $this->supplier->id,
            'expense_code_id' => $expenseCode->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'error.supplier_invoice_number.0',
                'Invoice dup-inv-001 from this supplier is already recorded as cost line CL-DUP-001. Pay it as a payment voucher rather than recording it again here.',
            );

        $this->assertSame(0, Bill::count());
    }

    public function test_capturing_a_cost_line_refuses_a_supplier_invoice_already_recorded_as_a_bill(): void
    {
        $this->deliverAndConfirm();
        $bill = $this->bill();
        $bill->update(['supplier_invoice_number' => 'DUP-INV-002']);

        \Spatie\Permission\Models\Permission::findOrCreate(\App\Constants\Permissions::FINANCE_COSTS_CREATE, 'web');
        $this->accounts->givePermissionTo(\App\Constants\Permissions::FINANCE_COSTS_CREATE);

        $expenseCode = ExpenseCode::create([
            'code' => 'TEST-DUP-COST-001',
            'simple_meaning' => 'Test service',
            'accounting_class' => 'expense',
            'expense_family' => 'operations',
            'expense_type' => 'service',
            'default_debit_account_id' => ChartOfAccount::where('code', '5100')->value('id'),
            'job_id_rule' => 'not_allowed',
            'cash_flow_class' => 'operating',
            'requires_asset_record' => false,
            'requires_supplier' => false,
            'is_capex_review' => false,
            'is_active' => true,
            'is_procurable' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/costs', [
            'expense_code' => $expenseCode->code,
            'amount' => 5000,
            'funding_mode' => 'unpaid_invoice',
            'payee_type' => 'SUPPLIER',
            'payee_id' => $this->supplier->id,
            'external_ref' => 'dup-inv-002', // case-insensitive match
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.external_ref.0',
                "Invoice dup-inv-002 from this supplier is already recorded as bill {$bill->bill_number}. Pay it from Procurement rather than recording it again here.",
            );

        $this->assertSame(0, CostLine::count());
    }

    public function test_paying_a_posted_invoice_relieves_the_payable_and_credits_the_source(): void
    {
        $this->deliverAndConfirm();
        $bill = $this->bill();
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        $source = $this->payingAccount();
        BillPayment::create([
            'bill_id' => $bill->id,
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'payment_source_id' => $source->id,
            'reference_number' => 'TRX-001',
            'user_id' => $this->accounts->id,
        ]);

        // Raised by the invoice, relieved by the payment: flat once settled.
        $this->assertSame('0.00', $this->movementOn(self::PAYABLE));
        // The bank actually gave up the cash.
        $this->assertSame('-50000.00', $this->movementOn(self::BANK));
        // The receipt accrual stays discharged.
        $this->assertSame('50000.00', $this->movementOn(self::ACCRUED));
    }

    /**
     * Supplier Credit (type payable) IS the invoice's own liability account —
     * offering it as the "paying account" would credit 2100 to relieve a
     * balance already carried on 2100, a wash entry that discharges a real
     * payable with no cash leaving any bank or float.
     */
    public function test_a_supplier_payment_is_refused_from_the_request_boundary_when_funded_by_supplier_credit(): void
    {
        $this->deliverAndConfirm();
        $bill = $this->bill();
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        \Spatie\Permission\Models\Permission::findOrCreate(\App\Constants\Permissions::FINANCE_PETTY_CASH_CREATE, 'web');
        $this->accounts->givePermissionTo(\App\Constants\Permissions::FINANCE_PETTY_CASH_CREATE);
        $apSource = PaymentSource::where('code', 'AP')->firstOrFail();

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'payment_source_id' => $apSource->id,
            'reference_number' => 'TRX-AP-SOURCE',
        ])->assertUnprocessable();

        // Untouched: still exactly what verification posted, not relieved by
        // a payment that never happened.
        $this->assertSame('-50000.00', $this->movementOn(self::PAYABLE));
        $this->assertDatabaseMissing('bill_payments', ['bill_id' => $bill->id]);
    }

    /**
     * The controller validation above is the friendly error; this proves the
     * invariant also holds at the one place every BillPayment creator shares —
     * the single-invoice screen, the batch run, and a petty-cash disbursement
     * against a linked bill all end in JournalPostingService::postSupplierPayment()
     * via BillPayment::boot() — by creating the row directly, the way the batch
     * run and the petty-cash path both do.
     */
    public function test_a_supplier_payment_is_refused_at_the_posting_choke_point_when_funded_by_supplier_credit(): void
    {
        $this->deliverAndConfirm();
        $bill = $this->bill();
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        $apSource = PaymentSource::where('code', 'AP')->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Supplier Credit');

        BillPayment::create([
            'bill_id' => $bill->id,
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'payment_source_id' => $apSource->id,
            'reference_number' => 'TRX-AP-SOURCE-DIRECT',
            'user_id' => $this->accounts->id,
        ]);
    }

    /**
     * The payment leg is conditioned on the invoice leg. Without that, settling
     * a grandfathered legacy bill would debit a payable nothing ever credited.
     */
    public function test_a_payment_against_an_unposted_invoice_posts_nothing(): void
    {
        $this->deliverAndConfirm();
        $bill = $this->bill();
        $bill->forceFill([
            'verified_at' => now(),
            'verified_by' => $this->accounts->id,
            'verification_basis' => 'legacy',
        ])->save();

        $source = $this->payingAccount();
        BillPayment::create([
            'bill_id' => $bill->id,
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'payment_source_id' => $source->id,
            'reference_number' => 'TRX-002',
            'user_id' => $this->accounts->id,
        ]);

        $this->assertSame(0, JournalEntry::where('source_type', BillPayment::class)->count());
        $this->assertSame('0.00', $this->movementOn(self::PAYABLE));
        $this->assertSame('0.00', $this->movementOn(self::BANK));
    }

    public function test_a_part_payment_leaves_the_balance_on_the_payable(): void
    {
        $this->deliverAndConfirm();
        $bill = $this->bill();
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        $source = $this->payingAccount();
        BillPayment::create([
            'bill_id' => $bill->id,
            'amount_paid' => 20000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'payment_source_id' => $source->id,
            'reference_number' => 'TRX-003',
            'user_id' => $this->accounts->id,
        ]);

        // 50,000 credited by the invoice, 20,000 debited by the payment.
        $this->assertSame('-30000.00', $this->movementOn(self::PAYABLE));
        $this->assertSame('-20000.00', $this->movementOn(self::BANK));
    }

    public function test_reversing_a_payment_preserves_evidence_and_reopens_the_invoice(): void
    {
        $this->deliverAndConfirm();
        $bill = $this->bill();
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        $allocation = app(SupplierPaymentService::class)->record($bill->fresh(), [
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'payment_source_id' => $this->payingAccount()->id,
            'reference_number' => 'TRX-REVERSAL',
            'user_id' => $this->accounts->id,
        ]);
        $payment = $allocation->disbursement;

        app(PaymentReversalService::class)->reverse(
            $payment,
            $this->accounts->id,
            'Supplier transfer was recalled',
        );

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'voided',
            'void_reason' => 'Supplier transfer was recalled',
        ]);
        $this->assertDatabaseHas('bill_payments', ['id' => $allocation->id]);
        $this->assertSame('0.00', (string) $bill->fresh()->paid_amount);
        $this->assertSame('50000.00', (string) $bill->fresh()->balance);
        $this->assertSame('-50000.00', $this->movementOn(self::PAYABLE));
        $this->assertSame('0.00', $this->movementOn(self::BANK));
        $this->assertTrue(JournalEntry::query()->where('reversal_of_id', '!=', null)->exists());
    }

    /**
     * A sign-off that could not reach the ledger is not a sign-off.
     *
     * Saving first and posting second would leave a failure looking exactly
     * like the defect this rail closes — verified on the invoice, unknown to
     * Accounts Payable — so the two are one transaction.
     */
    public function test_a_verification_that_cannot_post_does_not_stick(): void
    {
        $this->deliverAndConfirm();
        $bill = $this->bill();

        \App\Modules\Finance\CostCollector\Models\AccountingPeriod::query()
            ->whereDate('starts_on', '<=', $bill->bill_date)
            ->whereDate('ends_on', '>=', $bill->bill_date)
            ->update(['status' => 'closed']);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")
            ->assertStatus(422);

        $this->assertNull($bill->fresh()->verified_at, 'The invoice must not read verified.');
        $this->assertNull($this->entryFor($bill));
        $this->assertSame('0.00', $this->movementOn(self::PAYABLE));
    }

    /** Every entry this rail writes must balance on its own. */
    public function test_every_posted_entry_balances(): void
    {
        $this->deliverAndConfirm();
        $bill = $this->bill();
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        $source = $this->payingAccount();
        BillPayment::create([
            'bill_id' => $bill->id,
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'payment_source_id' => $source->id,
            'reference_number' => 'TRX-004',
            'user_id' => $this->accounts->id,
        ]);

        $entries = JournalEntry::with('lines')->get();
        $this->assertGreaterThanOrEqual(2, $entries->count());

        foreach ($entries as $entry) {
            $debits = $entry->lines->where('entry_type', 'debit')->sum('amount');
            $credits = $entry->lines->where('entry_type', 'credit')->sum('amount');
            $this->assertEquals($debits, $credits, "Entry {$entry->entry_no} does not balance.");
            $this->assertEquals($entry->total_debit, $debits, "Entry {$entry->entry_no} header disagrees with its lines.");
        }
    }

    public function test_a_verified_supplier_invoice_cannot_be_deleted(): void
    {
        $bill = $this->bill();
        $bill->forceFill([
            'verified_at' => now(),
            'verified_by' => $this->accounts->id,
            'verification_basis' => 'three_way_match',
        ])->save();

        $this->deleteJson("/api/procurement-stores/bills/{$bill->id}")
            ->assertUnprocessable()
            ->assertJsonPath(
                'error',
                'A verified, posted, or paid supplier invoice is an accounting record and cannot be deleted. Reverse or credit it instead.',
            );

        $this->assertDatabaseHas('bills', ['id' => $bill->id]);
    }
}
