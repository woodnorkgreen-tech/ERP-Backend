<?php

namespace Tests\Feature\Procurement;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderAmendment;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * W2-4 (confirmed 2026-09-23, Option B): formal PO Amendment/Change-Order.
 * Materiality is decided purely by which fields changed — never an invented
 * KES/percentage threshold — and a pending commercial amendment pauses
 * receiving/billing until it resolves.
 */
class PurchaseOrderAmendmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate(Permissions::PROCUREMENT_ORDERS_CREATE, 'web');
        Permission::findOrCreate(Permissions::PROCUREMENT_ORDERS_AMEND, 'web');
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function approvedOrder(int $userId, float $unitPrice = 5000, float $quantity = 10): PurchaseOrder
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Timber & Board Ltd', 'contact_person' => 'Contact',
            'phone' => '0700000003', 'email' => uniqid().'@test.local',
            'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $userId,
        ]);
        $order = PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $supplier->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store', 'description' => 'Materials',
            'total_amount' => $unitPrice * $quantity, 'status' => 'approved', 'user_id' => $userId,
            'approved_at' => now(), 'approved_by' => $userId,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'material_id' => null, 'custom_description' => 'MDF 18mm sheet',
            'quantity' => $quantity, 'unit_price' => $unitPrice, 'total' => $unitPrice * $quantity,
        ]);

        return $order->fresh();
    }

    public function test_an_administrative_change_applies_immediately_with_no_reapproval(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->approvedOrder($requester->id);

        Sanctum::actingAs($requester);
        $response = $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'Delivery address was typed incorrectly',
            'delivery_address' => 'Matasia Store',
        ])->assertCreated();

        $this->assertFalse($response->json('data.is_commercial'));
        $this->assertSame('approved', $response->json('data.status'));
        $this->assertSame('Matasia Store', $order->fresh()->delivery_address);
    }

    public function test_a_supplier_change_is_commercial_and_stays_pending_until_approved(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->approvedOrder($requester->id);
        $originalSupplierId = $order->supplier_id;

        $newSupplier = Supplier::create([
            'supplier_name' => 'Steel & Nails Ltd', 'contact_person' => 'Contact',
            'phone' => '0711111111', 'email' => uniqid().'@test.local',
            'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $requester->id,
        ]);

        Sanctum::actingAs($requester);
        $response = $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'Original supplier could no longer fulfil the order',
            'supplier_id' => $newSupplier->id,
        ])->assertCreated();

        $this->assertTrue($response->json('data.is_commercial'));
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertSame($originalSupplierId, $order->fresh()->supplier_id, 'The original supplier remains authoritative until approved.');
    }

    public function test_a_quantity_or_price_change_is_commercial(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->approvedOrder($requester->id);
        $item = $order->items->first();

        Sanctum::actingAs($requester);
        $response = $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'Supplier revised the price',
            'items' => [[
                'id' => $item->id, 'custom_description' => $item->custom_description,
                'quantity' => $item->quantity, 'unit_price' => 5500,
            ]],
        ])->assertCreated();

        $this->assertTrue($response->json('data.is_commercial'));
        $this->assertContains('items', $response->json('data.changed_fields'));
        $this->assertContains('total_amount', $response->json('data.changed_fields'));
        $this->assertSame('5000.00', (string) $item->fresh()->unit_price, 'Unchanged until approved.');
    }

    public function test_nothing_actually_changing_is_refused(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->approvedOrder($requester->id);

        Sanctum::actingAs($requester);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'No actual change',
            'delivery_address' => $order->delivery_address,
        ])->assertStatus(422);
    }

    public function test_only_an_approved_order_can_be_amended(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->approvedOrder($requester->id);
        $order->update(['status' => 'pending']);

        Sanctum::actingAs($requester);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'Trying to amend a non-approved order',
            'delivery_address' => 'Matasia Store',
        ])->assertStatus(422);
    }

    public function test_approving_a_commercial_amendment_applies_it(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->approvedOrder($requester->id);
        $item = $order->items->first();

        Sanctum::actingAs($requester);
        $amendmentId = $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'Supplier revised the price',
            'items' => [[
                'id' => $item->id, 'custom_description' => $item->custom_description,
                'quantity' => $item->quantity, 'unit_price' => 5500,
            ]],
        ])->assertCreated()->json('data.id');

        $approver = $this->user(Permissions::PROCUREMENT_ORDERS_AMEND);
        Sanctum::actingAs($approver);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments/{$amendmentId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame('5500.00', (string) $order->items()->first()->unit_price);
        $this->assertSame('55000.00', (string) $order->fresh()->total_amount);
    }

    public function test_the_requester_cannot_approve_their_own_amendment(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE, Permissions::PROCUREMENT_ORDERS_AMEND);
        $order = $this->approvedOrder($requester->id);

        $newSupplier = Supplier::create([
            'supplier_name' => 'Steel & Nails Ltd', 'contact_person' => 'Contact',
            'phone' => '0711111111', 'email' => uniqid().'@test.local',
            'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $requester->id,
        ]);

        Sanctum::actingAs($requester);
        $amendmentId = $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'Supplier change', 'supplier_id' => $newSupplier->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments/{$amendmentId}/approve")
            ->assertStatus(422);
        $this->assertSame('pending', PurchaseOrderAmendment::find($amendmentId)->status);
    }

    public function test_rejecting_a_commercial_amendment_leaves_the_order_unchanged(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->approvedOrder($requester->id);
        $item = $order->items->first();

        Sanctum::actingAs($requester);
        $amendmentId = $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'Price change proposal',
            'items' => [[
                'id' => $item->id, 'custom_description' => $item->custom_description,
                'quantity' => $item->quantity, 'unit_price' => 5500,
            ]],
        ])->assertCreated()->json('data.id');

        $approver = $this->user(Permissions::PROCUREMENT_ORDERS_AMEND);
        Sanctum::actingAs($approver);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments/{$amendmentId}/reject", [
            'reason' => 'Price increase not authorized',
        ])->assertOk();

        $this->assertSame('5000.00', (string) $order->items()->first()->unit_price);
        $this->assertSame('rejected', PurchaseOrderAmendment::find($amendmentId)->status);
    }

    public function test_a_pending_commercial_amendment_pauses_goods_receiving(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->approvedOrder($requester->id);
        $item = $order->items->first();

        Sanctum::actingAs($requester);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'Quantity revised',
            'items' => [[
                'id' => $item->id, 'custom_description' => $item->custom_description,
                'quantity' => 20, 'unit_price' => 5000,
            ]],
        ])->assertCreated();

        $this->postJson('/api/procurement-stores/goods-receipt-notes', [
            'purchase_order_id' => $order->id,
            'store_location' => 'Karen Village Store', 'quality_check' => 'pass',
            'items' => [[
                'purchase_order_item_id' => $item->id, 'ordered_quantity' => $item->quantity,
                'received_quantity' => $item->quantity, 'condition' => 'good', 'accepted' => true,
            ]],
        ])->assertStatus(422);
    }

    public function test_a_pending_commercial_amendment_pauses_billing(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->approvedOrder($requester->id);
        $item = $order->items->first();

        Sanctum::actingAs($requester);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'Price revised',
            'items' => [[
                'id' => $item->id, 'custom_description' => $item->custom_description,
                'quantity' => $item->quantity, 'unit_price' => 5500,
            ]],
        ])->assertCreated();

        $this->postJson('/api/procurement-stores/bills', [
            'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(), 'amount' => 50000,
            'supplier_invoice_number' => 'SINV-1',
        ])->assertStatus(422);
    }

    public function test_amendment_numbers_increment_per_order(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->approvedOrder($requester->id);

        Sanctum::actingAs($requester);
        $first = $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'First correction', 'delivery_address' => 'Matasia Store',
        ])->assertCreated()->json('data.amendment_number');

        $second = $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'Second correction', 'description' => 'Updated description',
        ])->assertCreated()->json('data.amendment_number');

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);
    }
}
