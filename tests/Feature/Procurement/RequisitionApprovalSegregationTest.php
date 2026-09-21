<?php

namespace Tests\Feature\Procurement;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Models\RequisitionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The first of three approval gates in the buying chain (requisition, order,
 * bill) — all three lacked a segregation-of-duties check equally; see
 * PurchaseOrderApprovalSegregationTest and BillVerificationSegregationTest
 * for the other two. Whoever raises a requisition does not also approve it,
 * unless explicitly granted the exception every other approval gate in this
 * codebase already respects.
 */
class RequisitionApprovalSegregationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceSettingsSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder::class);
        Permission::findOrCreate(Permissions::PROCUREMENT_REQUISITIONS_APPROVE, 'web');
        Permission::findOrCreate(Permissions::APPROVALS_SELF_APPROVE, 'web');
    }

    private function approver(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::PROCUREMENT_REQUISITIONS_APPROVE);

        return $user;
    }

    /** A requisition raised by the given user, awaiting approval, with one fully-coded line. */
    private function pendingRequisition(User $raisedBy): Requisition
    {
        $department = Department::firstOrCreate(['name' => 'Operations'], ['name' => 'Operations']);
        $officeCode = \App\Modules\Finance\CostCollector\Models\ExpenseCode::where('code', 'OE-OFF-001')->sole();

        $requisition = Requisition::create([
            'requisition_number' => Requisition::generateRequisitionNumber(),
            'date' => now()->toDateString(),
            'requested_by_type' => 'office',
            'department_id' => $department->id,
            'urgency' => 'normal',
            'status' => 'pending_approval',
            'total_amount' => 1000,
            'submitted_at' => now(),
            'user_id' => $raisedBy->id,
        ]);

        RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'expense_code_id' => $officeCode->id,
            'custom_description' => 'Ream of paper',
            'quantity' => 2, 'unit_price' => 500, 'total' => 1000,
            'purpose' => 'office_use',
        ]);

        return $requisition->fresh('items');
    }

    public function test_the_person_who_raised_the_requisition_cannot_approve_it_themselves(): void
    {
        $requester = $this->approver();
        $requisition = $this->pendingRequisition($requester);

        Sanctum::actingAs($requester);

        $this->postJson("/api/procurement-stores/requisitions/{$requisition->id}/approve")
            ->assertStatus(422);

        $this->assertSame('pending_approval', $requisition->fresh()->status);
    }

    public function test_a_different_approver_may_approve_it(): void
    {
        $requester = $this->approver();
        $requisition = $this->pendingRequisition($requester);

        $approver = $this->approver();
        Sanctum::actingAs($approver);

        $this->postJson("/api/procurement-stores/requisitions/{$requisition->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $requisition->fresh()->status);
    }

    public function test_the_self_approve_permission_lifts_the_block(): void
    {
        $requester = $this->approver();
        $requester->givePermissionTo(Permissions::APPROVALS_SELF_APPROVE);
        $requisition = $this->pendingRequisition($requester);

        Sanctum::actingAs($requester);

        $this->postJson("/api/procurement-stores/requisitions/{$requisition->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $requisition->fresh()->status);
    }
}
