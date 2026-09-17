<?php

namespace Tests\Feature\Procurement;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialUomConversion;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * confirmItem() is the Stores-module "Confirm into stock" action — the one
 * place a matched-but-unconfirmed GRN line actually becomes a stock record.
 * It used to under-record silently: it never passed the buying-unit
 * conversion or receipt cost through to adjustStock(), and it never wrote
 * stock_status/stock_quantity/inventory_log_id back onto the line, so a
 * "confirmed" line still looked unfinished everywhere else that reads those
 * columns. Registering a brand-new material from this screen was worse:
 * LibraryMaterial::create() left item_status at its 'Under Review' default,
 * which adjustStock() then refused outright.
 */
class GoodsReceiptStoreConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $stores;
    private PurchaseOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stores = User::create([
            'name' => 'Storekeeper', 'email' => uniqid('stores_').'@test.local',
            'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $this->stores->givePermissionTo(Permission::findOrCreate(Permissions::STORES_MANAGE, 'web'));
        Sanctum::actingAs($this->stores);

        $supplier = Supplier::create([
            'supplier_name' => 'Timber & Board Ltd', 'contact_person' => 'Contact',
            'phone' => '0700000002', 'email' => uniqid().'@test.local',
            'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $this->stores->id,
        ]);

        $this->order = PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $supplier->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store', 'description' => 'Board stock',
            'total_amount' => 25000, 'status' => 'approved', 'user_id' => $this->stores->id,
        ]);
    }

    /** A GRN line dock-accepted but not yet confirmed by Stores. */
    private function pendingLine(?int $materialId, float $receivedQuantity = 5, string $stockStatus = 'awaiting_stores_details'): GoodsReceiptNoteItem
    {
        $orderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $this->order->id, 'custom_description' => 'MDF 18mm',
            'quantity' => 5, 'unit_price' => 5000, 'total' => 25000,
        ]);

        $note = GoodsReceiptNote::create([
            'grn_number' => 'GRN-'.uniqid(), 'date' => now()->toDateString(),
            'purchase_order_id' => $this->order->id, 'batch_number' => 'BATCH-'.uniqid(),
            'store_location' => 'Karen Village Store', 'quality_check' => 'pass',
            'store_status' => 'pending_confirmation', 'received_by' => $this->stores->id,
        ]);

        return GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id, 'purchase_order_item_id' => $orderItem->id,
            'material_id' => $materialId, 'ordered_quantity' => 5, 'received_quantity' => $receivedQuantity,
            'condition' => 'good', 'accepted' => true, 'store_status' => 'pending',
            'stock_status' => $stockStatus,
        ]);
    }

    public function test_confirming_a_matched_material_produces_a_complete_stock_record(): void
    {
        $material = LibraryMaterial::create([
            'material_code' => 'MAT-'.uniqid(), 'material_name' => 'MDF 18mm sheet',
            'item_status' => 'Active', 'material_type' => 'consumable', 'unit_of_measure' => 'pcs',
        ]);

        $line = $this->pendingLine($material->id);

        $this->postJson("/api/procurement-stores/goods-receipt-note-items/{$line->id}/confirm", [
            'material_id' => $material->id,
            'unit_price' => 4800,
        ])->assertOk();

        $line->refresh();
        $this->assertSame('confirmed', $line->store_status);
        $this->assertSame('posted', $line->stock_status);
        $this->assertNotNull($line->inventory_log_id);
        $this->assertSame(5.0, (float) $line->stock_quantity);
        $this->assertSame(5.0, (float) DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand'));
        $this->assertSame(4800.0, (float) $material->fresh()->unit_cost, 'Weighted-average cost must move on a store-confirmed receipt too.');
    }

    public function test_confirming_applies_the_buying_unit_conversion(): void
    {
        $boxId = DB::table('units_of_measure')->where('code', 'box')->value('id');
        $pcsId = DB::table('units_of_measure')->where('code', 'pcs')->value('id');

        $material = LibraryMaterial::create([
            'material_code' => 'MAT-'.uniqid(), 'material_name' => 'Wood screws (box of 100)',
            'item_status' => 'Active', 'material_type' => 'consumable', 'unit_of_measure' => 'pcs',
            'base_uom_id' => $pcsId, 'purchase_uom_id' => $boxId,
        ]);
        MaterialUomConversion::create(['material_id' => $material->id, 'from_uom_id' => $boxId, 'to_uom_id' => $pcsId, 'factor' => 100]);

        $line = $this->pendingLine($material->id, receivedQuantity: 3);

        $this->postJson("/api/procurement-stores/goods-receipt-note-items/{$line->id}/confirm", [
            'material_id' => $material->id,
            'unit_price' => 250,
        ])->assertOk();

        // 3 boxes of 100 must credit 300 pieces, not 3.
        $this->assertSame(300.0, (float) DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand'));
        $this->assertSame(300.0, (float) $line->fresh()->stock_quantity);
    }

    public function test_confirming_is_refused_without_a_configured_conversion(): void
    {
        $boxId = DB::table('units_of_measure')->where('code', 'box')->value('id');
        $pcsId = DB::table('units_of_measure')->where('code', 'pcs')->value('id');

        $material = LibraryMaterial::create([
            'material_code' => 'MAT-'.uniqid(), 'material_name' => 'Wood screws, unit unset',
            'item_status' => 'Active', 'material_type' => 'consumable', 'unit_of_measure' => 'pcs',
            'base_uom_id' => $pcsId, 'purchase_uom_id' => $boxId,
        ]);
        // No MaterialUomConversion row — the buying unit cannot be converted.

        $line = $this->pendingLine($material->id, stockStatus: 'awaiting_unit_setup');

        $this->postJson("/api/procurement-stores/goods-receipt-note-items/{$line->id}/confirm", [
            'material_id' => $material->id,
            'unit_price' => 250,
        ])->assertStatus(422);

        $this->assertSame(0, (int) DB::table('stocks')->where('material_id', $material->id)->count(), 'Nothing should be credited when the unit cannot be converted.');
        $this->assertSame('pending', $line->fresh()->store_status);
    }

    public function test_confirming_is_refused_while_a_line_is_awaiting_inspection(): void
    {
        $material = LibraryMaterial::create([
            'material_code' => 'MAT-'.uniqid(), 'material_name' => 'MDF 18mm sheet',
            'item_status' => 'Active', 'material_type' => 'consumable', 'unit_of_measure' => 'pcs',
        ]);

        $line = $this->pendingLine($material->id, stockStatus: 'awaiting_inspection');

        $this->postJson("/api/procurement-stores/goods-receipt-note-items/{$line->id}/confirm", [
            'material_id' => $material->id,
            'unit_price' => 4800,
        ])->assertStatus(422);

        $this->assertSame('pending', $line->fresh()->store_status);
        $this->assertSame(0, (int) DB::table('stocks')->where('material_id', $material->id)->count());
    }

    public function test_registering_a_new_material_from_the_confirm_screen_credits_stock(): void
    {
        $line = $this->pendingLine(null, stockStatus: 'not_stocked');

        $this->postJson("/api/procurement-stores/goods-receipt-note-items/{$line->id}/confirm", [
            'new_material' => [
                'material_name' => 'Contact Adhesive 1L',
                'unit_of_measure' => 'l',
                'material_type' => 'consumable',
                'category' => 'Adhesives',
            ],
            'unit_price' => 900,
        ])->assertOk();

        $line->refresh();
        $this->assertNotNull($line->material_id);
        $material = LibraryMaterial::find($line->material_id);
        $this->assertSame('Active', $material->item_status, 'A material registered from the receipt screen must be immediately receivable, not left at Under Review.');
        $this->assertSame('posted', $line->stock_status);
        $this->assertSame(5.0, (float) DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand'));
    }
}
