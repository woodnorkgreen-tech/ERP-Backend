<?php

namespace Tests\Feature\Procurement;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Critical Risks C2 and C3 (finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md):
 * update() previously accepted a full item/supplier/total rewrite of any
 * order regardless of status, and destroy()'s pending-only guard was
 * commented out in source, leaving cascading foreign keys as the only thing
 * standing between an approved+paid order and having its Bill/GRN wiped out
 * from under it. These tests pin the reinstated guards, and prove the
 * pending, unlinked case that legitimately needs to keep working still does.
 */
class PurchaseOrderMutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate(Permissions::PROCUREMENT_ORDERS_APPROVE, 'web');
    }

    private function approver(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::PROCUREMENT_ORDERS_APPROVE);

        return $user;
    }

    private function supplier(User $owner): Supplier
    {
        return Supplier::create([
            'supplier_name' => 'Timber & Board Ltd', 'contact_person' => 'Contact',
            'phone' => '0700000004', 'email' => uniqid().'@test.local',
            'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $owner->id,
        ]);
    }

    private function order(User $owner, string $status): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $this->supplier($owner)->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store', 'description' => 'Board stock',
            'total_amount' => 250000, 'status' => $status, 'user_id' => $owner->id,
        ]);
    }

    // ── C2: update() ─────────────────────────────────────────────────────

    public function test_an_approved_purchase_order_cannot_be_edited(): void
    {
        $user = $this->approver();
        $order = $this->order($user, 'approved');
        Sanctum::actingAs($user);

        $this->putJson("/api/procurement-stores/purchase-orders/{$order->id}", [
            'supplier_id' => $this->supplier($user)->id,
            'total_amount' => 999999,
        ])->assertStatus(422)->assertJsonPath(
            'error',
            fn (string $message) => str_contains($message, 'can no longer be edited directly'),
        );

        $this->assertSame('250000.00', (string) $order->fresh()->total_amount);
    }

    public function test_a_delivered_purchase_order_cannot_be_edited(): void
    {
        $user = $this->approver();
        $order = $this->order($user, 'delivered');
        Sanctum::actingAs($user);

        $this->putJson("/api/procurement-stores/purchase-orders/{$order->id}", [
            'total_amount' => 1,
        ])->assertStatus(422);

        $this->assertSame('250000.00', (string) $order->fresh()->total_amount);
    }

    /** The legitimate case must keep working: a pending order is still a draft. */
    public function test_a_pending_purchase_order_can_still_be_edited(): void
    {
        $user = $this->approver();
        $order = $this->order($user, 'pending');
        Sanctum::actingAs($user);

        $this->putJson("/api/procurement-stores/purchase-orders/{$order->id}", [
            'delivery_address' => 'Updated Store Address',
        ])->assertOk();

        $this->assertSame('Updated Store Address', $order->fresh()->delivery_address);
    }

    // ── C3: destroy() ────────────────────────────────────────────────────

    public function test_an_approved_purchase_order_cannot_be_deleted(): void
    {
        $user = $this->approver();
        $order = $this->order($user, 'approved');
        Sanctum::actingAs($user);

        $this->deleteJson("/api/procurement-stores/purchase-orders/{$order->id}")
            ->assertStatus(422)
            ->assertJsonPath('error', fn (string $message) => str_contains($message, 'cannot be deleted'));

        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id]);
    }

    public function test_a_paid_purchase_orders_bill_and_grn_survive_a_delete_attempt(): void
    {
        // The exact scenario Critical Risk C3 named: an approved order that
        // has already been delivered against and billed. Before the fix, the
        // cascading foreign keys meant this call would have silently deleted
        // the bill and the goods receipt note along with the order.
        $user = $this->approver();
        $order = $this->order($user, 'approved');
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'material_id' => 1,
            'quantity' => 5, 'unit_price' => 5000, 'total' => 25000,
        ]);
        $grn = GoodsReceiptNote::create([
            'grn_number' => 'GRN-'.uniqid(), 'date' => now()->toDateString(),
            'purchase_order_id' => $order->id, 'batch_number' => 'BATCH-'.uniqid(),
            'store_location' => 'Karen Village Store', 'quality_check' => 'pass',
            'store_status' => 'confirmed', 'received_by' => $user->id,
        ]);
        $bill = Bill::create([
            'bill_number' => 'BILL-'.uniqid(), 'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id, 'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(), 'amount' => 25000,
            'status' => 'paid', 'supplier_invoice_number' => 'SINV-1',
            'verified_at' => now(), 'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/procurement-stores/purchase-orders/{$order->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id]);
        $this->assertDatabaseHas('bills', ['id' => $bill->id]);
        $this->assertDatabaseHas('goods_receipt_notes', ['id' => $grn->id]);
        $this->assertDatabaseHas('purchase_order_items', ['id' => $item->id]);
    }

    /** A pending order with a (theoretical/legacy-data) linked bill is still blocked, even though its own status would otherwise allow deletion. */
    public function test_a_pending_order_with_a_linked_bill_cannot_be_deleted(): void
    {
        $user = $this->approver();
        $order = $this->order($user, 'pending');
        Bill::create([
            'bill_number' => 'BILL-'.uniqid(), 'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id, 'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(), 'amount' => 25000,
            'status' => 'pending', 'supplier_invoice_number' => 'SINV-2',
            'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/procurement-stores/purchase-orders/{$order->id}")
            ->assertStatus(422)
            ->assertJsonPath('error', fn (string $message) => str_contains($message, 'linked bill or goods receipt'));

        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id]);
    }

    /** The legitimate case must keep working: a pending, unlinked order can still be removed. */
    public function test_a_pending_unlinked_purchase_order_can_still_be_deleted(): void
    {
        $user = $this->approver();
        $order = $this->order($user, 'pending');
        Sanctum::actingAs($user);

        $this->deleteJson("/api/procurement-stores/purchase-orders/{$order->id}")
            ->assertOk();

        $this->assertDatabaseMissing('purchase_orders', ['id' => $order->id]);
    }
}
