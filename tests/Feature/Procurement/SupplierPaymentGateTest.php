<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillPayment;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\PaymentMethod;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Supplier;
use App\Modules\ProcurementStores\Services\PurchaseOrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Approving a purchase order authorises a purchase; it does not mean anything
 * arrived or that anyone owes the supplier yet. These tests pin the controls
 * that sit between approval and money leaving: the stage each order is at, and
 * the three-way match a supplier invoice must pass before it can be paid.
 */
class SupplierPaymentGateTest extends TestCase
{
    use RefreshDatabase;

    private User $accounts;
    private Supplier $supplier;
    private PurchaseOrder $order;
    private PurchaseOrderItem $orderItem;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Accounts', 'web');
        $this->accounts = User::create([
            'name' => 'Accounts Clerk',
            'email' => uniqid('accounts_').'@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $this->accounts->assignRole('Accounts');
        Sanctum::actingAs($this->accounts);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Timber & Board Ltd',
            'contact_person' => 'Supplier Contact',
            'phone' => '0700000002',
            'email' => uniqid('supplier_').'@test.local',
            'address' => 'Industrial Area',
            'payment_terms' => '30 days',
            'status' => 'Active',
            'user_id' => $this->accounts->id,
        ]);

        $this->order = PurchaseOrder::create([
            'po_number' => 'PO-TEST-'.uniqid(),
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

    /** Record a delivery. Stores confirmation is a separate, deliberate step. */
    private function deliver(float $received, bool $confirmed): GoodsReceiptNoteItem
    {
        $note = GoodsReceiptNote::create([
            'grn_number' => 'GRN-TEST-'.uniqid(),
            'date' => now()->toDateString(),
            'purchase_order_id' => $this->order->id,
            'batch_number' => 'BATCH-'.uniqid(),
            'store_location' => 'Karen Village Store',
            'quality_check' => 'pass',
            'store_status' => $confirmed ? 'confirmed' : 'pending_confirmation',
            'received_by' => $this->accounts->id,
        ]);

        return GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id,
            'purchase_order_item_id' => $this->orderItem->id,
            'ordered_quantity' => 10,
            'received_quantity' => $received,
            'condition' => 'good',
            'accepted' => true,
            'store_status' => $confirmed ? 'confirmed' : 'pending',
            'stock_status' => $confirmed ? 'posted' : 'awaiting_stores_details',
            'unit_price' => 5000,
        ]);
    }

    private function bill(float $amount, ?string $invoiceNumber = 'SINV-1001'): Bill
    {
        return Bill::create([
            'bill_number' => Bill::generateBillNumber(),
            'purchase_order_id' => $this->order->id,
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => $amount,
            'status' => 'pending',
            'supplier_invoice_number' => $invoiceNumber,
            'user_id' => $this->accounts->id,
        ]);
    }

    private function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(['method_name' => 'Bank Transfer']);
    }

    public function test_an_approved_order_awaits_delivery_not_payment(): void
    {
        $state = app(PurchaseOrderWorkflow::class)->order($this->order);

        $this->assertSame('delivery', $state['stage']);
        $this->assertSame('Procurement', $state['owner']);
        $this->assertFalse($state['receipt_started']);
    }

    public function test_a_delivery_awaiting_stores_confirmation_is_not_yet_accepted(): void
    {
        $this->deliver(received: 10, confirmed: false);

        $state = app(PurchaseOrderWorkflow::class)->order($this->order->fresh());

        $this->assertSame('stores', $state['stage']);
        $this->assertSame('Stores', $state['owner']);
        $this->assertSame('10.000000', $state['items'][0]['received']);
        $this->assertSame('0.000000', $state['items'][0]['accepted']);
        $this->assertSame('0.00', $state['accepted_value']);
    }

    public function test_stores_confirmation_moves_the_order_to_invoicing(): void
    {
        $this->deliver(received: 10, confirmed: true);

        $state = app(PurchaseOrderWorkflow::class)->order($this->order->fresh());

        $this->assertSame('invoice', $state['stage']);
        $this->assertTrue($state['receipt_complete']);
        $this->assertSame('50000.00', $state['accepted_value']);
    }

    public function test_an_invoice_cannot_be_verified_before_stores_accepts_the_goods(): void
    {
        $this->deliver(received: 10, confirmed: false);
        $bill = $this->bill(50000);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")
            ->assertStatus(422)
            ->assertJsonPath('error', 'This invoice does not yet pass the three-way match.');

        $this->assertNull($bill->fresh()->verified_at);
    }

    public function test_an_unverified_invoice_cannot_be_paid(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);

        $response = $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod()->id,
            'reference_number' => 'FT-0001',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('must verify', $response->json('error'));
        $this->assertSame(0, BillPayment::where('bill_id', $bill->id)->count());
    }

    public function test_a_verified_invoice_can_be_paid(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify", [
            'verification_notes' => 'Checked against GRN and order.',
        ])->assertOk()->assertJsonPath('data.verified', true);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod()->id,
            'reference_number' => 'FT-0001',
        ])->assertSuccessful();

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame('three_way_match', $bill->fresh()->verification_basis);
    }

    public function test_an_invoice_above_the_value_accepted_into_stock_is_refused(): void
    {
        // Half the order arrived; the supplier invoiced for all of it.
        $this->deliver(received: 5, confirmed: true);
        $bill = $this->bill(50000);

        $response = $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")
            ->assertStatus(422);

        $this->assertContains(
            'Invoice does not exceed the value accepted into stock',
            $response->json('blockers')
        );
    }

    public function test_a_part_delivery_may_be_part_invoiced(): void
    {
        $this->deliver(received: 5, confirmed: true);
        $bill = $this->bill(25000);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.receipt_complete', false);
    }

    public function test_repricing_an_invoice_withdraws_its_verification(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        // The supplier reissues at a lower figure. The sign-off was a statement
        // about the old one, so it must not carry over.
        $bill->update(['amount' => 40000]);

        $this->assertNull($bill->fresh()->verified_at);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => 40000,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod()->id,
            'reference_number' => 'FT-0002',
        ])->assertStatus(422);
    }

    public function test_a_later_change_to_the_order_withdraws_a_standing_verification(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        // Re-pricing the order changes what was checked without touching the
        // invoice row, so only the fingerprint can catch it.
        $this->orderItem->update(['unit_price' => 4000, 'total' => 40000]);
        $this->order->update(['total_amount' => 40000]);

        $this->getJson("/api/procurement-stores/bills/{$bill->id}/verification")
            ->assertOk()
            ->assertJsonPath('data.verified', false)
            ->assertJsonPath('data.can_pay', false);
    }

    public function test_no_payment_path_can_bypass_the_gate(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);

        // Writing the payment row directly is the path a new caller would take
        // by accident. The model itself refuses it.
        $this->expectException(\RuntimeException::class);

        BillPayment::create([
            'bill_id' => $bill->id,
            'amount_paid' => 50000,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod()->id,
            'reference_number' => 'DIRECT',
            'user_id' => $this->accounts->id,
        ]);
    }

    public function test_invoices_raised_before_the_control_existed_stay_payable(): void
    {
        $bill = $this->bill(50000, invoiceNumber: null);
        $bill->forceFill([
            'verified_at' => now()->subMonth(),
            'verification_basis' => 'legacy',
        ])->saveQuietly();

        $state = app(PurchaseOrderWorkflow::class)->bill($bill->fresh());

        $this->assertTrue($state['can_pay']);
        $this->assertSame('legacy', $state['verification_basis']);
        $this->assertSame([], $state['blockers']);
    }

    public function test_only_accounts_may_verify_an_invoice_for_payment(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);

        $storeman = User::create([
            'name' => 'Storeman',
            'email' => uniqid('stores_').'@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $storeman->assignRole(Role::findOrCreate('Stores', 'web'));
        Sanctum::actingAs($storeman);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertStatus(403);
        $this->assertNull($bill->fresh()->verified_at);
    }

    public function test_the_order_workflow_endpoint_names_the_owner_of_the_next_move(): void
    {
        $this->deliver(received: 10, confirmed: false);

        $this->getJson("/api/procurement-stores/purchase-orders/{$this->order->id}/workflow")
            ->assertOk()
            ->assertJsonPath('data.stage', 'stores')
            ->assertJsonPath('data.owner', 'Stores')
            ->assertJsonPath('data.receipt_complete', false);
    }

    public function test_the_payables_list_says_which_invoices_can_actually_be_paid(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $blocked = $this->bill(50000);

        $this->getJson('/api/procurement-stores/pending-bills?supplier_id='.$this->supplier->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $blocked->id)
            ->assertJsonPath('data.0.can_pay', false);

        $this->postJson("/api/procurement-stores/bills/{$blocked->id}/verify")->assertOk();

        $this->getJson('/api/procurement-stores/pending-bills?supplier_id='.$this->supplier->id)
            ->assertOk()
            ->assertJsonPath('data.0.can_pay', true);
    }

    public function test_a_batch_payment_is_refused_whole_when_one_invoice_is_blocked(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $verified = $this->bill(30000);
        $this->postJson("/api/procurement-stores/bills/{$verified->id}/verify")->assertOk();

        // A second order and invoice for the same supplier, never verified.
        $secondOrder = PurchaseOrder::create([
            'po_number' => 'PO-TEST-'.uniqid(),
            'date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store',
            'total_amount' => 10000,
            'status' => 'approved',
            'user_id' => $this->accounts->id,
        ]);
        $unverified = Bill::create([
            'bill_number' => Bill::generateBillNumber(),
            'purchase_order_id' => $secondOrder->id,
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 10000,
            'status' => 'pending',
            'user_id' => $this->accounts->id,
        ]);

        $response = $this->postJson('/api/procurement-stores/multi-payment', [
            'bill_ids' => [$verified->id, $unverified->id],
            'amount_paid' => 40000,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod()->id,
            'reference_number' => 'FT-BATCH',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('blocked'));

        // Neither invoice moved: a batch settles whole or not at all.
        $this->assertSame(0, BillPayment::whereIn('bill_id', [$verified->id, $unverified->id])->count());
        $this->assertSame('30000.00', (string) $verified->fresh()->balance);
    }

    public function test_a_payment_larger_than_the_balance_is_refused(): void
    {
        $this->deliver(received: 10, confirmed: true);
        $bill = $this->bill(50000);
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")->assertOk();

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => 60000,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod()->id,
            'reference_number' => 'FT-0003',
        ])->assertStatus(422);
    }
}
