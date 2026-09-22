<?php

namespace Tests\Feature\Procurement;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialUomConversion;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Approving a PO line is the freshest, most authoritative pre-receipt price
 * signal in the system — a buyer just committed company money against it.
 * PurchaseOrderPriceFeedback writes that price forward into the material's
 * default_unit_cost so the catalogue estimate tracks reality instead of
 * freezing at whatever was typed once on the material form (or never typed).
 */
class PurchaseOrderApprovalPricingTest extends TestCase
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

    private function supplier(int $userId): Supplier
    {
        return Supplier::create([
            'supplier_name' => 'Timber & Board Ltd', 'contact_person' => 'Contact',
            'phone' => '0700000003', 'email' => uniqid().'@test.local',
            'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $userId,
        ]);
    }

    private function pendingOrder(int $userId): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $this->supplier($userId)->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store', 'description' => 'Materials',
            'total_amount' => 0, 'status' => 'pending_approval', 'user_id' => $userId,
        ]);
    }

    public function test_approving_a_po_sets_the_material_default_price_from_the_line_priced_in_the_base_unit(): void
    {
        $requester = $this->approver();
        $order = $this->pendingOrder($requester->id);
        $material = LibraryMaterial::create([
            'material_name' => 'MDF 18mm Sheet', 'material_code' => 'MDF-18',
            'item_status' => 'Active', 'default_unit_cost' => null,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'material_id' => $material->id,
            'quantity' => 10, 'unit_price' => 1500.00, 'total' => 15000.00,
        ]);

        $approver = $this->approver();
        Sanctum::actingAs($approver);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/approve")->assertOk();

        $this->assertSame(1500.0, (float) $material->fresh()->default_unit_cost);
    }

    public function test_approving_a_po_converts_a_line_priced_in_the_purchase_unit_down_to_the_base_unit(): void
    {
        $base = UnitOfMeasure::create(['code' => 'M', 'name' => 'Metre', 'dimension' => 'length', 'is_active' => true]);
        $roll = UnitOfMeasure::create(['code' => 'ROLL', 'name' => 'Roll', 'dimension' => 'length', 'is_active' => true]);

        $requester = $this->approver();
        $order = $this->pendingOrder($requester->id);
        $material = LibraryMaterial::create([
            'material_name' => 'Edge Banding Tape', 'material_code' => 'EDG-01',
            'item_status' => 'Active', 'base_uom_id' => $base->id, 'purchase_uom_id' => $roll->id,
        ]);
        MaterialUomConversion::create([
            'material_id' => $material->id, 'from_uom_id' => $roll->id, 'to_uom_id' => $base->id, 'factor' => 50,
        ]);
        // Bought by the roll at 2500, i.e. 50 per metre — default_unit_cost is
        // always kept per base unit, the same basis MaterialPurchaseOptions
        // scales up from when quoting a buyer an ordering price.
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'material_id' => $material->id, 'uom_id' => $roll->id,
            'quantity' => 4, 'unit_price' => 2500.00, 'total' => 10000.00,
        ]);

        $approver = $this->approver();
        Sanctum::actingAs($approver);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/approve")->assertOk();

        $this->assertSame(50.0, (float) $material->fresh()->default_unit_cost);
    }

    public function test_approving_a_po_refreshes_an_existing_estimate_to_the_latest_price(): void
    {
        $requester = $this->approver();
        $order = $this->pendingOrder($requester->id);
        $material = LibraryMaterial::create([
            'material_name' => 'MDF 18mm Sheet', 'material_code' => 'MDF-19',
            'item_status' => 'Active', 'default_unit_cost' => 1200.00,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'material_id' => $material->id,
            'quantity' => 10, 'unit_price' => 1600.00, 'total' => 16000.00,
        ]);

        $approver = $this->approver();
        Sanctum::actingAs($approver);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/approve")->assertOk();

        $this->assertSame(1600.0, (float) $material->fresh()->default_unit_cost);
    }

    public function test_approving_a_po_with_an_unpriced_or_service_line_does_not_error(): void
    {
        $requester = $this->approver();
        $order = $this->pendingOrder($requester->id);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'material_id' => null, 'custom_description' => 'Delivery service',
            'quantity' => 1, 'unit_price' => 0, 'total' => 0,
        ]);

        $approver = $this->approver();
        Sanctum::actingAs($approver);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/approve")->assertOk();

        $this->assertSame('approved', $order->fresh()->status);
    }
}
