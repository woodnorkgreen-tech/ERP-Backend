<?php

namespace Tests\Feature\Procurement;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Wave 2 closure gate §8 (mandatory): an approved commercial amendment must
 * change the PO's effective project/departmental commitment to the revised
 * value — never leave it frozen at the original, and never double it by
 * adding a second commitment alongside the first.
 */
class PurchaseOrderAmendmentCommitmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate(Permissions::PROCUREMENT_ORDERS_APPROVE, 'web');
        Permission::findOrCreate(Permissions::PROCUREMENT_ORDERS_CREATE, 'web');
        Permission::findOrCreate(Permissions::PROCUREMENT_ORDERS_AMEND, 'web');
        $this->seed(AccountingPeriodSeeder::class);
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function approvedOrderViaRealApproval(int $userId, float $unitPrice, float $quantity): PurchaseOrder
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
            'total_amount' => $unitPrice * $quantity, 'status' => 'pending_approval', 'user_id' => $userId,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'material_id' => null, 'custom_description' => 'MDF 18mm sheet',
            'quantity' => $quantity, 'unit_price' => $unitPrice, 'total' => $unitPrice * $quantity,
        ]);

        $approver = $this->user(Permissions::PROCUREMENT_ORDERS_APPROVE);
        Sanctum::actingAs($approver);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/approve")->assertOk();

        return $order->fresh();
    }

    private function committedTotal(PurchaseOrder $order): string
    {
        return (string) CostLine::where('source_type', PurchaseOrderItem::class)
            ->whereIn('source_id', $order->fresh()->items()->pluck('id'))
            ->where('nature', CostLine::NATURE_COMMITTED)
            ->where('status', CostLine::STATUS_VERIFIED)
            ->sum('net_amount');
    }

    public function test_an_upward_commercial_amendment_raises_the_effective_commitment_to_the_revised_value(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->approvedOrderViaRealApproval($requester->id, 10000, 10);
        $this->assertSame('100000.00', $this->committedTotal($order));

        Sanctum::actingAs($requester);
        $response = $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'Supplier revised pricing before delivery',
            'items' => [[
                'id' => $order->items->first()->id,
                'quantity' => 10, 'unit_price' => 12000,
            ]],
        ])->assertCreated();
        $this->assertTrue($response->json('data.is_commercial'));
        $amendmentId = $response->json('data.id');

        $approver = $this->user(Permissions::PROCUREMENT_ORDERS_AMEND);
        Sanctum::actingAs($approver);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments/{$amendmentId}/approve")
            ->assertOk();

        $this->assertSame('120000.00', $order->fresh()->total_amount);
        $this->assertSame(
            '120000.00',
            $this->committedTotal($order),
            'Effective commitment must become 120,000 — not remain 100,000 and not double to 220,000.'
        );
    }

    public function test_a_downward_commercial_amendment_lowers_the_effective_commitment_to_the_revised_value(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->approvedOrderViaRealApproval($requester->id, 12000, 10);
        $this->assertSame('120000.00', $this->committedTotal($order));

        Sanctum::actingAs($requester);
        $amendmentId = $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments", [
            'reason' => 'Quantity reduced before delivery',
            'items' => [[
                'id' => $order->items->first()->id,
                'quantity' => 10, 'unit_price' => 9000,
            ]],
        ])->assertCreated()->json('data.id');

        $approver = $this->user(Permissions::PROCUREMENT_ORDERS_AMEND);
        Sanctum::actingAs($approver);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/amendments/{$amendmentId}/approve")
            ->assertOk();

        $this->assertSame('90000.00', $order->fresh()->total_amount);
        $this->assertSame('90000.00', $this->committedTotal($order));
    }
}
