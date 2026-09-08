<?php

namespace Tests\Feature\MaterialsLibrary;

use App\Constants\Permissions;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Being in the library and being carried by Stores are two different facts.
 *
 * The library is the register of every material identity the business can name;
 * Store Inventory is the much shorter list of what is actually kept on a shelf.
 * Until this flag existed the second was derived from the first, so activating
 * a catalogue row put it on the inventory whether or not anyone stocked it.
 *
 * The flag is a listing decision, never an authorisation one — which is what
 * most of these tests are about.
 */
class InventoryVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private MaterialCategory $leaf;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('Stores')->givePermissionTo([
            Permissions::MATERIALS_LIBRARY_VIEW,
            Permissions::MATERIALS_LIBRARY_MANAGE,
        ]);

        $itemType = MaterialItemType::create([
            'code' => 'ST'.random_int(1000, 9999), 'name' => 'Stock Material',
            'default_issue_disposition' => 'consumed', 'default_tracking_mode' => 'bulk_quantity',
            'is_stock_item' => true, 'is_active' => true,
        ]);
        $uom = UnitOfMeasure::firstOrCreate(
            ['code' => 'pcs'],
            ['name' => 'Pieces', 'dimension' => 'count', 'is_active' => true],
        );
        $group = MaterialCategory::create([
            'name' => 'Consumables '.uniqid(), 'code' => 'CON'.random_int(100, 999),
            'item_type_id' => $itemType->id, 'is_active' => true, 'is_selectable' => false,
        ]);
        $this->leaf = MaterialCategory::create([
            'name' => 'Adhesives', 'code' => 'ADH'.random_int(100, 999), 'parent_id' => $group->id,
            'item_type_id' => $itemType->id, 'is_active' => true, 'is_selectable' => true,
            'allowed_uoms' => [$uom->code],
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole('Stores');
        Sanctum::actingAs($this->user->fresh());
    }

    private function material(string $name, float $onHand = 0, bool $visible = true, bool $returnable = false): LibraryMaterial
    {
        $material = LibraryMaterial::create([
            'material_name' => $name,
            'material_code' => strtoupper(substr(md5($name), 0, 8)),
            'material_category_id' => $this->leaf->id,
            'category' => $this->leaf->name,
            'unit_of_measure' => 'pcs',
            'base_uom_id' => UnitOfMeasure::where('code', 'pcs')->value('id'),
            'item_status' => 'Active',
            'is_active' => true,
            'material_type' => $returnable ? 'reusable' : 'consumable',
            'issue_disposition' => $returnable ? 'returnable' : 'consumed',
            'tracking_mode' => 'bulk_quantity',
            'is_inventory_visible' => $visible,
        ]);

        if ($onHand > 0) {
            Stock::create([
                'material_id' => $material->id,
                'quantity_on_hand' => $onHand,
                'quantity_reserved' => 0,
            ]);
        }

        return $material;
    }

    private function inventoryIds(array $params = []): array
    {
        $response = $this->getJson('/api/procurement-stores/inventory?'.http_build_query($params))->assertOk();
        $payload = $response->json('data.data') ?? $response->json('data');

        return collect($payload)->pluck('id')->all();
    }

    public function test_a_material_is_listed_in_inventory_by_default(): void
    {
        $material = $this->material('Wood glue');

        $this->assertTrue($material->fresh()->is_inventory_visible);
        $this->assertContains($material->id, $this->inventoryIds(['include_unstocked' => 1]));
    }

    public function test_hiding_a_material_removes_it_from_the_inventory_listing(): void
    {
        $listed = $this->material('Wood glue');
        $hidden = $this->material('Obscure sealant');

        $this->postJson('/api/materials-library/materials/inventory-visibility', [
            'material_ids' => [$hidden->id],
            'visible' => false,
        ])->assertOk()->assertJsonPath('updated', 1);

        $ids = $this->inventoryIds(['include_unstocked' => 1]);
        $this->assertContains($listed->id, $ids);
        $this->assertNotContains($hidden->id, $ids, 'A hidden material should not appear on the shelf list.');
    }

    public function test_the_library_itself_still_lists_a_hidden_material(): void
    {
        $hidden = $this->material('Obscure sealant', visible: false);

        $this->getJson('/api/materials-library/materials')
            ->assertOk()
            ->assertJsonPath('data.0.id', $hidden->id)
            ->assertJsonPath('data.0.is_inventory_visible', false);
    }

    public function test_a_material_holding_stock_cannot_be_hidden(): void
    {
        $stocked = $this->material('Wood glue', onHand: 12);

        $response = $this->postJson('/api/materials-library/materials/inventory-visibility', [
            'material_ids' => [$stocked->id],
            'visible' => false,
        ])->assertOk();

        $this->assertSame(0, $response->json('updated'));
        $this->assertSame(['Wood glue'], $response->json('blocked'));
        $this->assertTrue($stocked->fresh()->is_inventory_visible);
        $this->assertContains($stocked->id, $this->inventoryIds(['include_unstocked' => 1]));
    }

    public function test_stock_arriving_on_a_hidden_material_puts_it_back_on_the_list(): void
    {
        // The write guard cannot cover stock that arrives after the fact, so the
        // read path is the backstop: no balance is ever invisible.
        $hidden = $this->material('Obscure sealant', visible: false);
        Stock::create(['material_id' => $hidden->id, 'quantity_on_hand' => 4, 'quantity_reserved' => 0]);

        $this->assertContains($hidden->id, $this->inventoryIds(['include_unstocked' => 1]));
    }

    public function test_an_explicit_material_id_lookup_is_never_filtered(): void
    {
        // Project fulfilment resolves the exact identities its BOM references.
        // Filtering that would report a correctly linked row as unlinked.
        $hidden = $this->material('Obscure sealant', visible: false);

        $this->assertContains(
            $hidden->id,
            $this->inventoryIds(['material_ids' => [$hidden->id], 'include_unstocked' => 1]),
        );
    }

    public function test_include_hidden_returns_the_whole_catalogue(): void
    {
        // Receiving and planning pickers resolve identity rather than browse a
        // shelf, so they opt back into the full register.
        $hidden = $this->material('Obscure sealant', visible: false);

        $this->assertNotContains($hidden->id, $this->inventoryIds(['include_unstocked' => 1]));
        $this->assertContains($hidden->id, $this->inventoryIds(['include_unstocked' => 1, 'include_hidden' => 1]));
    }

    public function test_visibility_can_be_restored(): void
    {
        $hidden = $this->material('Obscure sealant', visible: false);

        $this->postJson('/api/materials-library/materials/inventory-visibility', [
            'material_ids' => [$hidden->id],
            'visible' => true,
        ])->assertOk()->assertJsonPath('updated', 1);

        $this->assertTrue($hidden->fresh()->is_inventory_visible);
        $this->assertContains($hidden->id, $this->inventoryIds(['include_unstocked' => 1]));
    }

    public function test_many_materials_are_set_in_one_call(): void
    {
        $first = $this->material('Wood glue');
        $second = $this->material('Contact adhesive');
        $stocked = $this->material('Epoxy', onHand: 3);

        $response = $this->postJson('/api/materials-library/materials/inventory-visibility', [
            'material_ids' => [$first->id, $second->id, $stocked->id],
            'visible' => false,
        ])->assertOk();

        $this->assertSame(2, $response->json('updated'));
        $this->assertSame(['Epoxy'], $response->json('blocked'));
    }

    public function test_a_hidden_material_is_not_offered_for_stock_movement(): void
    {
        // The movement pickers read the same curated list as the shelf, so an
        // item Stores does not carry cannot be received, issued or returned.
        $hidden = $this->material('Obscure sealant', visible: false);
        $carried = $this->material('Wood glue');

        $receivePicker = $this->inventoryIds(['include_unstocked' => 1]);
        $this->assertNotContains($hidden->id, $receivePicker);
        $this->assertContains($carried->id, $receivePicker);
    }

    public function test_receiving_stock_puts_a_hidden_material_back_on_the_list(): void
    {
        // Bringing stock in is the declaration that Stores carries the item, so
        // no receipt can leave a balance sitting on a hidden row.
        $hidden = $this->material('Obscure sealant', visible: false);

        app(InventoryService::class)->adjustStock($hidden->id, 6, 'check_in', [
            'reference_no' => 'GRN-TEST-1',
        ]);

        $this->assertTrue($hidden->fresh()->is_inventory_visible);
        $this->assertContains($hidden->id, $this->inventoryIds(['include_unstocked' => 1]));
    }

    /**
     * Custody rows as the ledger records them. Written directly so the loan
     * balance is the only thing under test — routing these through adjustStock
     * would drag Finance posting into a question about visibility.
     */
    private function custody(LibraryMaterial $material, string $type, float $quantity): void
    {
        InventoryLog::create([
            'material_id' => $material->id,
            'user_id' => $this->user->id,
            'type' => $type,
            'usage_type' => 'reusable',
            'quantity' => $type === 'check_out' ? -abs($quantity) : abs($quantity),
            'balance_after' => 0,
            'reference_no' => strtoupper($type).'-'.uniqid(),
            'logged_at' => now(),
        ]);
    }

    public function test_a_material_still_out_on_loan_cannot_be_hidden(): void
    {
        // A returnable item sits at zero on hand for exactly as long as someone
        // is holding it. Hiding it there would take it off the movement screens
        // at the one moment it has to be returnable.
        $onLoan = $this->material('Scaffold clamp', returnable: true);
        $this->custody($onLoan, 'check_out', 5);

        $response = $this->postJson('/api/materials-library/materials/inventory-visibility', [
            'material_ids' => [$onLoan->id],
            'visible' => false,
        ])->assertOk();

        $this->assertSame(0, $response->json('updated'));
        $this->assertSame(['Scaffold clamp'], $response->json('blocked'));
        $this->assertContains($onLoan->id, $this->inventoryIds(['include_unstocked' => 1]));
    }

    public function test_a_fully_returned_material_can_be_hidden_again(): void
    {
        // Nothing is owed once custody nets to zero, so the item is free to come
        // off the list — the guard tracks the obligation, not its history.
        $returned = $this->material('Scaffold clamp', returnable: true);
        $this->custody($returned, 'check_out', 5);
        $this->custody($returned, 'return', 5);

        $this->postJson('/api/materials-library/materials/inventory-visibility', [
            'material_ids' => [$returned->id],
            'visible' => false,
        ])->assertOk()->assertJsonPath('updated', 1)->assertJsonPath('blocked', []);

        $this->assertNotContains($returned->id, $this->inventoryIds(['include_unstocked' => 1]));
    }

    public function test_changing_visibility_requires_the_manage_permission(): void
    {
        $material = $this->material('Wood glue');

        // Read access to the catalogue is not permission to decide what Stores
        // carries, so a view-only user is granted exactly that and no more.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::findOrCreate(Permissions::MATERIALS_LIBRARY_VIEW, 'web'));
        Sanctum::actingAs($viewer->fresh());

        $this->postJson('/api/materials-library/materials/inventory-visibility', [
            'material_ids' => [$material->id],
            'visible' => false,
        ])->assertForbidden();

        $this->assertTrue($material->fresh()->is_inventory_visible);
    }

    public function test_the_material_form_cannot_write_the_flag(): void
    {
        // One door, so the stock guard cannot be walked around.
        $material = $this->material('Wood glue');

        $this->putJson("/api/materials-library/materials/{$material->id}", [
            'material_name' => 'Wood glue',
            'is_inventory_visible' => false,
        ])->assertOk();

        $this->assertTrue($material->fresh()->is_inventory_visible);
    }
}
