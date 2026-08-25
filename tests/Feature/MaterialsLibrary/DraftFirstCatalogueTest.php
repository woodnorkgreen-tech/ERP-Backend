<?php

namespace Tests\Feature\MaterialsLibrary;

use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\MaterialItemType;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\MaterialsLibrary\Support\MaterialCompleteness;
use App\Modules\ProcurementStores\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use App\Constants\Permissions;

/**
 * Identity and behaviour are different facts with different lifetimes. A
 * catalogue row only needs a name and a kind to exist; everything the old form
 * demanded up front is required at activation instead — the moment stock
 * movement becomes possible and being wrong first costs something.
 */
class DraftFirstCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private MaterialCategory $group;
    private MaterialCategory $leaf;
    private MaterialItemType $stockType;
    private UnitOfMeasure $sheet;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Stores', 'Manager', 'Super Admin'] as $role) {
            Role::findOrCreate($role);
        }
        Role::findByName('Stores')->givePermissionTo([
            Permissions::MATERIALS_LIBRARY_VIEW, Permissions::MATERIALS_LIBRARY_MANAGE,
        ]);
        Role::findByName('Manager')->givePermissionTo([
            Permissions::MATERIALS_LIBRARY_VIEW, Permissions::MATERIALS_LIBRARY_MANAGE,
        ]);

        $this->stockType = MaterialItemType::create([
            'code' => 'ST'.random_int(1000, 9999), 'name' => 'Stock Material',
            'default_issue_disposition' => 'consumed', 'default_tracking_mode' => 'bulk_quantity',
            'is_stock_item' => true, 'is_active' => true,
        ]);

        $this->sheet = UnitOfMeasure::firstOrCreate(
            ['code' => 'sheet'],
            ['name' => 'Sheet', 'dimension' => 'count', 'is_active' => true],
        );

        $this->group = MaterialCategory::create([
            'name' => 'Boards '.uniqid(), 'code' => 'BRD'.random_int(100, 999),
            'item_type_id' => $this->stockType->id, 'is_active' => true, 'is_selectable' => false,
        ]);
        $this->leaf = MaterialCategory::create([
            'name' => 'MDF Boards', 'code' => 'MDF'.random_int(100, 999), 'parent_id' => $this->group->id,
            'item_type_id' => $this->stockType->id, 'is_active' => true, 'is_selectable' => true,
            'allowed_uoms' => ['sheet'],
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole('Stores');
        Sanctum::actingAs($this->user->fresh());
    }

    private function createMaterial(array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/materials-library/materials', array_merge([
            'material_name' => 'MDF 18mm Sheet',
            'material_category_id' => $this->leaf->id,
        ], $payload));
    }

    public function test_a_material_saves_with_only_a_name_and_a_category(): void
    {
        $this->createMaterial()->assertStatus(201);

        $material = LibraryMaterial::sole();
        $this->assertSame('MDF 18mm Sheet', $material->material_name);
        $this->assertNotEmpty($material->material_code, 'A code should be generated when none is typed.');
        $this->assertNull($material->workstation_id, 'Workstation is routing, not identity.');
    }

    public function test_behaviour_is_derived_from_the_category_item_type(): void
    {
        $this->createMaterial()->assertStatus(201);

        $material = LibraryMaterial::sole();
        $this->assertSame($this->stockType->id, $material->item_type_id);
        $this->assertSame('consumed', $material->issue_disposition);
        $this->assertSame('bulk_quantity', $material->tracking_mode);
        // The category allows exactly one unit, so the choice is already made.
        $this->assertSame($this->sheet->id, $material->base_uom_id);
        $this->assertSame('sheet', $material->unit_of_measure);
    }

    public function test_a_material_that_arrives_complete_is_active_immediately(): void
    {
        $this->createMaterial()->assertStatus(201);

        $material = LibraryMaterial::with('materialCategory.parent')->sole();
        $this->assertSame([], MaterialCompleteness::missing($material));
        $this->assertSame('Active', $material->item_status);
    }

    public function test_a_material_whose_category_settles_nothing_waits_under_review(): void
    {
        $bare = MaterialCategory::create([
            'name' => 'Uncategorised Bits', 'code' => 'UNC'.random_int(100, 999),
            'parent_id' => $this->group->id, 'is_active' => true, 'is_selectable' => true,
        ]);
        $bare->update(['item_type_id' => null]);

        $this->createMaterial(['material_category_id' => $bare->id])->assertStatus(201);

        $material = LibraryMaterial::with('materialCategory.parent')->sole();
        $this->assertSame('Under Review', $material->item_status);
        $this->assertArrayHasKey('base_uom_id', MaterialCompleteness::missing($material));
    }

    public function test_a_draft_cannot_move_stock(): void
    {
        $material = LibraryMaterial::create([
            'material_name' => 'Unfinished Item', 'material_code' => 'DRAFT-'.uniqid(),
            'material_category_id' => $this->leaf->id, 'item_status' => 'Under Review',
        ]);

        // The gate is the one adjustStock already enforces — no new check needed.
        $this->expectException(\DomainException::class);
        app(InventoryService::class)->adjustStock($material->id, -1, 'check_out', []);
    }

    public function test_activation_refuses_while_something_is_missing_and_says_what(): void
    {
        $material = LibraryMaterial::create([
            'material_name' => 'Half Done', 'material_code' => 'HALF-'.uniqid(),
            'material_category_id' => $this->leaf->id, 'item_status' => 'Under Review',
        ]);

        $response = $this->postJson("/api/materials-library/materials/{$material->id}/activate")
            ->assertStatus(422);

        $this->assertStringContainsString('Base unit of measure', $response->json('message'));
        $this->assertSame('Under Review', $material->fresh()->item_status);
    }

    public function test_activation_succeeds_once_the_governance_set_is_complete(): void
    {
        $material = LibraryMaterial::create([
            'material_name' => 'Nearly There', 'material_code' => 'NEAR-'.uniqid(),
            'material_category_id' => $this->leaf->id, 'item_type_id' => $this->stockType->id,
            'base_uom_id' => $this->sheet->id, 'issue_disposition' => 'consumed',
            'tracking_mode' => 'bulk_quantity', 'item_status' => 'Under Review',
        ]);

        $this->postJson("/api/materials-library/materials/{$material->id}/activate")->assertOk();

        $this->assertSame('Active', $material->fresh()->item_status);
        $this->assertTrue((bool) $material->fresh()->is_active);
    }

    public function test_a_required_category_attribute_holds_activation_back(): void
    {
        $this->leaf->update([
            'required_attributes' => [['key' => 'thickness', 'label' => 'Thickness', 'required' => true]],
        ]);

        $material = LibraryMaterial::create([
            'material_name' => 'Board Without Thickness', 'material_code' => 'NOTHK-'.uniqid(),
            'material_category_id' => $this->leaf->id, 'item_type_id' => $this->stockType->id,
            'base_uom_id' => $this->sheet->id, 'issue_disposition' => 'consumed',
            'tracking_mode' => 'bulk_quantity', 'item_status' => 'Under Review',
        ]);

        $response = $this->postJson("/api/materials-library/materials/{$material->id}/activate")
            ->assertStatus(422);

        $this->assertStringContainsString('Thickness', $response->json('message'));
    }

    public function test_a_category_schema_is_inherited_from_its_group(): void
    {
        $this->group->update([
            'required_attributes' => [['key' => 'sheet_size', 'label' => 'Sheet size']],
        ]);
        $this->leaf->update([
            'required_attributes' => [['key' => 'thickness', 'label' => 'Thickness', 'required' => true]],
        ]);

        $keys = array_column($this->leaf->fresh()->resolvedAttributeSchema(), 'key');

        $this->assertSame(['sheet_size', 'thickness'], $keys, 'Group fields come first, then the leaf adds its own.');
    }

    public function test_schema_endpoint_explains_field_ownership(): void
    {
        $this->group->update(['required_attributes' => [[
            'key' => 'sheet_size', 'label' => 'Sheet size',
        ]]]);
        $this->leaf->update(['required_attributes' => [[
            'key' => 'finish', 'label' => 'Finish',
        ]]]);

        $response = $this->getJson("/api/materials-library/categories/{$this->leaf->id}/schema")
            ->assertOk();

        $fields = collect($response->json('data'))->keyBy('key');
        $this->assertSame('inherited', $fields['sheet_size']['source']);
        $this->assertSame('category', $fields['finish']['source']);
    }

    public function test_schema_endpoint_discovers_units_already_used_by_category_materials(): void
    {
        $this->leaf->update(['allowed_uoms' => null]);
        LibraryMaterial::create([
            'material_name' => 'Existing Sheet', 'material_code' => 'UNIT-'.uniqid(),
            'material_category_id' => $this->leaf->id, 'base_uom_id' => $this->sheet->id,
            'unit_of_measure' => $this->sheet->code,
        ]);

        $this->getJson("/api/materials-library/categories/{$this->leaf->id}/schema")
            ->assertOk()
            ->assertJsonPath('meta.observed_uoms.0', 'sheet');
    }

    public function test_legacy_attribute_cleanup_is_previewed_applied_and_reversible(): void
    {
        $material = LibraryMaterial::create([
            'material_name' => 'Legacy Board', 'material_code' => 'LEG-'.uniqid(),
            'material_category_id' => $this->leaf->id,
            'attributes' => ['attributes' => ['Thickness Size' => '18mm', 'Critical? (Y/N)' => 'N']],
        ]);
        $this->user->assignRole('Manager');
        Sanctum::actingAs($this->user->fresh());

        $this->getJson("/api/materials-library/categories/{$this->leaf->id}/normalization-preview")
            ->assertOk()->assertJsonPath('data.materials_ready', 1);

        $runId = $this->postJson("/api/materials-library/categories/{$this->leaf->id}/normalize-attributes", ['confirm' => true])
            ->assertOk()->json('data.run_id');
        $this->assertSame(['critical_y_n' => 'N', 'thickness_size' => '18mm'], $material->fresh()->attributes);

        $this->postJson("/api/materials-library/categories/normalization-runs/{$runId}/rollback", ['confirm' => true])
            ->assertOk();
        $this->assertSame(['attributes' => ['Thickness Size' => '18mm', 'Critical? (Y/N)' => 'N']], $material->fresh()->attributes);
    }

    public function test_a_supplied_specification_must_match_its_category_type(): void
    {
        $this->leaf->update([
            'required_attributes' => [['key' => 'thickness_mm', 'label' => 'Thickness', 'type' => 'number']],
        ]);

        $this->createMaterial([
            'attributes' => ['thickness_mm' => 'eighteen'],
        ])->assertStatus(422)->assertJsonValidationErrors('attributes.thickness_mm');
    }

    public function test_editing_an_active_item_into_an_incomplete_category_returns_it_to_review(): void
    {
        $this->createMaterial(['attributes' => ['grade' => 'Standard']])->assertCreated();
        $material = LibraryMaterial::sole();
        $this->assertSame('Active', $material->item_status);

        $strict = MaterialCategory::create([
            'name' => 'Specification Controlled '.uniqid(), 'code' => 'SPC'.random_int(100, 999),
            'parent_id' => $this->group->id, 'item_type_id' => $this->stockType->id,
            'is_active' => true, 'is_selectable' => true, 'allowed_uoms' => ['sheet'],
            'required_attributes' => [['key' => 'rating', 'label' => 'Technical rating', 'required' => true]],
        ]);

        $this->putJson("/api/materials-library/materials/{$material->id}", [
            'material_category_id' => $strict->id,
        ])->assertOk()->assertJsonPath('data.item_status', 'Under Review');

        $material->refresh();
        $this->assertSame('Under Review', $material->item_status);
        $this->assertFalse((bool) $material->is_active);
        $this->assertArrayHasKey('attributes.rating', MaterialCompleteness::missing($material->load('materialCategory.parent')));
    }

    public function test_only_managers_can_change_category_blueprints(): void
    {
        $this->putJson("/api/materials-library/categories/{$this->leaf->id}", [
            'required_attributes' => [['key' => 'grade', 'label' => 'Grade']],
        ])->assertForbidden();

        $this->user->assignRole('Manager');
        Sanctum::actingAs($this->user->fresh());

        $this->putJson("/api/materials-library/categories/{$this->leaf->id}", [
            'required_attributes' => [['key' => 'invalid key', 'label' => 'Grade']],
        ])->assertStatus(422)->assertJsonValidationErrors('required_attributes.0.key');
    }
}
