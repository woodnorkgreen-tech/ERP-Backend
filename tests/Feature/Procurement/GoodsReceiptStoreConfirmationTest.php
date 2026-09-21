<?php

namespace Tests\Feature\Procurement;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialUomConversion;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\GoodsReceiptInspection;
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

    /**
     * GoodsReceiptInspectionController's own guarantee — its response even
     * says so — is "only the accepted quantity can enter available stock."
     * A batch/expiry/serial/board-tracked material inspected with a partial
     * acceptance (some rejected or quarantined) still routes to
     * `awaiting_stores_details` whenever any quantity was accepted at all,
     * so this exact combination — an inspection record present, and it
     * disagreeing with received_quantity — is reachable, not hypothetical.
     */
    public function test_confirming_credits_only_the_inspection_approved_quantity_not_the_full_delivery(): void
    {
        $material = LibraryMaterial::create([
            'material_code' => 'MAT-'.uniqid(), 'material_name' => 'MDF 18mm sheet',
            'item_status' => 'Active', 'material_type' => 'consumable', 'unit_of_measure' => 'pcs',
        ]);

        // Delivered 5, only 3 accepted at inspection — 2 quarantined.
        $line = $this->pendingLine($material->id, receivedQuantity: 5);
        GoodsReceiptInspection::create([
            'goods_receipt_note_item_id' => $line->id,
            'inspected_quantity' => 5, 'accepted_quantity' => 3, 'rejected_quantity' => 0,
            'quarantined_quantity' => 2, 'outcome' => 'accepted_with_conditions', 'status' => 'resolved',
            'findings' => 'Two sheets water-damaged in transit, quarantined for supplier credit.',
            'inspected_by' => $this->stores->id, 'inspected_at' => now(),
        ]);

        $this->postJson("/api/procurement-stores/goods-receipt-note-items/{$line->id}/confirm", [
            'material_id' => $material->id,
            'unit_price' => 4800,
        ])->assertOk();

        $this->assertSame(
            3.0,
            (float) DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand'),
            'The quarantined 2 must not reach available stock just because Stores confirmed the line.',
        );
        $this->assertSame(3.0, (float) $line->fresh()->stock_quantity);
    }

    /**
     * Two concurrent confirms cannot both pass the store_status guard before
     * either has written it — a double-click or two Stores staff acting on
     * the same line at once must not credit stock twice. A synchronous test
     * cannot force a true race, but it does prove the guard now lives inside
     * the locked, transacted section rather than as a pre-check outside it:
     * a second call, sequenced after the first has committed, is refused.
     */
    public function test_confirming_the_same_line_twice_only_credits_stock_once(): void
    {
        $material = LibraryMaterial::create([
            'material_code' => 'MAT-'.uniqid(), 'material_name' => 'MDF 18mm sheet',
            'item_status' => 'Active', 'material_type' => 'consumable', 'unit_of_measure' => 'pcs',
        ]);

        $line = $this->pendingLine($material->id);

        $this->postJson("/api/procurement-stores/goods-receipt-note-items/{$line->id}/confirm", [
            'material_id' => $material->id, 'unit_price' => 4800,
        ])->assertOk();

        $this->postJson("/api/procurement-stores/goods-receipt-note-items/{$line->id}/confirm", [
            'material_id' => $material->id, 'unit_price' => 4800,
        ])->assertStatus(422);

        $this->assertSame(5.0, (float) DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand'));
        $this->assertSame(1, (int) DB::table('inventory_logs')->where('material_id', $material->id)->count());
    }

    /**
     * StockMovementPoster::postReceipt() prices a received board at the price
     * just paid for it, and refuses the receipt outright when no price is
     * available anywhere. confirmItem()'s equivalent — creditStockForAcceptedItem()
     * — used to skip that entirely: it never forwarded the unit price Stores
     * had just entered into BoardRegistrationService::createBoardRecords(),
     * so every board silently fell back to the catalogue's standing cost
     * instead of what this specific delivery cost.
     */
    public function test_confirming_a_board_tracked_material_values_boards_at_the_confirmed_price(): void
    {
        $material = LibraryMaterial::create([
            'material_code' => 'MAT-'.uniqid(), 'material_name' => 'MDF 18mm Sheet',
            'material_type' => 'reusable', 'tracking_mode' => 'dimension_piece',
            'issue_disposition' => 'recoverable_remainder', 'unit_of_measure' => 'sheet',
            'item_status' => 'Active', 'unit_cost' => 3000.00,
        ]);

        $line = $this->pendingLine($material->id, receivedQuantity: 2);

        $this->postJson("/api/procurement-stores/goods-receipt-note-items/{$line->id}/confirm", [
            'material_id' => $material->id,
            'unit_price' => 4500,
        ])->assertOk();

        $boards = Board::where('library_material_id', $material->id)->get();
        $this->assertCount(2, $boards);
        $this->assertTrue(
            $boards->every(fn (Board $board) => (float) $board->current_value === 4500.0),
            'Boards confirmed from the Stores queue must be valued at this delivery\'s price, not the catalogue\'s standing cost.',
        );
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
