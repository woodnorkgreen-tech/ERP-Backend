<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Supplier;
use App\Modules\ProcurementStores\Services\PurchaseOrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * W2-3 (confirmed 2026-09-23, Option A): multiple/staged supplier Bills
 * against one PO, capped at `Remaining Billable = Approved PO Value −
 * Sum(Valid Bills)`. Multiple GRNs per PO were already supported; only the
 * one-Bill-per-PO restriction is relaxed here, and only alongside this cap.
 */
class StagedBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $accounts;
    private Supplier $supplier;
    private PurchaseOrder $order;
    private PurchaseOrderItem $orderItem;

    protected function setUp(): void
    {
        parent::setUp();

        // Verification is a ledger event (see SupplierPaymentGateTest's
        // identical setup note): it moves the accrual onto Accounts
        // Payable, so a chart and an open period are part of what the
        // workflow assumes.
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);

        $accountsRole = Role::findOrCreate('Accounts', 'web');
        $this->accounts = User::create([
            'name' => 'Accounts Clerk', 'email' => uniqid('accounts_').'@test.local',
            'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $this->accounts->assignRole('Accounts');
        // One actor playing every role (raising, billing, verifying) is a
        // convenience for testing staged billing, not a claim that
        // self-verification should be allowed in production — see
        // SupplierPaymentGateTest's identical fixture note.
        $this->accounts->givePermissionTo(
            Permission::findOrCreate(\App\Constants\Permissions::APPROVALS_SELF_APPROVE, 'web'),
        );
        Sanctum::actingAs($this->accounts);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Timber & Board Ltd', 'contact_person' => 'Contact',
            'phone' => '0700000002', 'email' => uniqid('supplier_').'@test.local',
            'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $this->accounts->id,
        ]);

        // A 100,000 order, delivered and accepted in full, ready to be billed
        // — in one Bill or several.
        $this->order = PurchaseOrder::create([
            'po_number' => 'PO-TEST-'.uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store', 'description' => 'Board stock',
            'total_amount' => 100000, 'status' => 'approved', 'user_id' => $this->accounts->id,
            'approved_at' => now(), 'approved_by' => $this->accounts->id,
        ]);
        $this->orderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $this->order->id, 'custom_description' => 'MDF 18mm sheet',
            'quantity' => 20, 'unit_price' => 5000, 'total' => 100000,
        ]);

        $note = GoodsReceiptNote::create([
            'grn_number' => 'GRN-TEST-'.uniqid(), 'date' => now()->toDateString(),
            'purchase_order_id' => $this->order->id, 'batch_number' => 'BATCH-'.uniqid(),
            'store_location' => 'Karen Village Store', 'quality_check' => 'pass',
            'store_status' => 'confirmed', 'received_by' => $this->accounts->id,
        ]);
        GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id, 'purchase_order_item_id' => $this->orderItem->id,
            'ordered_quantity' => 20, 'received_quantity' => 20, 'condition' => 'good',
            'accepted' => true, 'store_status' => 'confirmed', 'stock_status' => 'posted',
            'unit_price' => 5000,
        ]);
    }

    private function billPayload(float $amount, string $invoiceNumber): array
    {
        return [
            'purchase_order_id' => $this->order->id, 'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(), 'amount' => $amount,
            'supplier_invoice_number' => $invoiceNumber,
        ];
    }

    public function test_a_second_bill_is_accepted_when_it_fits_the_remaining_balance(): void
    {
        $this->postJson('/api/procurement-stores/bills', $this->billPayload(60000, 'SINV-1'))->assertCreated();
        $this->postJson('/api/procurement-stores/bills', $this->billPayload(40000, 'SINV-2'))->assertCreated();

        $this->assertSame(2, Bill::where('purchase_order_id', $this->order->id)->count());
        $this->assertSame('0.00', $this->order->fresh()->remainingBillable());
    }

    public function test_cumulative_billing_beyond_the_approved_value_is_blocked(): void
    {
        $this->postJson('/api/procurement-stores/bills', $this->billPayload(60000, 'SINV-1'))->assertCreated();

        $response = $this->postJson('/api/procurement-stores/bills', $this->billPayload(50000, 'SINV-2'))
            ->assertStatus(422);
        $this->assertArrayHasKey('amount', $response->json('error'));

        $this->assertSame(1, Bill::where('purchase_order_id', $this->order->id)->count());
        $this->assertSame('40000.00', $this->order->fresh()->remainingBillable());
    }

    public function test_a_third_bill_sees_the_balance_after_the_first_two(): void
    {
        $this->postJson('/api/procurement-stores/bills', $this->billPayload(30000, 'SINV-1'))->assertCreated();
        $this->postJson('/api/procurement-stores/bills', $this->billPayload(30000, 'SINV-2'))->assertCreated();
        $this->assertSame('40000.00', $this->order->fresh()->remainingBillable());

        $this->postJson('/api/procurement-stores/bills', $this->billPayload(40000, 'SINV-3'))->assertCreated();
        $this->assertSame('0.00', $this->order->fresh()->remainingBillable());
        $this->assertSame(3, Bill::where('purchase_order_id', $this->order->id)->count());
    }

    public function test_each_bill_keeps_its_own_invoice_number_and_verification(): void
    {
        $first = $this->postJson('/api/procurement-stores/bills', $this->billPayload(60000, 'SINV-1'))
            ->assertCreated()->json('data.id');
        $second = $this->postJson('/api/procurement-stores/bills', $this->billPayload(40000, 'SINV-2'))
            ->assertCreated()->json('data.id');

        $this->postJson("/api/procurement-stores/bills/{$first}/verify")->assertOk();

        $this->assertNotNull(Bill::find($first)->verified_at);
        $this->assertNull(Bill::find($second)->verified_at, 'Verifying one bill must not verify another.');
        $this->assertNotSame(
            Bill::find($first)->supplier_invoice_number,
            Bill::find($second)->supplier_invoice_number,
        );
    }

    public function test_a_void_bill_frees_its_share_of_the_billable_balance(): void
    {
        $billId = $this->postJson('/api/procurement-stores/bills', $this->billPayload(60000, 'SINV-1'))
            ->assertCreated()->json('data.id');
        $this->assertSame('40000.00', $this->order->fresh()->remainingBillable());

        Bill::whereKey($billId)->update(['status' => 'cancelled']);
        $this->assertSame('100000.00', $this->order->fresh()->remainingBillable());
    }

    public function test_verifying_a_bill_measures_against_the_order_not_the_full_total_once_others_exist(): void
    {
        $first = $this->postJson('/api/procurement-stores/bills', $this->billPayload(60000, 'SINV-1'))
            ->assertCreated()->json('data.id');
        $second = $this->postJson('/api/procurement-stores/bills', $this->billPayload(40000, 'SINV-2'))
            ->assertCreated()->json('data.id');

        $state = app(PurchaseOrderWorkflow::class)->bill(Bill::find($second));
        $orderTotalCheck = collect($state['checks'])->firstWhere('key', 'order_total');

        $this->assertTrue($orderTotalCheck['passed']);
        $this->assertStringContainsString('40,000.00 invoiced against 40,000.00 remaining billable', $orderTotalCheck['detail']);

        // Re-verifying the FIRST bill must not be measured against what the
        // second bill already consumed — it excludes itself, the same as
        // when it was originally raised.
        $firstState = app(PurchaseOrderWorkflow::class)->bill(Bill::find($first));
        $firstCheck = collect($firstState['checks'])->firstWhere('key', 'order_total');
        $this->assertTrue($firstCheck['passed']);
    }

    /**
     * Closure gate §16: PurchaseOrderWorkflow::order()'s single "stage" field
     * still reflects only the first Bill raised (a documented, deliberate
     * Wave-2 scope limit — see the class docblock). But claiming the order
     * is "complete"/settled off that first Bill alone, while a second Bill
     * is still unpaid, is a materially misleading financial state, not a
     * harmless backward-compatible quirk — fixed to require every
     * non-cancelled Bill actually settled, and backed here by the aggregate
     * figures the same response now also carries.
     */
    /**
     * Closure gate §6: while only one Bill could ever exist per order,
     * "invoice ≤ accepted value" WAS the cumulative receipt check — there
     * was only one invoice to measure. W2-3 allowed several without making
     * this specific check cumulative, so three staged Bills could each
     * individually pass it while jointly billing more than Stores actually
     * accepted. Reproduced here with a genuinely partial receipt (40 of 100
     * units), then fixed by measuring each Bill against the accepted value
     * still unbilled by any other valid Bill, the same exclusion already
     * used for the order-total check.
     */
    public function test_cumulative_billing_cannot_exceed_what_was_actually_received(): void
    {
        $order = PurchaseOrder::create([
            'po_number' => 'PO-PARTIAL-'.uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store', 'description' => 'Board stock',
            'total_amount' => 100000, 'status' => 'approved', 'user_id' => $this->accounts->id,
            'approved_at' => now(), 'approved_by' => $this->accounts->id,
        ]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'custom_description' => 'MDF 18mm sheet',
            'quantity' => 100, 'unit_price' => 1000, 'total' => 100000,
        ]);
        $note = GoodsReceiptNote::create([
            'grn_number' => 'GRN-PARTIAL-'.uniqid(), 'date' => now()->toDateString(),
            'purchase_order_id' => $order->id, 'batch_number' => 'BATCH-'.uniqid(),
            'store_location' => 'Karen Village Store', 'quality_check' => 'pass',
            'store_status' => 'confirmed', 'received_by' => $this->accounts->id,
        ]);
        GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id, 'purchase_order_item_id' => $item->id,
            'ordered_quantity' => 100, 'received_quantity' => 40, 'condition' => 'good',
            'accepted' => true, 'store_status' => 'confirmed', 'stock_status' => 'posted',
            'unit_price' => 1000,
        ]);

        $payload = fn (float $amount, string $invoice) => [
            'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(), 'amount' => $amount,
            'supplier_invoice_number' => $invoice,
        ];

        $first = $this->postJson('/api/procurement-stores/bills', $payload(20000, 'PARTIAL-1'))
            ->assertCreated()->json('data.id');
        $second = $this->postJson('/api/procurement-stores/bills', $payload(20000, 'PARTIAL-2'))
            ->assertCreated()->json('data.id');
        // Both fit within the order's 100,000 total, so PO-value creation
        // succeeds — but only 40,000 was ever actually received, and these
        // two already consume every shilling of it.
        $third = $this->postJson('/api/procurement-stores/bills', $payload(1000, 'PARTIAL-3'))
            ->assertCreated()->json('data.id');

        $state = app(PurchaseOrderWorkflow::class)->bill(Bill::find($third));
        $acceptedCheck = collect($state['checks'])->firstWhere('key', 'accepted_value');

        $this->assertFalse(
            $acceptedCheck['passed'],
            'A third bill must not verify once the first two already consumed all 40,000 of what was actually received, regardless of the order\'s larger total value.'
        );
        $this->assertFalse($state['eligible_for_verification']);
    }

    public function test_the_order_is_not_marked_complete_while_a_later_bill_is_still_unpaid(): void
    {
        $first = $this->postJson('/api/procurement-stores/bills', $this->billPayload(60000, 'SINV-1'))
            ->assertCreated()->json('data.id');
        $this->postJson('/api/procurement-stores/bills', $this->billPayload(40000, 'SINV-2'))
            ->assertCreated();

        // Simulate the first bill being fully paid off, directly on its own
        // ledger columns — the same direct-state approach this file already
        // uses for a cancelled bill above, since exercising the full payment
        // HTTP flow is not what this test is about.
        Bill::whereKey($first)->update(['balance' => '0.00', 'paid_amount' => '60000.00']);

        $state = app(PurchaseOrderWorkflow::class)->order($this->order->fresh());

        $this->assertNotSame('complete', $state['stage'], 'The order must not show as settled while SINV-2 is still unpaid.');
        $this->assertSame(2, $state['bills_count']);
        $this->assertSame(1, $state['bills_paid_count']);
        $this->assertSame('100000.00', $state['total_billed']);
        $this->assertSame('0.00', $state['remaining_billable']);
    }
}
