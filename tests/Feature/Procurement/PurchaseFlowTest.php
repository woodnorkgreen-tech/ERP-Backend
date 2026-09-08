<?php

namespace Tests\Feature\Procurement;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\HR\Models\Department;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Models\RequisitionItem;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * How many moves it takes to buy one thing.
 *
 * Buying used to be six screens and two approval cycles: raise a requisition as
 * a draft, go somewhere else to submit it, have it approved, go somewhere else
 * to raise the order, submit THAT for approval, have the same organisation
 * approve the same spend again. Every step in that chain existed; none of them
 * decided anything the previous step had not already decided.
 *
 * This pins the shorter route. Three claims:
 *   1. asking is one action — a requisition reaches the approver on save;
 *   2. raising an order places it, when the requisition already covers it;
 *   3. who may approve is a permission, not a hard-coded list of role names.
 *
 * @see \App\Modules\ProcurementStores\Services\PurchaseApprovalPolicy
 * @see \App\Modules\ProcurementStores\Policies\PurchasePolicy
 */
class PurchaseFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;
    private Supplier $supplier;
    private Department $department;
    private ExpenseCode $officeCode;

    protected function setUp(): void
    {
        parent::setUp();

        // Placing an order posts its committed cost, which needs a period and a
        // chart to land in — the same groundwork the accrual tests lay.
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceSettingsSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder::class);

        Role::findOrCreate('Super Admin', 'web');
        $this->approver = User::factory()->create(['is_active' => true]);
        $this->approver->assignRole('Super Admin');
        Sanctum::actingAs($this->approver);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Timber Ltd', 'contact_person' => 'C',
            'phone' => '0700000000', 'email' => uniqid() . '@t.local', 'address' => 'Nairobi',
            'payment_terms' => '30 days', 'status' => 'Active', 'user_id' => $this->approver->id,
        ]);

        $this->department = Department::create(['name' => 'Operations']);

        // Approval refuses an uncoded line, and an office requisition may not
        // carry a code that demands a job. Looked up by code rather than id so
        // the fixture breaks loudly if the catalogue retires it.
        $this->officeCode = ExpenseCode::where('code', 'OE-COM-001')->sole();
    }

    /** The smallest payload the create endpoint accepts. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'date' => now()->toDateString(),
            'requested_by_type' => 'office',
            'department_id' => $this->department->id,
            'urgency' => 'normal',
            'items' => [[
                'quantity' => 2,
                'unit_price' => 250,
                'purpose' => 'office_use',
                'custom_description' => 'Ream of paper',
            ]],
        ], $overrides);
    }

    // ---- 1. Asking is one action ----

    public function test_raising_a_requisition_sends_it_for_approval(): void
    {
        $response = $this->postJson('/api/procurement-stores/requisitions', $this->payload())
            ->assertSuccessful();

        $requisition = Requisition::findOrFail($response->json('data.id'));

        $this->assertSame('pending_approval', $requisition->status);
        $this->assertNotNull($requisition->submitted_at, 'It should already be in the approver queue.');
    }

    /**
     * The draft is not removed, only demoted from "what always happens" to
     * "what you ask for". Somebody half-way through a long list still needs it.
     */
    public function test_a_requester_may_still_keep_it_back_as_a_draft(): void
    {
        $response = $this->postJson(
            '/api/procurement-stores/requisitions',
            $this->payload(['save_as_draft' => true]),
        )->assertSuccessful();

        $requisition = Requisition::findOrFail($response->json('data.id'));

        $this->assertSame('draft', $requisition->status);
        $this->assertNull($requisition->submitted_at);
    }

    public function test_a_draft_can_still_be_submitted_the_long_way(): void
    {
        $response = $this->postJson(
            '/api/procurement-stores/requisitions',
            $this->payload(['save_as_draft' => true]),
        )->assertSuccessful();

        $id = $response->json('data.id');

        $this->postJson("/api/procurement-stores/requisitions/{$id}/submit")->assertSuccessful();

        $this->assertSame('pending_approval', Requisition::findOrFail($id)->status);
    }

    // ---- 2. Raising an order places it ----

    private function approvedRequisition(float $unitPrice = 500, float $quantity = 2): Requisition
    {
        $requisition = Requisition::create([
            'requisition_number' => Requisition::generateRequisitionNumber(),
            'date' => now()->toDateString(),
            'requested_by_type' => 'office',
            'department_id' => $this->department->id,
            'urgency' => 'normal',
            'status' => 'approved',
            'total_amount' => $unitPrice * $quantity,
            'submitted_at' => now()->subDay(),
            'approved_at' => now(),
            'approved_by' => $this->approver->id,
            'user_id' => $this->approver->id,
        ]);

        RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'supplier_id' => $this->supplier->id,
            'expense_code_id' => $this->officeCode->id,
            'custom_description' => 'Ream of paper',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $unitPrice * $quantity,
            'purpose' => 'office_use',
        ]);

        return $requisition->fresh('items');
    }

    private function raiseOrder(Requisition $requisition)
    {
        return $this->postJson('/api/procurement-stores/purchase-orders/store-linked', [
            'requisition_id' => $requisition->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Workshop, Nairobi',
        ]);
    }

    public function test_raising_an_order_from_an_approved_requisition_issues_it_at_once(): void
    {
        $requisition = $this->approvedRequisition();

        $response = $this->raiseOrder($requisition)->assertSuccessful();

        $order = PurchaseOrder::where('requisition_id', $requisition->id)->sole();

        // One call. No submit step, no second approval screen.
        $this->assertSame('approved', $order->status);
        $this->assertNotNull($order->approved_at);
        $this->assertTrue($response->json("approvals.{$order->id}.auto"));
    }

    /**
     * The shortcut is cover, not size. An order that outgrows the requisition
     * behind it is a new decision and still waits for a person — here the unit
     * price was raised after approval.
     */
    public function test_an_order_that_outgrew_its_requisition_still_waits_for_a_person(): void
    {
        $requisition = $this->approvedRequisition();
        $requisition->items()->update(['unit_price' => 900, 'total' => 1800]);

        $response = $this->raiseOrder($requisition)->assertSuccessful();

        $order = PurchaseOrder::where('requisition_id', $requisition->id)->sole();

        $this->assertSame('pending_approval', $order->status);
        $this->assertFalse($response->json("approvals.{$order->id}.auto"));
        $this->assertStringContainsString('needs approving', $response->json("approvals.{$order->id}.reason"));
    }

    // ---- 3. Approval is a permission ----

    private function userWith(?string $permission): User
    {
        $user = User::factory()->create(['is_active' => true]);

        if ($permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    public function test_the_approval_permission_is_enough_without_any_of_the_old_roles(): void
    {
        $requisition = $this->approvedRequisition();
        $requisition->update(['status' => 'pending_approval', 'approved_at' => null, 'approved_by' => null]);

        Sanctum::actingAs($this->userWith(Permissions::PROCUREMENT_REQUISITIONS_APPROVE));

        $this->postJson("/api/procurement-stores/requisitions/{$requisition->id}/approve")
            ->assertSuccessful();

        $this->assertSame('approved', $requisition->fresh()->status);
    }

    public function test_a_user_without_the_permission_cannot_approve(): void
    {
        $requisition = $this->approvedRequisition();
        $requisition->update(['status' => 'pending_approval', 'approved_at' => null, 'approved_by' => null]);

        Sanctum::actingAs($this->userWith(null));

        $this->postJson("/api/procurement-stores/requisitions/{$requisition->id}/approve")
            ->assertForbidden();

        $this->assertSame('pending_approval', $requisition->fresh()->status);
    }

    // ---- The refusal is visible before the button is pressed ----

    /**
     * An office-only category on a project requisition is the real case that
     * prompted this: the approver pressed Approve, got a 422, and the register
     * had shown the row as ready. The register now carries the same sentence
     * the endpoint would answer with.
     */
    public function test_the_register_says_why_a_requisition_cannot_be_approved(): void
    {
        $requisition = $this->approvedRequisition();
        $requisition->update([
            'status' => 'pending_approval',
            'requested_by_type' => 'project',
            'approved_at' => null,
            'approved_by' => null,
        ]);

        // OE-COM-001 is job_id_rule = not_allowed, so it may never be charged
        // to a job — legal on the office requisition it was created as, illegal
        // the moment the requisition became a project one.
        $response = $this->getJson('/api/procurement-stores/requisitions')->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $requisition->id);

        $this->assertNotNull($row['approval_blocker'] ?? null, 'The register should name the blocker.');
        $this->assertStringContainsString('cannot be charged to a job', $row['approval_blocker']);

        // ...and the endpoint refuses with that same sentence.
        $this->postJson("/api/procurement-stores/requisitions/{$requisition->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error', $row['approval_blocker'])
            ->assertJsonPath('code', 'EXPENSE_CODE_JOB_RULE');
    }

    public function test_a_requisition_with_nothing_wrong_carries_no_blocker(): void
    {
        $requisition = $this->approvedRequisition();
        $requisition->update(['status' => 'pending_approval', 'approved_at' => null, 'approved_by' => null]);

        $response = $this->getJson('/api/procurement-stores/requisitions')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $requisition->id);

        $this->assertNull($row['approval_blocker'] ?? null);
    }

    /** Nobody loses access before `permissions:sync` has run on an environment. */
    public function test_the_legacy_accounts_role_still_approves(): void
    {
        $requisition = $this->approvedRequisition();
        $requisition->update(['status' => 'pending_approval', 'approved_at' => null, 'approved_by' => null]);

        Role::findOrCreate('Accounts', 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Accounts');
        Sanctum::actingAs($user);

        $this->postJson("/api/procurement-stores/requisitions/{$requisition->id}/approve")
            ->assertSuccessful();

        $this->assertSame('approved', $requisition->fresh()->status);
    }
}
