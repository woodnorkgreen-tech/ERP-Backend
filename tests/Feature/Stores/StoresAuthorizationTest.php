<?php

namespace Tests\Feature\Stores;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StoresAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_projections_require_a_stores_permission_and_do_not_mutate_stock(): void
    {
        $unauthorized = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($unauthorized);
        foreach (['valuation-readiness', 'ledger-reconciliation', 'action-queue'] as $endpoint) {
            $this->getJson("/api/procurement-stores/{$endpoint}")->assertForbidden();
        }

        Permission::findOrCreate(Permissions::STORES_VIEW);
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo(Permissions::STORES_VIEW);
        Sanctum::actingAs($viewer);

        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'General', 'code' => 'WS-AUTH-'.uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $material = LibraryMaterial::create([
            'workstation_id' => $workstationId,
            'material_name' => 'Unvalued audit material',
            'material_code' => 'AUD-'.uniqid(),
            'category' => 'Consumables',
            'material_type' => 'consumable',
            'tracking_mode' => 'bulk_quantity',
            'issue_disposition' => 'consumed',
            'unit_of_measure' => 'pcs',
            'unit_cost' => 0,
            'item_status' => 'Active',
            'is_active' => true,
        ]);
        Stock::create(['material_id' => $material->id, 'quantity_on_hand' => 4, 'quantity_reserved' => 0]);
        InventoryLog::create([
            'material_id' => $material->id,
            'user_id' => $viewer->id,
            'type' => 'check_in',
            'quantity' => 3,
            'balance_after' => 3,
            'logged_at' => now(),
        ]);

        $this->getJson('/api/procurement-stores/valuation-readiness')
            ->assertOk()
            ->assertJsonPath('data.0.classification', 'UNVALUED')
            ->assertJsonPath('data.0.unit_cost', null);
        $this->getJson('/api/procurement-stores/ledger-reconciliation')
            ->assertOk()
            ->assertJsonPath('summary.discrepancies', 1)
            ->assertJsonPath('data.0.difference', 1);
        $this->getJson('/api/procurement-stores/action-queue')->assertOk();

        $this->assertSame(4.0, (float) $material->stock()->value('quantity_on_hand'));
        $this->assertSame(1, InventoryLog::where('material_id', $material->id)->count());
    }

    public function test_stores_view_alone_cannot_reverse_a_movement(): void
    {
        Permission::findOrCreate(Permissions::STORES_VIEW);
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo(Permissions::STORES_VIEW);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/procurement-stores/inventory-logs/999/reverse', [
            'reason' => 'Viewer attempts a movement reversal without authority.',
        ])->assertForbidden();
    }
}
