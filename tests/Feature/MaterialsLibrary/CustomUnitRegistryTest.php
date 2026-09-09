<?php

namespace Tests\Feature\MaterialsLibrary;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The registry has always run short — 65 materials once sat incomplete because
 * nobody had registered "pack" or "cartridge", and the only fix was a migration.
 * The person naming the material knows the unit, so they can register it, while
 * the two things that must not happen still cannot: two rows meaning the same
 * unit, and a stock unit changing under quantities already recorded in it.
 */
class CustomUnitRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('Stores')->givePermissionTo([
            Permissions::MATERIALS_LIBRARY_VIEW, Permissions::MATERIALS_LIBRARY_MANAGE,
        ]);
        Role::findOrCreate('Reader')->givePermissionTo([Permissions::MATERIALS_LIBRARY_VIEW]);
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    public function test_a_unit_the_registry_never_held_can_be_registered_from_the_material_form(): void
    {
        $this->actAs('Stores');

        $response = $this->postJson('/api/materials-library/reference/units-of-measure', [
            'name' => 'Bag of cement',
            'dimension' => 'package',
        ])->assertStatus(201)->assertJsonPath('adopted', false);

        $unit = UnitOfMeasure::findOrFail($response->json('data.id'));
        $this->assertSame('bag_of_cement', $unit->code, 'The code is derived from the name when none is typed.');
        $this->assertSame('package', $unit->dimension);
        $this->assertTrue($unit->is_active);
        // A bag is a whole thing; half a bag is not a quantity Stores records.
        $this->assertFalse($unit->allows_fraction);
        $this->assertSame(0, $unit->decimal_places);

        // It is registry-wide, so every picker sees it immediately.
        $this->getJson('/api/materials-library/reference/units-of-measure')
            ->assertOk()
            ->assertJsonFragment(['code' => 'bag_of_cement']);
    }

    public function test_a_measured_unit_keeps_its_decimals(): void
    {
        $this->actAs('Stores');

        $this->postJson('/api/materials-library/reference/units-of-measure', [
            'name' => 'Foot', 'code' => 'ft', 'dimension' => 'length',
        ])->assertStatus(201);

        $unit = UnitOfMeasure::where('code', 'ft')->sole();
        $this->assertTrue($unit->allows_fraction);
        $this->assertSame(3, $unit->decimal_places);
    }

    public function test_a_unit_that_already_exists_is_adopted_rather_than_duplicated(): void
    {
        $this->actAs('Stores');
        // Seeded by the registry migration; firstOrCreate keeps the test honest
        // whether or not it is already there.
        $existing = UnitOfMeasure::firstOrCreate(
            ['code' => 'pcs'],
            ['name' => 'Piece', 'dimension' => 'count', 'decimal_places' => 0, 'allows_fraction' => false, 'is_active' => true],
        );

        // Same code in a different case.
        $this->postJson('/api/materials-library/reference/units-of-measure', [
            'name' => 'Pieces', 'code' => 'PCS', 'dimension' => 'count',
        ])->assertOk()->assertJsonPath('adopted', true)->assertJsonPath('data.id', $existing->id);

        // Same name, no code typed at all.
        $this->postJson('/api/materials-library/reference/units-of-measure', [
            'name' => 'piece', 'dimension' => 'package',
        ])->assertOk()->assertJsonPath('adopted', true)->assertJsonPath('data.id', $existing->id);

        $this->assertSame(1, UnitOfMeasure::whereIn('code', ['pcs', 'PCS'])->count());
    }

    public function test_registering_a_retired_unit_brings_it_back_instead_of_creating_a_second_one(): void
    {
        $this->actAs('Stores');
        $retired = UnitOfMeasure::create([
            'code' => 'drum', 'name' => 'Drum', 'dimension' => 'package',
            'decimal_places' => 0, 'allows_fraction' => false, 'is_active' => false,
        ]);

        $this->postJson('/api/materials-library/reference/units-of-measure', [
            'name' => 'Drum', 'dimension' => 'package',
        ])->assertOk()->assertJsonPath('data.id', $retired->id);

        $this->assertTrue($retired->fresh()->is_active);
        $this->assertSame(1, UnitOfMeasure::where('code', 'drum')->count());
    }

    public function test_reading_the_catalogue_does_not_let_you_extend_the_registry(): void
    {
        $this->actAs('Reader');

        $this->postJson('/api/materials-library/reference/units-of-measure', [
            'name' => 'Wheelbarrow', 'dimension' => 'package',
        ])->assertStatus(403);

        $this->assertSame(0, UnitOfMeasure::where('code', 'wheelbarrow')->count());
    }

    public function test_a_unit_without_a_dimension_is_refused(): void
    {
        $this->actAs('Stores');

        $this->postJson('/api/materials-library/reference/units-of-measure', ['name' => 'Handful'])
            ->assertStatus(422)->assertJsonValidationErrors('dimension');

        $this->postJson('/api/materials-library/reference/units-of-measure', [
            'name' => 'Handful', 'dimension' => 'vibes',
        ])->assertStatus(422)->assertJsonValidationErrors('dimension');
    }

    public function test_the_form_is_told_when_a_stock_unit_can_no_longer_change(): void
    {
        $this->actAs('Stores');
        $category = MaterialCategory::create([
            'name' => 'Adhesives '.uniqid(), 'code' => 'ADH'.random_int(100, 999),
            'is_active' => true, 'is_selectable' => true,
        ]);
        $litre = UnitOfMeasure::firstOrCreate(
            ['code' => 'l'],
            ['name' => 'Litre', 'dimension' => 'volume', 'decimal_places' => 3, 'allows_fraction' => true, 'is_active' => true],
        );

        $untouched = LibraryMaterial::create([
            'material_name' => 'Never Received', 'material_code' => 'NR-'.uniqid(),
            'material_category_id' => $category->id, 'base_uom_id' => $litre->id, 'item_status' => 'Active',
        ]);
        $moved = LibraryMaterial::create([
            'material_name' => 'Already Received', 'material_code' => 'AR-'.uniqid(),
            'material_category_id' => $category->id, 'base_uom_id' => $litre->id, 'item_status' => 'Active',
        ]);
        Stock::create(['material_id' => $moved->id, 'quantity_on_hand' => 20]);
        InventoryLog::create([
            'material_id' => $moved->id, 'user_id' => auth()->id(), 'type' => 'check_in',
            'quantity' => 20, 'balance_after' => 20, 'logged_at' => now(),
        ]);

        $this->getJson("/api/materials-library/materials/{$untouched->id}")
            ->assertOk()->assertJsonPath('data.base_uom_locked', false);
        $this->getJson("/api/materials-library/materials/{$moved->id}")
            ->assertOk()->assertJsonPath('data.base_uom_locked', true);

        // And the flag matches what the update actually does.
        $this->putJson("/api/materials-library/materials/{$moved->id}", ['base_uom_id' => $this->newUnitId()])
            ->assertStatus(422)->assertJsonValidationErrors('base_uom_id');
    }

    private function newUnitId(): int
    {
        return UnitOfMeasure::firstOrCreate(
            ['code' => 'kg'],
            ['name' => 'Kilogram', 'dimension' => 'mass', 'decimal_places' => 3, 'allows_fraction' => true, 'is_active' => true],
        )->id;
    }
}
