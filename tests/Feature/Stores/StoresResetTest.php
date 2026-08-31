<?php

namespace Tests\Feature\Stores;

use App\Models\GovernanceAuditLog;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\StockCount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StoresResetTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $manager;
    private int $workstationId;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Stores', 'Manager', 'Super Admin'] as $role) {
            Role::findOrCreate($role);
        }
        $this->workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Reset Store', 'code' => 'WS-RESET-'.uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('Super Admin');
        $this->manager = User::factory()->create();
        $this->manager->assignRole('Manager');
    }

    private function material(): LibraryMaterial
    {
        return LibraryMaterial::create([
            'workstation_id' => $this->workstationId,
            'material_name' => 'Reset material '.uniqid(),
            'material_code' => 'RST-'.uniqid(),
            'category' => 'Consumables', 'unit_of_measure' => 'pcs', 'unit_cost' => 5,
            'item_status' => 'Active', 'is_active' => true,
            'issue_disposition' => 'consumed', 'tracking_mode' => 'bulk_quantity',
            'is_serialized' => false, 'is_batch_controlled' => false, 'is_expiry_controlled' => false,
        ]);
    }

    private function seedStock(LibraryMaterial $material): void
    {
        DB::table('stocks')->insert([
            'material_id' => $material->id, 'quantity_on_hand' => 12,
            'warehouse_code' => 'MAIN', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_logs')->insert([
            'material_id' => $material->id, 'user_id' => $this->superAdmin->id,
            'type' => 'check_in', 'quantity' => 12, 'balance_after' => 12,
            'usage_type' => 'consumable', 'logged_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_only_a_super_admin_can_reach_the_reset(): void
    {
        Sanctum::actingAs($this->manager->fresh());
        $this->getJson('/api/procurement-stores/stores-reset/preview')->assertStatus(403);
        $this->postJson('/api/procurement-stores/stores-reset', [
            'confirmation' => 'RESET STORES', 'reason' => 'Manager should not be able to do this.',
        ])->assertStatus(403);
    }

    public function test_reset_requires_the_exact_confirmation_phrase_and_a_reason(): void
    {
        $material = $this->material();
        $this->seedStock($material);
        Sanctum::actingAs($this->superAdmin->fresh());

        $this->postJson('/api/procurement-stores/stores-reset', [
            'confirmation' => 'reset stores', 'reason' => 'Clearing the UAT data before go-live.',
        ])->assertStatus(422);
        $this->postJson('/api/procurement-stores/stores-reset', [
            'confirmation' => 'RESET STORES', 'reason' => 'too short',
        ])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertDatabaseCount('stocks', 1);
    }

    public function test_reset_clears_stores_but_keeps_the_catalogue_and_writes_an_audit_record(): void
    {
        $material = $this->material();
        $this->seedStock($material);
        Sanctum::actingAs($this->superAdmin->fresh());

        $preview = $this->getJson('/api/procurement-stores/stores-reset/preview')->assertOk();
        $this->assertSame(1, $preview->json('data.tables.stocks'));
        $this->assertSame([], $preview->json('data.blockers'));

        $this->postJson('/api/procurement-stores/stores-reset', [
            'confirmation' => 'RESET STORES', 'reason' => 'Clearing the UAT data before go-live.',
        ])->assertOk();

        $this->assertDatabaseCount('stocks', 0);
        $this->assertDatabaseCount('inventory_logs', 0);
        $this->assertDatabaseHas('library_materials', ['id' => $material->id]);
        $this->assertSame(1, GovernanceAuditLog::where('gate_type', 'stores_reset')->count());
    }

    public function test_reset_is_permanently_disarmed_once_an_opening_inventory_is_approved(): void
    {
        $material = $this->material();
        $this->seedStock($material);
        StockCount::create([
            'count_number' => 'OPEN-LIVE-1', 'mode' => StockCount::MODE_OPENING,
            'warehouse_code' => 'MAIN', 'status' => 'approved',
            'counted_on' => today(), 'created_by' => $this->superAdmin->id,
        ]);
        Sanctum::actingAs($this->superAdmin->fresh());

        $this->getJson('/api/procurement-stores/stores-reset/preview')
            ->assertOk()->assertJsonCount(1, 'data.blockers');
        $this->postJson('/api/procurement-stores/stores-reset', [
            'confirmation' => 'RESET STORES', 'reason' => 'Trying to reset a live store.',
        ])->assertStatus(422)->assertJsonValidationErrors('reset');

        $this->assertDatabaseCount('stocks', 1);
    }
}
