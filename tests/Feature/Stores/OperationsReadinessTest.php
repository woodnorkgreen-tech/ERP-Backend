<?php

namespace Tests\Feature\Stores;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\StoresFinancePosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OperationsReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_requires_library_or_finance_access(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));
        $this->getJson('/api/procurement-stores/readiness')->assertForbidden();
    }

    public function test_setup_gaps_and_stalled_postings_are_reported_without_changing_records(): void
    {
        Permission::findOrCreate(Permissions::MATERIALS_LIBRARY_VIEW);
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::MATERIALS_LIBRARY_VIEW);
        Sanctum::actingAs($user);
        $material = LibraryMaterial::create(['material_name' => 'Incomplete active item', 'material_code' => 'READY-001',
            'category' => 'Supplies', 'unit_of_measure' => 'pcs', 'item_status' => 'Active']);
        $material->stock()->create(['quantity_on_hand' => 3, 'quantity_reserved' => 4, 'warehouse_code' => 'MAIN']);
        $log = InventoryLog::create(['material_id' => $material->id, 'user_id' => $user->id,
            'type' => 'check_out', 'quantity' => -1, 'balance_after' => 3, 'logged_at' => now()]);
        $posting = StoresFinancePosting::create(['inventory_log_id' => $log->id, 'posting_type' => 'issue_cost',
            'status' => 'pending', 'updated_at' => now()->subHour()]);

        $response = $this->getJson('/api/procurement-stores/readiness')->assertOk()->assertJsonPath('data.ready', false);
        $checks = collect($response->json('data.checks'))->keyBy('key');
        $this->assertFalse($checks['material_controls']['ready']);
        $this->assertFalse($checks['stock_balances']['ready']);
        $this->assertFalse($checks['stock_valuation']['ready']);
        $this->assertFalse($checks['stores_cost_capture']['ready']);
        $this->assertSame(['READY-001'], $checks['material_controls']['examples']);
        $this->assertSame('pending', $posting->fresh()->status);
        $this->assertSame(4.0, (float) $material->stock()->first()->quantity_reserved);
    }
}
