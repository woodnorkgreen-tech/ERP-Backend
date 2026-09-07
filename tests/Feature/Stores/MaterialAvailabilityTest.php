<?php

namespace Tests\Feature\Stores;

use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use App\Constants\Permissions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MaterialAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private int $workstationId;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Stores', 'Manager', 'Super Admin'] as $role) {
            Role::findOrCreate($role);
        }
        $this->workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Avail Store', 'code' => 'WS-AVAIL-'.uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Permission::findOrCreate(Permissions::MATERIALS_LIBRARY_VIEW);
        $user = User::factory()->create();
        $user->assignRole('Stores');
        $user->givePermissionTo(Permissions::MATERIALS_LIBRARY_VIEW);
        Sanctum::actingAs($user->fresh());
    }

    private function material(string $name, ?float $onHand, float $reserved = 0): LibraryMaterial
    {
        $material = LibraryMaterial::create([
            'workstation_id' => $this->workstationId,
            'material_name' => $name, 'material_code' => 'AV-'.uniqid(),
            'category' => 'Consumables', 'unit_of_measure' => 'pcs', 'unit_cost' => 3,
            'item_status' => 'Active', 'is_active' => true,
            'issue_disposition' => 'consumed', 'tracking_mode' => 'bulk_quantity',
            'is_serialized' => false, 'is_batch_controlled' => false, 'is_expiry_controlled' => false,
        ]);
        if ($onHand !== null) {
            DB::table('stocks')->insert([
                'material_id' => $material->id, 'quantity_on_hand' => $onHand,
                'quantity_reserved' => $reserved, 'warehouse_code' => 'MAIN',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $material;
    }

    public function test_issuable_scope_excludes_zero_reserved_and_unstocked_materials(): void
    {
        $stocked = $this->material('Has free stock', 10);
        $this->material('Zero balance', 0);
        $this->material('Fully reserved', 4, 4);
        $this->material('Never stocked', null);

        $issuable = LibraryMaterial::issuable()->pluck('id')->all();
        $this->assertSame([$stocked->id], $issuable);
        $this->assertSame(4, LibraryMaterial::governed()->count());
    }

    public function test_issuable_filter_narrows_the_stores_picker_without_changing_the_default(): void
    {
        $stocked = $this->material('Issuable item', 7);
        $zero = $this->material('Zero item', 0);

        $all = $this->getJson('/api/procurement-stores/inventory')->assertOk()->json('data.data');
        $ids = collect($all)->pluck('id')->all();
        $this->assertContains($stocked->id, $ids);
        $this->assertContains($zero->id, $ids, 'Zero balances must stay visible for reorder.');

        $issuable = $this->getJson('/api/procurement-stores/inventory?availability=issuable')->assertOk()->json('data.data');
        $issuableIds = collect($issuable)->pluck('id')->all();
        $this->assertContains($stocked->id, $issuableIds);
        $this->assertNotContains($zero->id, $issuableIds);
    }

    public function test_demand_side_catalogue_still_lists_materials_with_no_stock(): void
    {
        $neverStocked = $this->material('Planning only', null);

        $catalogue = $this->getJson('/api/materials-library/materials')->assertOk()->json('data');
        $this->assertContains($neverStocked->id, collect($catalogue)->pluck('id')->all());

        $issuable = $this->getJson('/api/materials-library/materials?availability=issuable')->assertOk()->json('data');
        $this->assertNotContains($neverStocked->id, collect($issuable)->pluck('id')->all());
    }

    public function test_catalogue_and_workstation_use_status_instead_of_a_stale_legacy_flag(): void
    {
        $active = $this->material('Active stale flag', null);
        $active->update(['is_active' => false]);
        $blocked = $this->material('Blocked stale flag', 10);
        $blocked->update(['item_status' => 'Blocked']);

        $rows = $this->getJson('/api/materials-library/materials')->assertOk()->json('data');
        $this->assertContains($active->id, collect($rows)->pluck('id')->all());
        $this->assertNotContains($blocked->id, collect($rows)->pluck('id')->all());
    }

    public function test_reservations_cannot_exceed_stock_or_be_released_below_zero(): void
    {
        $material = $this->material('Reservation control', 5, 2);
        $inventory = app(\App\Modules\ProcurementStores\Services\InventoryService::class);
        foreach ([4, -3] as $quantity) {
            try {
                $inventory->reserveStock($material->id, $quantity);
                $this->fail('Invalid reservation was accepted.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertArrayHasKey('quantity', $exception->errors());
            }
            $this->assertSame(2.0, (float) $material->stock()->first()->quantity_reserved);
        }
        $inventory->reserveStock($material->id, 3);
        $this->assertSame(5.0, (float) $material->stock()->first()->quantity_reserved);
    }
}
