<?php

namespace Tests\Feature\Stores;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StockMovementReversalTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private LibraryMaterial $material;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(Permissions::STORES_MOVEMENT_REVERSE);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->givePermissionTo(Permissions::STORES_MOVEMENT_REVERSE);
        Sanctum::actingAs($this->actor);

        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'General', 'code' => 'WS-REV-'.uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->material = LibraryMaterial::create([
            'workstation_id' => $workstationId,
            'material_name' => 'Reversal test material',
            'material_code' => 'REV-'.uniqid(),
            'category' => 'Consumables',
            'material_type' => 'consumable',
            'tracking_mode' => 'bulk_quantity',
            'issue_disposition' => 'consumed',
            'unit_of_measure' => 'pcs',
            'unit_cost' => 12,
            'item_status' => 'Active',
            'is_active' => true,
        ]);
    }

    public function test_delete_is_retired_and_reasoned_reversal_preserves_the_original(): void
    {
        $original = app(InventoryService::class)->adjustStock(
            $this->material->id,
            5,
            'check_in',
            ['receipt_unit_cost' => 12],
        );

        $this->deleteJson("/api/procurement-stores/inventory-logs/{$original->id}")
            ->assertStatus(405);
        $this->assertDatabaseHas('inventory_logs', ['id' => $original->id]);

        $response = $this->postJson("/api/procurement-stores/inventory-logs/{$original->id}/reverse", [
            'reason' => 'Supplier delivery was entered against the wrong material.',
        ])->assertCreated();

        $reversalId = $response->json('data.id');
        $this->assertSame(0.0, (float) Stock::where('material_id', $this->material->id)->value('quantity_on_hand'));
        $this->assertDatabaseHas('inventory_logs', ['id' => $original->id, 'type' => 'check_in']);
        $this->assertDatabaseHas('inventory_logs', [
            'id' => $reversalId,
            'type' => 'reversal',
            'reversal_of_log_id' => $original->id,
            'user_id' => $this->actor->id,
        ]);
        $this->assertEqualsWithDelta(
            0,
            (float) InventoryLog::where('material_id', $this->material->id)->sum('quantity'),
            0.00001,
        );
    }

    public function test_a_movement_can_only_be_reversed_once_and_requires_a_reason(): void
    {
        $original = app(InventoryService::class)->adjustStock($this->material->id, 2, 'check_in', [
            'receipt_unit_cost' => 12,
        ]);

        $url = "/api/procurement-stores/inventory-logs/{$original->id}/reverse";
        $this->postJson($url, ['reason' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($url, ['reason' => 'Duplicate receipt entered during reconciliation.'])->assertCreated();
        $this->postJson($url, ['reason' => 'Trying the same correction for a second time.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('movement');

        $this->assertSame(1, InventoryLog::where('reversal_of_log_id', $original->id)->count());
    }

    public function test_a_receipt_cannot_be_reversed_after_its_stock_has_been_used(): void
    {
        $receipt = app(InventoryService::class)->adjustStock($this->material->id, 3, 'check_in', [
            'receipt_unit_cost' => 12,
        ]);
        app(InventoryService::class)->adjustStock($this->material->id, -1, 'check_out');

        $this->postJson("/api/procurement-stores/inventory-logs/{$receipt->id}/reverse", [
            'reason' => 'Attempt to remove a receipt after stock was issued.',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(2.0, (float) Stock::where('material_id', $this->material->id)->value('quantity_on_hand'));
        $this->assertDatabaseMissing('inventory_logs', ['reversal_of_log_id' => $receipt->id]);
    }

    public function test_an_issue_reversal_restores_stock_and_preserves_both_movements(): void
    {
        app(InventoryService::class)->adjustStock($this->material->id, 5, 'check_in', [
            'receipt_unit_cost' => 12,
        ]);
        $issue = app(InventoryService::class)->adjustStock($this->material->id, -2, 'check_out');

        $response = $this->postJson("/api/procurement-stores/inventory-logs/{$issue->id}/reverse", [
            'reason' => 'The issue was recorded against the wrong material request.',
        ])->assertCreated();

        $reversalId = $response->json('data.id');
        $this->assertSame(5.0, (float) Stock::where('material_id', $this->material->id)->value('quantity_on_hand'));
        $this->assertDatabaseHas('inventory_logs', [
            'id' => $issue->id,
            'type' => 'check_out',
            'quantity' => -2,
        ]);
        $this->assertDatabaseHas('inventory_logs', [
            'id' => $reversalId,
            'type' => 'reversal',
            'quantity' => 2,
            'original_issue_log_id' => $issue->id,
            'reversal_of_log_id' => $issue->id,
        ]);
    }

    public function test_an_issue_with_any_return_cannot_be_reversed(): void
    {
        app(InventoryService::class)->adjustStock($this->material->id, 5, 'check_in', [
            'receipt_unit_cost' => 12,
        ]);
        $issue = app(InventoryService::class)->adjustStock($this->material->id, -2, 'check_out');
        app(InventoryService::class)->adjustStock($this->material->id, 1, 'return', [
            'original_issue_log_id' => $issue->id,
        ]);

        $this->postJson("/api/procurement-stores/inventory-logs/{$issue->id}/reverse", [
            'reason' => 'Attempt to reverse an issue that already has a partial return.',
        ])->assertUnprocessable()->assertJsonValidationErrors('movement');

        $this->assertSame(4.0, (float) Stock::where('material_id', $this->material->id)->value('quantity_on_hand'));
        $this->assertDatabaseMissing('inventory_logs', ['reversal_of_log_id' => $issue->id]);
    }

    public function test_a_reversal_cannot_itself_be_reversed(): void
    {
        $original = app(InventoryService::class)->adjustStock($this->material->id, 2, 'check_in', [
            'receipt_unit_cost' => 12,
        ]);
        $reversal = $this->postJson("/api/procurement-stores/inventory-logs/{$original->id}/reverse", [
            'reason' => 'Reverse the duplicate receipt while it is still unused.',
        ])->assertCreated()->json('data');

        $this->postJson("/api/procurement-stores/inventory-logs/{$reversal['id']}/reverse", [
            'reason' => 'Attempt to reverse a reversal rather than correct the source.',
        ])->assertUnprocessable()->assertJsonValidationErrors('movement');

        $this->assertSame(1, InventoryLog::where('reversal_of_log_id', $original->id)->count());
    }
}
