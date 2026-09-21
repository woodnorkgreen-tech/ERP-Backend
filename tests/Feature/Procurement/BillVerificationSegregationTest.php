<?php

namespace Tests\Feature\Procurement;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Verifying a supplier invoice is what moves a liability from "accrued
 * against a receipt" to "owed and payable" — the exact decision a second
 * person is supposed to check. Every other approval gate in this codebase
 * already refuses to let the person who raised a thing also sign it off (see
 * SelfApproval's own docblock); bill verification never joined that list.
 *
 * These pin the segregation-of-duties guard specifically — the three-way
 * match itself is SupplierPaymentGateTest's job, so every fixture here is
 * built to pass that match cleanly and isolate the one thing under test.
 */
class BillVerificationSegregationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);

        Permission::findOrCreate(Permissions::APPROVALS_SELF_APPROVE, 'web');
        Role::findOrCreate('Accounts', 'web');
    }

    private function accountsUser(): User
    {
        $user = User::create([
            'name' => 'Accounts Clerk', 'email' => uniqid('accounts_').'@test.local',
            'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $user->assignRole('Accounts');

        return $user;
    }

    /** A bill that cleanly passes the three-way match, recorded by the given user. */
    private function matchedBill(User $recordedBy): Bill
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Timber & Board Ltd', 'contact_person' => 'Contact',
            'phone' => '0700000004', 'email' => uniqid().'@test.local',
            'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $recordedBy->id,
        ]);

        $order = PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $supplier->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store', 'description' => 'Board stock',
            'total_amount' => 50000, 'status' => 'approved', 'user_id' => $recordedBy->id,
            'approved_at' => now(), 'approved_by' => $recordedBy->id,
        ]);

        $orderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'custom_description' => 'MDF 18mm sheet',
            'quantity' => 10, 'unit_price' => 5000, 'total' => 50000,
        ]);

        $note = GoodsReceiptNote::create([
            'grn_number' => 'GRN-'.uniqid(), 'date' => now()->toDateString(),
            'purchase_order_id' => $order->id, 'batch_number' => 'BATCH-'.uniqid(),
            'store_location' => 'Karen Village Store', 'quality_check' => 'pass',
            'store_status' => 'confirmed', 'received_by' => $recordedBy->id,
        ]);

        GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id, 'purchase_order_item_id' => $orderItem->id,
            'ordered_quantity' => 10, 'received_quantity' => 10, 'condition' => 'good',
            'accepted' => true, 'store_status' => 'confirmed', 'stock_status' => 'posted',
            'unit_price' => 5000,
        ]);

        return Bill::create([
            'bill_number' => Bill::generateBillNumber(), 'purchase_order_id' => $order->id,
            'supplier_id' => $supplier->id, 'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(), 'amount' => 50000,
            'status' => 'pending', 'supplier_invoice_number' => 'SINV-'.uniqid(),
            'user_id' => $recordedBy->id,
        ]);
    }

    public function test_the_person_who_recorded_the_bill_cannot_verify_it_themselves(): void
    {
        $clerk = $this->accountsUser();
        $bill = $this->matchedBill($clerk);

        Sanctum::actingAs($clerk);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")
            ->assertStatus(422);

        $this->assertNull($bill->fresh()->verified_at);
    }

    public function test_a_different_accounts_user_may_verify_it(): void
    {
        $recorder = $this->accountsUser();
        $bill = $this->matchedBill($recorder);

        $verifier = $this->accountsUser();
        Sanctum::actingAs($verifier);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")
            ->assertOk();

        $bill->refresh();
        $this->assertNotNull($bill->verified_at);
        $this->assertSame($verifier->id, $bill->verified_by);
    }

    public function test_the_self_approve_permission_lifts_the_block(): void
    {
        $clerk = $this->accountsUser();
        $clerk->givePermissionTo(Permissions::APPROVALS_SELF_APPROVE);
        $bill = $this->matchedBill($clerk);

        Sanctum::actingAs($clerk);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")
            ->assertOk();

        $this->assertNotNull($bill->fresh()->verified_at);
    }

    /** A bill recorded by nobody in particular (a legacy/system-imported row) is not a self-verification. */
    public function test_a_bill_with_no_recorded_creator_is_not_blocked(): void
    {
        $clerk = $this->accountsUser();
        $bill = $this->matchedBill($clerk);
        $bill->forceFill(['user_id' => null])->save();

        Sanctum::actingAs($clerk);

        $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify")
            ->assertOk();
    }
}
