<?php

namespace Tests\Feature\Procurement;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\Supplier;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Approving a purchase order commits company money against a named supplier —
 * exactly the kind of decision a second person is supposed to check. Every
 * other approval gate in this codebase (petty cash, spend vouchers, budget
 * additions, cost verification, client receipts — see SelfApproval's own
 * docblock) already refuses to let the person who raised a thing also sign it
 * off. This endpoint never had that check: `canApproveOrDelete()` asked only
 * "does this role hold the approval permission", never "did this person raise
 * the very order in front of them." No test exercised the HTTP endpoint at
 * all before this file.
 */
class PurchaseOrderApprovalSegregationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate(Permissions::PROCUREMENT_ORDERS_APPROVE, 'web');
        Permission::findOrCreate(Permissions::APPROVALS_SELF_APPROVE, 'web');
    }

    private function approver(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::PROCUREMENT_ORDERS_APPROVE);

        return $user;
    }

    /** A purchase order raised by the given user, awaiting approval. */
    private function pendingOrder(User $raisedBy): PurchaseOrder
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Timber & Board Ltd', 'contact_person' => 'Contact',
            'phone' => '0700000003', 'email' => uniqid().'@test.local',
            'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $raisedBy->id,
        ]);

        return PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $supplier->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store', 'description' => 'Board stock',
            'total_amount' => 250000, 'status' => 'pending_approval', 'user_id' => $raisedBy->id,
        ]);
    }

    public function test_the_person_who_raised_the_order_cannot_approve_it_themselves(): void
    {
        $requester = $this->approver();
        $order = $this->pendingOrder($requester);

        Sanctum::actingAs($requester);

        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/approve")
            ->assertStatus(422);

        $this->assertSame('pending_approval', $order->fresh()->status);
    }

    public function test_a_different_approver_may_approve_it(): void
    {
        $requester = $this->approver();
        $order = $this->pendingOrder($requester);

        $approver = $this->approver();
        Sanctum::actingAs($approver);

        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/approve")
            ->assertOk();

        $order->refresh();
        $this->assertSame('approved', $order->status);
        $this->assertSame($approver->id, $order->approved_by);
    }

    public function test_the_self_approve_permission_lifts_the_block(): void
    {
        $requester = $this->approver();
        $requester->givePermissionTo(Permissions::APPROVALS_SELF_APPROVE);
        $order = $this->pendingOrder($requester);

        Sanctum::actingAs($requester);

        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_a_super_admin_may_approve_their_own_order_without_the_permission(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'web');
        $requester = User::factory()->create(['is_active' => true]);
        $requester->assignRole('Super Admin');
        $order = $this->pendingOrder($requester);

        Sanctum::actingAs($requester);

        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $order->fresh()->status);
    }
}
