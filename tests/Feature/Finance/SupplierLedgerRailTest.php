<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillPayment;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\PaymentMethod;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Supplier;
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

    private function paymentMethod(): PaymentMethod
    {
        $source = PaymentSource::where('code', 'BANK-MAIN')->firstOrFail();

        return PaymentMethod::updateOrCreate(
            ['method_name' => 'Bank Transfer'],
            ['payment_source_id' => $source->id, 'is_active' => true],
        );
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

    public function test_paying_a_posted_invoice_relieves_the_payable_and_credits_the_source(): void
    {
        $this->deliverAndConfirm();
        $bill = $this->bill();
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        $method = $this->paymentMethod();
        BillPayment::create([
            'bill_id' => $bill->id,
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => $method->id,
            'payment_source_id' => $method->payment_source_id,
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

        $method = $this->paymentMethod();
        BillPayment::create([
            'bill_id' => $bill->id,
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => $method->id,
            'payment_source_id' => $method->payment_source_id,
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

        $method = $this->paymentMethod();
        BillPayment::create([
            'bill_id' => $bill->id,
            'amount_paid' => 20000,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => $method->id,
            'payment_source_id' => $method->payment_source_id,
            'reference_number' => 'TRX-003',
            'user_id' => $this->accounts->id,
        ]);

        // 50,000 credited by the invoice, 20,000 debited by the payment.
        $this->assertSame('-30000.00', $this->movementOn(self::PAYABLE));
        $this->assertSame('-20000.00', $this->movementOn(self::BANK));
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

        $method = $this->paymentMethod();
        BillPayment::create([
            'bill_id' => $bill->id,
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => $method->id,
            'payment_source_id' => $method->payment_source_id,
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
}
