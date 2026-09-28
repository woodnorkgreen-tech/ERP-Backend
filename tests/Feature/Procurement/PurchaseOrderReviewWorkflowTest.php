<?php

namespace Tests\Feature\Procurement;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Models\FinanceSetting;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Wave 2 (W2-1 senior approval, W2-6 Return for Correction) — both confirmed
 * 2026-09-23. Layered on the existing PurchaseApprovalPolicy cover/ceiling
 * logic and the STAB-3 direct-edit guard respectively; neither replaces what
 * was already there.
 */
class PurchaseOrderReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate(Permissions::PROCUREMENT_ORDERS_APPROVE, 'web');
        Permission::findOrCreate(Permissions::PROCUREMENT_ORDERS_APPROVE_SENIOR, 'web');
        Permission::findOrCreate(Permissions::PROCUREMENT_ORDERS_CREATE, 'web');
        $this->seed(AccountingPeriodSeeder::class);
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

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

    private function pendingApprovalOrder(User $requester, float $total): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $this->supplier($requester->id)->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store', 'description' => 'Materials',
            'total_amount' => $total, 'status' => 'pending', 'user_id' => $requester->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'material_id' => null, 'custom_description' => 'Materials',
            'quantity' => 1, 'unit_price' => $total, 'total' => $total,
        ]);
        $order->submitForApproval();

        return $order->fresh();
    }

    private function approveThreshold(float $amount): void
    {
        FinanceSetting::create([
            'key' => 'purchase_order_senior_approval_threshold',
            'value' => json_encode($amount),
            'label' => 'Purchase order senior-approval threshold (KES)',
            'approved_by' => User::factory()->create()->id,
            'approved_at' => now(),
            'effective_from' => '2020-01-01',
        ]);
    }

    // ---------------------------------------------------------------- W2-1

    public function test_an_order_below_the_threshold_approves_exactly_as_before(): void
    {
        $this->approveThreshold(1000000);
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->pendingApprovalOrder($requester, 500000);

        $this->assertFalse($order->senior_approval_required);

        Sanctum::actingAs($this->user(Permissions::PROCUREMENT_ORDERS_APPROVE));
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/approve")->assertOk();
        $this->assertSame('approved', $order->fresh()->status);
    }

    /**
     * Closure gate §3 boundary conditions: the comparison is strictly
     * greater-than (`bccomp(...) > 0` in seniorApprovalThreshold()'s caller),
     * so an order at exactly the threshold is not "high value" yet — only
     * the first shilling past it is.
     */
    public function test_boundary_just_below_at_and_just_above_the_threshold(): void
    {
        $this->approveThreshold(1000000);
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);

        $justBelow = $this->pendingApprovalOrder($requester, 999999.99);
        $this->assertFalse($justBelow->senior_approval_required);

        $exactly = $this->pendingApprovalOrder($requester, 1000000.00);
        $this->assertFalse($exactly->senior_approval_required, 'Exactly at the threshold must not itself require senior approval.');

        $justAbove = $this->pendingApprovalOrder($requester, 1000000.01);
        $this->assertTrue($justAbove->senior_approval_required);
    }

    public function test_no_threshold_configured_never_requires_senior_approval(): void
    {
        // Deliberately no approveThreshold() call — mirrors the seeded,
        // unapproved-null default state.
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->pendingApprovalOrder($requester, 50000000);

        $this->assertFalse($order->senior_approval_required);
        Sanctum::actingAs($this->user(Permissions::PROCUREMENT_ORDERS_APPROVE));
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/approve")->assertOk();
    }

    public function test_an_order_above_the_threshold_blocks_approval_until_senior_approved(): void
    {
        $this->approveThreshold(1000000);
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->pendingApprovalOrder($requester, 1500000);
        $this->assertTrue($order->senior_approval_required);

        $approver = $this->user(Permissions::PROCUREMENT_ORDERS_APPROVE);
        Sanctum::actingAs($approver);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/approve")
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'This order is above the senior-approval threshold and needs senior approval before it can be approved.']);
        $this->assertSame('pending_approval', $order->fresh()->status);

        $senior = $this->user(Permissions::PROCUREMENT_ORDERS_APPROVE_SENIOR);
        Sanctum::actingAs($senior);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/senior-approve")->assertOk();
        $this->assertSame($senior->id, $order->fresh()->senior_approved_by);
        $this->assertNotNull($order->fresh()->senior_approved_at);

        Sanctum::actingAs($approver);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/approve")->assertOk();
        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_the_requester_cannot_satisfy_their_own_senior_approval(): void
    {
        $this->approveThreshold(1000000);
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE, Permissions::PROCUREMENT_ORDERS_APPROVE_SENIOR);
        $order = $this->pendingApprovalOrder($requester, 1500000);

        Sanctum::actingAs($requester);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/senior-approve")
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'You raised this purchase order, so you cannot give its senior approval.']);
        $this->assertNull($order->fresh()->senior_approved_at);
    }

    public function test_holding_ordinary_approval_alone_is_not_enough_for_senior_approval(): void
    {
        $this->approveThreshold(1000000);
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->pendingApprovalOrder($requester, 1500000);

        Sanctum::actingAs($this->user(Permissions::PROCUREMENT_ORDERS_APPROVE));
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/senior-approve")->assertStatus(403);
    }

    // ---------------------------------------------------------------- W2-6

    public function test_returning_an_order_for_correction_requires_a_reason_and_records_it(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->pendingApprovalOrder($requester, 100000);
        $reviewer = $this->user(Permissions::PROCUREMENT_ORDERS_APPROVE);

        Sanctum::actingAs($reviewer);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/return-for-correction", [])
            ->assertStatus(422);

        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/return-for-correction", [
            'reason' => 'Wrong supplier selected',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('returned_for_correction', $order->status);
        $this->assertSame($reviewer->id, $order->returned_by);
        $this->assertNotNull($order->returned_at);
        $this->assertSame('Wrong supplier selected', $order->return_reason);
    }

    public function test_only_an_order_awaiting_approval_can_be_returned(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->pendingApprovalOrder($requester, 100000);
        $order->approve($this->user(Permissions::PROCUREMENT_ORDERS_APPROVE)->id);

        Sanctum::actingAs($this->user(Permissions::PROCUREMENT_ORDERS_APPROVE));
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/return-for-correction", [
            'reason' => 'Too late now',
        ])->assertStatus(422);
    }

    public function test_the_requester_can_correct_a_returned_order_and_resubmit(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->pendingApprovalOrder($requester, 100000);
        $reviewer = $this->user(Permissions::PROCUREMENT_ORDERS_APPROVE);
        $order->returnForCorrection($reviewer->id, 'Wrong delivery address');

        Sanctum::actingAs($requester);
        $this->putJson("/api/procurement-stores/purchase-orders/{$order->id}", [
            'delivery_address' => 'Corrected address',
        ])->assertOk();
        $this->assertSame('Corrected address', $order->fresh()->delivery_address);

        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/resubmit")->assertOk();
        $order->refresh();
        $this->assertSame('pending_approval', $order->status);
        $this->assertNotNull($order->resubmitted_at);

        // Closure gate §14: the correction record retains what changed, not
        // just the final corrected value the PO row now shows.
        $correction = $order->corrections()->first();
        $this->assertSame(1, $correction->correction_number);
        $this->assertSame($reviewer->id, $correction->returned_by);
        $this->assertSame('Wrong delivery address', $correction->return_reason);
        $this->assertSame('Karen Village Store', $correction->previous_snapshot['delivery_address']);
        $this->assertSame($requester->id, $correction->corrected_by);
        $this->assertSame('Corrected address', $correction->corrected_snapshot['delivery_address']);
        $this->assertNotNull($correction->resubmitted_at);
    }

    /**
     * Closure gate §14: a second return/correct cycle on the same order must
     * not overwrite or erase the first one's history.
     */
    public function test_multiple_return_and_correction_cycles_each_keep_their_own_history(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->pendingApprovalOrder($requester, 100000);
        $reviewer = $this->user(Permissions::PROCUREMENT_ORDERS_APPROVE);

        $order->returnForCorrection($reviewer->id, 'First problem: wrong address');
        Sanctum::actingAs($requester);
        $this->putJson("/api/procurement-stores/purchase-orders/{$order->id}", [
            'delivery_address' => 'First correction',
        ])->assertOk();
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/resubmit")->assertOk();

        $order->refresh()->returnForCorrection($reviewer->id, 'Second problem: wrong description too');
        $this->putJson("/api/procurement-stores/purchase-orders/{$order->id}", [
            'description' => 'Second correction',
        ])->assertOk();
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/resubmit")->assertOk();

        $history = $order->corrections()->get();
        $this->assertCount(2, $history);
        $second = $history->firstWhere('correction_number', 2);
        $first = $history->firstWhere('correction_number', 1);
        $this->assertSame('First problem: wrong address', $first->return_reason);
        $this->assertSame('First correction', $first->corrected_snapshot['delivery_address']);
        $this->assertSame('Second problem: wrong description too', $second->return_reason);
        $this->assertSame('Second correction', $second->corrected_snapshot['description']);
        // The first cycle's record is untouched by the second.
        $this->assertSame('First correction', $first->corrected_snapshot['delivery_address']);
    }

    public function test_a_reviewer_without_the_create_permission_cannot_correct_a_returned_order(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->pendingApprovalOrder($requester, 100000);
        $reviewer = $this->user(Permissions::PROCUREMENT_ORDERS_APPROVE);
        $order->returnForCorrection($reviewer->id, 'Wrong quantity');

        Sanctum::actingAs($reviewer);
        $this->putJson("/api/procurement-stores/purchase-orders/{$order->id}", [
            'delivery_address' => 'Should not be allowed',
        ])->assertStatus(403);
    }

    public function test_resubmitting_restarts_approval_and_re_evaluates_the_senior_threshold(): void
    {
        $this->approveThreshold(1000000);
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->pendingApprovalOrder($requester, 500000);
        $this->assertFalse($order->senior_approval_required);

        $order->returnForCorrection($this->user(Permissions::PROCUREMENT_ORDERS_APPROVE)->id, 'Price needs updating');

        Sanctum::actingAs($requester);
        $this->putJson("/api/procurement-stores/purchase-orders/{$order->id}", [
            'items' => [[
                'material_id' => null, 'custom_description' => 'Materials',
                'quantity' => 1, 'unit_price' => 1500000,
            ]],
        ])->assertOk();

        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/resubmit")->assertOk();
        $this->assertTrue($order->fresh()->senior_approval_required);
    }

    public function test_only_a_returned_order_can_be_resubmitted(): void
    {
        $requester = $this->user(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->pendingApprovalOrder($requester, 100000);

        Sanctum::actingAs($requester);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/resubmit")->assertStatus(422);
    }
}
