<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Receiving is immutable in normal operation — a mistake is corrected with a
 * return, and `update()` refuses outright. Deletion exists for the one case
 * that cannot be corrected that way: clearing test receiving before an opening
 * inventory, which StoresResetService blocks on until the receipts are gone.
 *
 * Because it hard-deletes history, it is Super Admin only and refuses anything
 * whose removal would strand a fact somewhere else.
 */
class GoodsReceiptDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private PurchaseOrder $order;
    private PurchaseOrderItem $orderItem;
    private int $materialId;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('Stores', 'web');

        $this->superAdmin = $this->userWithRole('Super Admin');

        $supplier = Supplier::create([
            'supplier_name' => 'Timber & Board Ltd', 'contact_person' => 'Contact',
            'phone' => '0700000002', 'email' => uniqid().'@test.local',
            'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $this->superAdmin->id,
        ]);

        $this->order = PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $supplier->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store', 'description' => 'Board stock',
            'total_amount' => 25000, 'status' => 'approved', 'user_id' => $this->superAdmin->id,
        ]);

        $this->orderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $this->order->id, 'custom_description' => 'MDF 18mm',
            'quantity' => 5, 'unit_price' => 5000, 'total' => 25000,
        ]);

        // stocks.material_id is a real foreign key into the catalogue.
        $this->materialId = (int) DB::table('library_materials')->insertGetId([
            'material_code' => 'MAT-'.uniqid(), 'material_name' => 'MDF 18mm sheet',
            'item_status' => 'active', 'is_hazardous' => 0, 'is_serialized' => 0,
            'is_batch_controlled' => 0, 'is_expiry_controlled' => 0,
            'is_project_chargeable' => 1, 'unit_cost' => 5000, 'valuation_method' => 'average',
            'revision_version' => '1', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => $role, 'email' => uniqid().'@test.local',
            'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** A receipt that put `$onHand` of the catalogue material on the shelf. */
    private function receipt(string $stockStatus = 'posted', float $onHand = 5.0): GoodsReceiptNote
    {
        $note = GoodsReceiptNote::create([
            'grn_number' => 'GRN-'.uniqid(), 'date' => now()->toDateString(),
            'purchase_order_id' => $this->order->id, 'batch_number' => 'BATCH-'.uniqid(),
            'store_location' => 'Karen Village Store', 'quality_check' => 'pass',
            'store_status' => 'confirmed', 'received_by' => $this->superAdmin->id,
        ]);

        GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id, 'purchase_order_item_id' => $this->orderItem->id,
            'material_id' => $this->materialId, 'ordered_quantity' => 5, 'received_quantity' => 5,
            'condition' => 'good', 'accepted' => true, 'store_status' => 'confirmed',
            'stock_status' => $stockStatus, 'unit_price' => 5000,
        ]);

        DB::table('stocks')->insert([
            'material_id' => $this->materialId, 'quantity_on_hand' => $onHand, 'quantity_reserved' => 0,
            'min_stock_level' => 0, 'warehouse_code' => 'MAIN', 'tracking_mode' => 'bulk',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $note;
    }

    private function deleteReceipt(GoodsReceiptNote $note)
    {
        return $this->deleteJson("/api/procurement-stores/goods-receipt-notes/{$note->id}");
    }

    public function test_only_a_super_admin_may_delete_receiving_history(): void
    {
        $note = $this->receipt(onHand: 0);
        Sanctum::actingAs($this->userWithRole('Stores'));

        $this->deleteReceipt($note)->assertStatus(403);
        $this->assertDatabaseHas('goods_receipt_notes', ['id' => $note->id]);
    }

    public function test_a_receipt_whose_stock_is_still_on_the_shelf_is_refused(): void
    {
        $note = $this->receipt(onHand: 5);
        Sanctum::actingAs($this->superAdmin);

        $this->deleteReceipt($note)->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'still has stock on the shelf'));
    }

    /**
     * `stock_status` is never set back when stock is adjusted out, so reading
     * the flag alone refused receipts whose inventory had already been reversed
     * — the exact state somebody following the refusal's own advice ends up in.
     */
    public function test_a_receipt_whose_stock_was_reversed_can_be_deleted_with_its_lines(): void
    {
        $note = $this->receipt(stockStatus: 'posted', onHand: 0);
        Sanctum::actingAs($this->superAdmin);

        $this->deleteReceipt($note)->assertOk();

        $this->assertDatabaseMissing('goods_receipt_notes', ['id' => $note->id]);
        // No foreign key and no soft delete, so the lines only go if we remove them.
        $this->assertSame(0, GoodsReceiptNoteItem::where('goods_receipt_note_id', $note->id)->count());
        $this->assertDatabaseHas('governance_audit_logs', ['gate_type' => 'goods_receipt_deleted']);
    }

    public function test_a_receipt_backing_a_matched_bill_is_refused(): void
    {
        $note = $this->receipt(onHand: 0);
        Bill::create([
            'bill_number' => 'BILL-'.uniqid(), 'purchase_order_id' => $this->order->id,
            'supplier_id' => $this->order->supplier_id, 'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(), 'amount' => 25000,
            'status' => 'pending', 'supplier_invoice_number' => 'SINV-1',
            'verified_at' => now(), 'user_id' => $this->superAdmin->id,
        ]);
        Sanctum::actingAs($this->superAdmin);

        $this->deleteReceipt($note)->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'evidence that payment rests on'));
    }
}
