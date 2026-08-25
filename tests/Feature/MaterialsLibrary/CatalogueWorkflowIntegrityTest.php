<?php

namespace Tests\Feature\MaterialsLibrary;

use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\MaterialItemType;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use App\Constants\Permissions;

/**
 * Draft-first creation only works if every module downstream agrees on what a
 * draft may take part in. The rule these tests pin:
 *
 *   planning may reference an unfinished item; moving stock may not.
 *
 * Corrections are the deliberate exception — a return or a write-off must never
 * be trapped inside an item whose classification changed after it was issued.
 */
class CatalogueWorkflowIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private MaterialCategory $leaf;
    private MaterialItemType $stockType;
    private UnitOfMeasure $pcs;
    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Stores', 'Production', 'Manager', 'Super Admin'] as $role) {
            Role::findOrCreate($role);
        }
        Role::findByName('Stores')->givePermissionTo([
            Permissions::MATERIALS_LIBRARY_VIEW, Permissions::MATERIALS_LIBRARY_MANAGE,
        ]);

        $this->stockType = MaterialItemType::create([
            'code' => 'ST'.random_int(1000, 9999), 'name' => 'Stock Material',
            'default_issue_disposition' => 'consumed', 'default_tracking_mode' => 'bulk_quantity',
            'is_stock_item' => true, 'is_active' => true,
        ]);
        $this->pcs = UnitOfMeasure::firstOrCreate(['code' => 'pcs'], ['name' => 'Piece', 'dimension' => 'count', 'is_active' => true]);

        $group = MaterialCategory::create([
            'name' => 'Consumables '.uniqid(), 'code' => 'CON'.random_int(100, 999),
            'item_type_id' => $this->stockType->id, 'is_active' => true, 'is_selectable' => false,
        ]);
        $this->leaf = MaterialCategory::create([
            'name' => 'Adhesives', 'code' => 'ADH'.random_int(100, 999), 'parent_id' => $group->id,
            'item_type_id' => $this->stockType->id, 'is_active' => true, 'is_selectable' => true,
        ]);

        $this->storekeeper = User::factory()->create();
        $this->storekeeper->assignRole('Stores');
        Sanctum::actingAs($this->storekeeper->fresh());
    }

    private function material(string $status = 'Active'): LibraryMaterial
    {
        return LibraryMaterial::create([
            'material_name' => 'Contact Adhesive', 'material_code' => 'ADH-'.uniqid(),
            'material_category_id' => $this->leaf->id, 'item_type_id' => $this->stockType->id,
            'base_uom_id' => $this->pcs->id, 'issue_disposition' => 'consumed',
            'tracking_mode' => 'bulk_quantity', 'item_status' => $status,
        ]);
    }

    public function test_a_draft_cannot_be_received(): void
    {
        $draft = $this->material('Under Review');

        $this->postJson('/api/procurement-stores/check-in', [
            'material_id' => $draft->id, 'quantity' => 5,
        ])->assertStatus(422);

        $this->assertNull(Stock::where('material_id', $draft->id)->first());
    }

    /**
     * The batch screen used to skip every control the single screen enforced.
     * The gate now lives inside adjustStock, so both paths are covered by one rule.
     */
    public function test_a_draft_cannot_be_received_through_the_batch_screen_either(): void
    {
        $draft = $this->material('Under Review');

        $this->postJson('/api/procurement-stores/batch-check-in', [
            'items' => [['material_id' => $draft->id, 'quantity' => 5]],
        ])->assertStatus(422); // the domain refused it, which is not a server fault

        $this->assertSame(0, InventoryLog::where('material_id', $draft->id)->count());
        $this->assertNull(Stock::where('material_id', $draft->id)->first());
    }

    public function test_a_draft_cannot_be_issued(): void
    {
        $material = $this->material();
        app(InventoryService::class)->adjustStock($material->id, 10, 'check_in', []);

        // Reclassified after stock already arrived.
        $material->update(['item_status' => 'Under Review']);

        $this->expectException(\DomainException::class);
        app(InventoryService::class)->adjustStock($material->id, -1, 'check_out', []);
    }

    public function test_stock_already_issued_can_still_be_returned_after_reclassification(): void
    {
        $material = $this->material();
        $service = app(InventoryService::class);
        $service->adjustStock($material->id, 10, 'check_in', []);
        $issue = $service->adjustStock($material->id, -4, 'check_out', []);

        // Blocking this would strand four units in a project forever.
        $material->update(['item_status' => 'Blocked']);

        $return = $service->adjustStock($material->id, 4, 'return', [
            'original_issue_log_id' => $issue->id,
        ]);

        $this->assertSame('return', $return->type);
        $this->assertSame(10.0, (float) Stock::where('material_id', $material->id)->value('quantity_on_hand'));
    }

    public function test_a_write_off_is_also_allowed_on_a_blocked_item(): void
    {
        $material = $this->material();
        app(InventoryService::class)->adjustStock($material->id, 6, 'check_in', []);
        $material->update(['item_status' => 'Blocked']);

        app(InventoryService::class)->adjustStock($material->id, -2, 'defective', []);

        $this->assertSame(4.0, (float) Stock::where('material_id', $material->id)->value('quantity_on_hand'));
    }

    public function test_a_draft_board_cannot_be_reserved_against_a_job(): void
    {
        $boardGroup = MaterialCategory::create([
            'name' => 'Boards', 'code' => 'BRD'.random_int(100, 999),
            'item_type_id' => $this->stockType->id, 'is_active' => true, 'is_selectable' => false,
        ]);
        $boardLeaf = MaterialCategory::create([
            'name' => 'MDF Boards', 'code' => 'MDF'.random_int(100, 999), 'parent_id' => $boardGroup->id,
            'item_type_id' => $this->stockType->id, 'is_active' => true, 'is_selectable' => true,
        ]);
        $draftBoard = LibraryMaterial::create([
            'material_name' => 'MDF 18mm', 'material_code' => 'MDF-'.uniqid(),
            'material_category_id' => $boardLeaf->id, 'item_type_id' => $this->stockType->id,
            'issue_disposition' => 'recoverable_remainder', 'tracking_mode' => 'dimension_piece',
            'item_status' => 'Under Review',
        ]);

        $production = User::factory()->create();
        $production->assignRole('Production');
        Sanctum::actingAs($production->fresh());

        $this->postJson('/api/procurement-stores/board-requests', [
            'job_ref' => 'JOB-1', 'material_id' => $draftBoard->id, 'qty' => 1,
        ])->assertStatus(422)->assertSee('Under Review', false);
    }

    /**
     * The list filter and the behaviour must agree. An item configured as a
     * measured, recoverable piece is board tracked whatever its category is
     * called — the old filter only understood three seeded category names.
     */
    public function test_a_board_outside_the_seeded_categories_is_still_found_by_the_filter(): void
    {
        $oddGroup = MaterialCategory::create([
            'name' => 'Composite Panels', 'code' => 'CMP'.random_int(100, 999),
            'item_type_id' => $this->stockType->id, 'is_active' => true, 'is_selectable' => false,
        ]);
        $oddLeaf = MaterialCategory::create([
            'name' => 'Honeycomb Panel', 'code' => 'HNY'.random_int(100, 999), 'parent_id' => $oddGroup->id,
            'item_type_id' => $this->stockType->id, 'is_active' => true, 'is_selectable' => true,
        ]);
        $board = LibraryMaterial::create([
            'material_name' => 'Honeycomb 20mm', 'material_code' => 'HNY-'.uniqid(),
            'material_category_id' => $oddLeaf->id, 'item_type_id' => $this->stockType->id,
            'issue_disposition' => 'recoverable_remainder', 'tracking_mode' => 'dimension_piece',
            'item_status' => 'Active',
        ]);

        $this->assertTrue($board->fresh()->isBoardTrackable(), 'The behaviour treats it as a board.');
        $this->assertTrue(
            LibraryMaterial::boardTrackable()->whereKey($board->id)->exists(),
            'So the board_trackable filter must return it too.',
        );
        $this->assertFalse(LibraryMaterial::boardTrackable(false)->whereKey($board->id)->exists());
    }

    public function test_the_handling_classification_is_the_same_everywhere(): void
    {
        $material = $this->material();

        // The library resource and the Stores inventory both read this one value.
        $this->assertSame('quantity', $material->stock_handling);
        $this->assertSame('Used up', $material->handling_label);

        $material->update(['issue_disposition' => 'returnable', 'tracking_mode' => 'bulk_quantity']);
        $this->assertSame('reusable_item', $material->fresh()->stock_handling);
        $this->assertSame('Comes back', $material->fresh()->handling_label);
    }
}
