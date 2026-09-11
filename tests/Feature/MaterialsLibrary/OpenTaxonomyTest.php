<?php

namespace Tests\Feature\MaterialsLibrary;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Database\Seeders\LegacyMaterialCategorySeeder;
use App\Modules\MaterialsLibrary\Database\Seeders\MaterialCategorySeeder;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\MaterialItemType;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The people who know what a material is are the people typing the list, so the
 * taxonomy has to be theirs to extend. Consolidating it afterwards is a
 * judgement about meaning and stays deliberate — one merge at a time.
 */
class OpenTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private MaterialCategory $group;

    private MaterialItemType $stockType;

    private UnitOfMeasure $pcs;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Stores', 'Manager', 'Super Admin'] as $role) {
            Role::findOrCreate($role);
        }
        foreach (['Stores', 'Manager'] as $role) {
            Role::findByName($role)->givePermissionTo([
                Permissions::MATERIALS_LIBRARY_VIEW, Permissions::MATERIALS_LIBRARY_MANAGE,
            ]);
        }

        $this->stockType = MaterialItemType::create([
            'code' => 'ST'.random_int(1000, 9999), 'name' => 'Stock Material',
            'default_issue_disposition' => 'consumed', 'default_tracking_mode' => 'bulk_quantity',
            'is_stock_item' => true, 'is_active' => true,
        ]);
        $this->pcs = UnitOfMeasure::firstOrCreate(
            ['code' => 'pcs'], ['name' => 'Piece', 'dimension' => 'count', 'is_active' => true],
        );
        $this->group = MaterialCategory::create([
            'name' => 'Hardware '.uniqid(), 'code' => 'HW'.random_int(100, 999),
            'item_type_id' => $this->stockType->id, 'is_active' => true, 'is_selectable' => false,
        ]);
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    public function test_anyone_with_catalogue_access_can_add_a_category_to_a_group(): void
    {
        $this->actAs('Stores');

        $this->postJson('/api/materials-library/categories', [
            'name' => 'Brushed Aluminium Sheet',
            'parent_id' => $this->group->id,
        ])->assertStatus(201);

        $created = MaterialCategory::where('name', 'Brushed Aluminium Sheet')->sole();
        $this->assertSame($this->group->id, $created->parent_id);
        $this->assertTrue($created->is_selectable, 'A leaf holds materials.');
        // A category created mid-form still has to know how its materials behave.
        $this->assertSame($this->stockType->id, $created->item_type_id);
        $this->assertNotEmpty($created->code);
    }

    public function test_only_a_manager_can_add_a_top_level_group(): void
    {
        $this->actAs('Stores');
        $this->postJson('/api/materials-library/categories', ['name' => 'Brand New Group'])
            ->assertStatus(403);

        $this->actAs('Manager');
        $this->postJson('/api/materials-library/categories', ['name' => 'Brand New Group'])
            ->assertStatus(201);

        $this->assertFalse(MaterialCategory::where('name', 'Brand New Group')->sole()->is_selectable);
    }

    public function test_a_duplicate_name_in_the_same_group_is_refused(): void
    {
        $this->actAs('Stores');
        MaterialCategory::create([
            'name' => 'Screws & Bolts', 'code' => 'SB'.random_int(100, 999),
            'parent_id' => $this->group->id, 'is_active' => true, 'is_selectable' => true,
        ]);

        $this->postJson('/api/materials-library/categories', [
            'name' => 'screws & bolts', 'parent_id' => $this->group->id,
        ])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_seeding_adds_missing_categories_without_replacing_custom_ones(): void
    {
        $customGroup = MaterialCategory::create([
            'name' => 'Custom Group', 'code' => 'CUS', 'sort_order' => 99,
            'is_active' => true, 'is_selectable' => false,
        ]);
        $customLeaf = MaterialCategory::create([
            'name' => 'MDF Boards', 'code' => 'CUS-MDF', 'sort_order' => 99,
            'parent_id' => $customGroup->id, 'is_active' => true, 'is_selectable' => true,
        ]);

        app(MaterialCategorySeeder::class)->run();

        $customGroup->refresh();
        $customLeaf->refresh();
        $this->assertSame('CUS', $customGroup->code);
        $this->assertSame(99, $customGroup->sort_order);
        $this->assertSame('CUS-MDF', $customLeaf->code);
        $this->assertSame(99, $customLeaf->sort_order);
        $this->assertSame($customGroup->id, $customLeaf->parent_id);

        $standardGroup = MaterialCategory::where('name', 'Boards')->whereNull('parent_id')->sole();
        $standardLeaf = MaterialCategory::where('name', 'MDF Boards')->where('parent_id', $standardGroup->id)->sole();
        $this->assertNotSame($customLeaf->id, $standardLeaf->id);
        $this->assertSame('MDF', $standardLeaf->code);
    }

    public function test_legacy_seeder_adds_missing_categories_without_replacing_custom_ones(): void
    {
        $customGroup = MaterialCategory::create([
            'name' => 'Custom Legacy Group', 'code' => 'CUS', 'sort_order' => 99,
            'is_active' => true, 'is_selectable' => false,
        ]);
        $customLeaf = MaterialCategory::create([
            'name' => 'Custom Legacy Leaf', 'code' => 'CUSL', 'sort_order' => 99,
            'parent_id' => $customGroup->id, 'is_active' => true, 'is_selectable' => true,
        ]);

        $initialGroupCount = MaterialCategory::whereNull('parent_id')->count();
        $initialCategoryCount = MaterialCategory::count();

        app(LegacyMaterialCategorySeeder::class)->run();

        $customGroup->refresh();
        $customLeaf->refresh();
        $this->assertSame('CUS', $customGroup->code);
        $this->assertSame(99, $customGroup->sort_order);
        $this->assertSame('CUSL', $customLeaf->code);
        $this->assertSame(99, $customLeaf->sort_order);
        $this->assertSame($customGroup->id, $customLeaf->parent_id);

        $this->assertSame($initialGroupCount + 24, MaterialCategory::whereNull('parent_id')->count());
        $this->assertSame($initialCategoryCount + 111, MaterialCategory::count());

        $legacyGroup = MaterialCategory::where('name', 'MDF')->whereNull('parent_id')->sole();
        $this->assertSame('MDF2', $legacyGroup->code);
        $this->assertSame(2, MaterialCategory::where('parent_id', $legacyGroup->id)->count());
        $this->assertSame('SHEE', MaterialCategory::where('name', 'Sheet')->where('parent_id', $legacyGroup->id)->sole()->code);

        // Idempotent: re-running does not duplicate or alter anything
        app(LegacyMaterialCategorySeeder::class)->run();
        $this->assertSame($initialGroupCount + 24, MaterialCategory::whereNull('parent_id')->count());
        $this->assertSame($initialCategoryCount + 111, MaterialCategory::count());
    }

    public function test_the_tree_stays_two_levels_deep(): void
    {
        $this->actAs('Manager');
        $leaf = MaterialCategory::create([
            'name' => 'Screws', 'code' => 'SCR'.random_int(100, 999),
            'parent_id' => $this->group->id, 'is_active' => true, 'is_selectable' => true,
        ]);

        $this->postJson('/api/materials-library/categories', [
            'name' => 'Machine Screws', 'parent_id' => $leaf->id,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_merging_carries_the_materials_across_and_retires_the_source(): void
    {
        $this->actAs('Manager');

        $source = MaterialCategory::create([
            'name' => 'Screws', 'code' => 'SCR'.random_int(100, 999), 'parent_id' => $this->group->id,
            'is_active' => true, 'is_selectable' => true,
        ]);
        $target = MaterialCategory::create([
            'name' => 'Screws & Bolts', 'code' => 'SB'.random_int(100, 999), 'parent_id' => $this->group->id,
            'is_active' => true, 'is_selectable' => true,
        ]);
        $material = LibraryMaterial::create([
            'material_name' => 'M6 Screw', 'material_code' => 'SCR-'.uniqid(),
            'material_category_id' => $source->id, 'item_status' => 'Active',
        ]);

        $this->postJson("/api/materials-library/categories/{$source->id}/merge", [
            'into_category_id' => $target->id,
        ])->assertOk();

        $material->refresh();
        $this->assertSame($target->id, $material->material_category_id);
        // Both lookup paths have to agree, or the legacy string filters drift.
        $this->assertSame($this->group->name, $material->category);
        $this->assertSame('Screws & Bolts', $material->subcategory);
        $this->assertNull(MaterialCategory::find($source->id));
    }

    public function test_the_needs_finishing_list_reports_what_each_material_lacks(): void
    {
        $this->actAs('Stores');
        $leaf = MaterialCategory::create([
            'name' => 'Rivets', 'code' => 'RVT'.random_int(100, 999), 'parent_id' => $this->group->id,
            'is_active' => true, 'is_selectable' => true,
        ]);
        LibraryMaterial::create([
            'material_name' => 'Pop Rivet 4mm', 'material_code' => 'RVT-'.uniqid(),
            'material_category_id' => $leaf->id, 'item_status' => 'Under Review',
        ]);

        $response = $this->getJson('/api/materials-library/materials/incomplete')->assertOk();

        $row = collect($response->json('data.data'))->firstWhere('material_name', 'Pop Rivet 4mm');
        $this->assertNotNull($row);
        $this->assertArrayHasKey('base_uom_id', $row['missing']);
        $this->assertFalse($row['is_ready']);

        // Gaps are ranked so the biggest single win is obvious.
        $this->assertNotEmpty($response->json('gaps'));
    }

    public function test_one_decision_can_be_applied_to_many_materials_at_once(): void
    {
        $this->actAs('Stores');
        $leaf = MaterialCategory::create([
            'name' => 'Rivets', 'code' => 'RVT'.random_int(100, 999), 'parent_id' => $this->group->id,
            'is_active' => true, 'is_selectable' => true,
        ]);

        $ids = collect(range(1, 3))->map(fn ($n) => LibraryMaterial::create([
            'material_name' => "Rivet {$n}", 'material_code' => 'RV-'.uniqid(),
            'material_category_id' => $leaf->id, 'item_type_id' => $this->stockType->id,
            'issue_disposition' => 'consumed', 'tracking_mode' => 'bulk_quantity',
            'item_status' => 'Under Review',
        ])->id)->all();

        $this->postJson('/api/materials-library/materials/bulk-controls', [
            'material_ids' => $ids,
            'base_uom_id' => $this->pcs->id,
            'activate_when_complete' => true,
        ])->assertOk()->assertJson(['updated' => 3, 'activated' => 3]);

        foreach ($ids as $id) {
            $material = LibraryMaterial::find($id);
            $this->assertSame($this->pcs->id, $material->base_uom_id);
            $this->assertSame('Active', $material->item_status);
        }
    }

    public function test_bulk_repair_will_not_rewrite_a_base_unit_that_stock_has_already_moved_in(): void
    {
        $this->actAs('Stores');
        $leaf = MaterialCategory::create([
            'name' => 'Rivets', 'code' => 'RVT'.random_int(100, 999), 'parent_id' => $this->group->id,
            'is_active' => true, 'is_selectable' => true,
        ]);
        $metre = UnitOfMeasure::firstOrCreate(['code' => 'm'], ['name' => 'Metre', 'dimension' => 'length', 'is_active' => true]);

        $material = LibraryMaterial::create([
            'material_name' => 'Already Moved', 'material_code' => 'AM-'.uniqid(),
            'material_category_id' => $leaf->id, 'base_uom_id' => $metre->id, 'item_status' => 'Active',
        ]);
        Stock::create(['material_id' => $material->id, 'quantity_on_hand' => 5]);
        InventoryLog::create([
            'material_id' => $material->id, 'user_id' => auth()->id(), 'type' => 'check_in',
            'quantity' => 5, 'balance_after' => 5, 'logged_at' => now(),
        ]);

        $this->postJson('/api/materials-library/materials/bulk-controls', [
            'material_ids' => [$material->id],
            'base_uom_id' => $this->pcs->id,
        ])->assertOk();

        $this->assertSame(
            $metre->id,
            $material->fresh()->base_uom_id,
            'Changing the base unit after movements would rewrite the meaning of every past movement.',
        );
    }
}
