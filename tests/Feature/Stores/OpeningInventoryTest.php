<?php

namespace Tests\Feature\Stores;

use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\StockCount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OpeningInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $storekeeper;
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
            'name' => 'Opening Store',
            'code' => 'WS-OPEN-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->storekeeper = User::factory()->create();
        $this->storekeeper->assignRole('Stores');
        $this->manager = User::factory()->create();
        $this->manager->assignRole('Manager');
        Sanctum::actingAs($this->storekeeper->fresh());
    }

    private function material(array $overrides = []): LibraryMaterial
    {
        return LibraryMaterial::create(array_merge([
            'workstation_id' => $this->workstationId,
            'material_name' => 'Opening material '.uniqid(),
            'material_code' => 'OPEN-'.uniqid(),
            'category' => 'Consumables',
            'unit_of_measure' => 'pcs',
            'unit_cost' => 0,
            'item_status' => 'Active',
            'is_active' => true,
            'issue_disposition' => 'consumed',
            'tracking_mode' => 'bulk_quantity',
            'is_serialized' => false,
            'is_batch_controlled' => false,
            'is_expiry_controlled' => false,
        ], $overrides));
    }

    public function test_opening_inventory_is_seeded_from_active_library_items_without_creating_stock(): void
    {
        $active = $this->material();
        $inactive = $this->material(['item_status' => 'Inactive', 'is_active' => false]);

        $response = $this->postJson('/api/procurement-stores/stock-counts', [
            'mode' => StockCount::MODE_OPENING,
            'counted_on' => today()->toDateString(),
            'warehouse_code' => 'MAIN',
        ])->assertOk();

        $this->assertSame(StockCount::MODE_OPENING, $response->json('data.mode'));
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame($active->id, $response->json('data.items.0.material_id'));
        $this->assertSame('bulk_quantity', $response->json('data.items.0.entry_method'));
        $this->assertNull(Stock::where('material_id', $active->id)->first());
        $this->assertNull(Stock::where('material_id', $inactive->id)->first());

        $this->putJson('/api/procurement-stores/stock-counts/'.$response->json('data.id'), [
            'items' => [[
                'id' => $response->json('data.items.0.id'),
                'counted_quantity' => 0,
            ]],
        ])->assertOk();
        $this->material(['material_name' => 'Added after snapshot']);
        $this->postJson('/api/procurement-stores/stock-counts/'.$response->json('data.id').'/submit')
            ->assertStatus(422)
            ->assertJsonValidationErrors('materials');

        $this->postJson('/api/procurement-stores/stock-counts/'.$response->json('data.id').'/reject', [
            'review_notes' => 'Catalogue changed; rebuild the opening worksheet.',
        ])->assertOk()->assertJsonPath('message', 'Draft discarded. You can now start a fresh inventory session.');
        $this->postJson('/api/procurement-stores/stock-counts', [
            'mode' => StockCount::MODE_OPENING,
            'counted_on' => today()->toDateString(),
        ])->assertOk()->assertJsonCount(2, 'data.items');
    }

    public function test_draft_and_discarded_sessions_can_be_deleted_but_posted_ones_cannot(): void
    {
        $this->material();
        $draft = $this->postJson('/api/procurement-stores/stock-counts', [
            'mode' => StockCount::MODE_OPENING,
            'counted_on' => today()->toDateString(),
        ])->assertOk()->json('data.id');

        $stranger = User::factory()->create();
        $stranger->assignRole('Stores');
        Sanctum::actingAs($stranger->fresh());
        $this->deleteJson('/api/procurement-stores/stock-counts/'.$draft)->assertStatus(403);

        Sanctum::actingAs($this->storekeeper->fresh());
        $this->deleteJson('/api/procurement-stores/stock-counts/'.$draft)->assertOk();
        $this->assertDatabaseMissing('stock_counts', ['id' => $draft]);
        $this->assertDatabaseMissing('stock_count_items', ['stock_count_id' => $draft]);

        $submitted = $this->postJson('/api/procurement-stores/stock-counts', [
            'mode' => StockCount::MODE_OPENING,
            'counted_on' => today()->toDateString(),
        ])->assertOk()->json('data');
        $this->putJson('/api/procurement-stores/stock-counts/'.$submitted['id'], [
            'items' => [['id' => $submitted['items'][0]['id'], 'counted_quantity' => 0]],
        ])->assertOk();
        $this->postJson('/api/procurement-stores/stock-counts/'.$submitted['id'].'/submit')->assertOk();
        $this->deleteJson('/api/procurement-stores/stock-counts/'.$submitted['id'])->assertStatus(422);
        $this->assertDatabaseHas('stock_counts', ['id' => $submitted['id']]);

        // A submitted count is rejected by a reviewer, not the storekeeper.
        Sanctum::actingAs($this->manager->fresh());
        $this->postJson('/api/procurement-stores/stock-counts/'.$submitted['id'].'/reject', [
            'review_notes' => 'Rebuild this worksheet from scratch.',
        ])->assertOk();
        $this->deleteJson('/api/procurement-stores/stock-counts/'.$submitted['id'])->assertOk();
        $this->assertDatabaseMissing('stock_counts', ['id' => $submitted['id']]);
    }

    public function test_approved_opening_inventory_creates_stock_and_an_auditable_adjustment(): void
    {
        $material = $this->material();
        $created = $this->postJson('/api/procurement-stores/stock-counts', [
            'mode' => StockCount::MODE_OPENING,
            'counted_on' => today()->toDateString(),
            'warehouse_code' => 'MAIN',
        ])->assertOk();
        $countId = $created->json('data.id');
        $itemId = $created->json('data.items.0.id');

        $this->putJson("/api/procurement-stores/stock-counts/{$countId}", [
            'items' => [[
                'id' => $itemId,
                'counted_quantity' => 12,
                'opening_unit_cost' => 125.50,
                'location_bin' => 'A-01',
                'variance_reason' => 'Physical opening count',
            ]],
        ])->assertOk();
        $this->postJson("/api/procurement-stores/stock-counts/{$countId}/submit")->assertOk();

        // Even when the creator also has review authority, maker/checker wins.
        $this->storekeeper->assignRole('Manager');
        Sanctum::actingAs($this->storekeeper->fresh());
        $this->postJson("/api/procurement-stores/stock-counts/{$countId}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'The person who created the count cannot approve its adjustments.');

        Sanctum::actingAs($this->manager->fresh());
        $this->postJson("/api/procurement-stores/stock-counts/{$countId}/approve", [
            'review_notes' => 'Count and valuation checked.',
        ])->assertOk();

        $stock = Stock::where('material_id', $material->id)->sole();
        $this->assertSame(12.0, (float) $stock->quantity_on_hand);
        $this->assertSame('A-01', $stock->location_bin);
        $this->assertSame(125.50, (float) $material->fresh()->unit_cost);

        $log = InventoryLog::where('material_id', $material->id)->sole();
        $this->assertSame('adjustment', $log->type);
        $this->assertSame(12.0, (float) $log->quantity);
        $this->assertSame(12.0, (float) $log->balance_after);
        $this->assertSame(125.50, (float) $log->receipt_unit_cost);
        $this->assertStringStartsWith('OPEN-', (string) $log->reference_no);
        $this->assertSame('approved', StockCount::findOrFail($countId)->status);

        $this->postJson('/api/procurement-stores/stock-counts', [
            'mode' => StockCount::MODE_OPENING,
            'counted_on' => today()->toDateString(),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Opening inventory has already been approved. Use a normal physical stock count for later reconciliations.');
    }

    public function test_existing_bulk_balance_is_rebaselined_by_ledger_adjustment_not_deleted(): void
    {
        $material = $this->material(['unit_cost' => 40]);
        Stock::create([
            'material_id' => $material->id,
            'quantity_on_hand' => 18,
            'quantity_reserved' => 0,
            'warehouse_code' => 'MAIN',
            'location_bin' => 'OLD-BIN',
            'tracking_mode' => Stock::TRACK_BY_COUNT,
        ]);

        $created = $this->postJson('/api/procurement-stores/stock-counts', [
            'mode' => StockCount::MODE_OPENING,
            'counted_on' => today()->toDateString(),
        ])->assertOk();
        $countId = $created->json('data.id');
        $itemId = $created->json('data.items.0.id');
        $this->assertSame(18.0, (float) $created->json('data.items.0.system_quantity'));

        $this->putJson("/api/procurement-stores/stock-counts/{$countId}", [
            'items' => [[
                'id' => $itemId,
                'counted_quantity' => 4,
                'opening_unit_cost' => 75,
                'location_bin' => 'A-02',
                'variance_reason' => 'Removed pre-launch test balance',
            ]],
        ])->assertOk();
        $this->postJson("/api/procurement-stores/stock-counts/{$countId}/submit")->assertOk();

        Sanctum::actingAs($this->manager->fresh());
        $this->postJson("/api/procurement-stores/stock-counts/{$countId}/approve")->assertOk();

        $stock = Stock::where('material_id', $material->id)->sole();
        $this->assertSame(4.0, (float) $stock->quantity_on_hand);
        $this->assertSame('A-02', $stock->location_bin);
        $this->assertSame(75.0, (float) $material->fresh()->unit_cost);
        $this->assertSame(-14.0, (float) InventoryLog::where('material_id', $material->id)->sole()->quantity);
        $this->assertDatabaseHas('stock_count_items', [
            'id' => $itemId,
            'system_quantity' => 18,
            'counted_quantity' => 4,
            'variance_quantity' => -14,
        ]);
    }

    public function test_controlled_materials_are_initialized_at_zero_without_inventing_identities(): void
    {
        $bulk = $this->material();
        $serialized = $this->material([
            'material_name' => 'Serialized meter',
            'tracking_mode' => 'serialized_item',
            'is_serialized' => true,
            'issue_disposition' => 'returnable',
            'material_type' => 'reusable',
        ]);

        $created = $this->postJson('/api/procurement-stores/stock-counts', [
            'mode' => StockCount::MODE_OPENING,
            'counted_on' => today()->toDateString(),
        ])->assertOk();
        $countId = $created->json('data.id');
        $items = collect($created->json('data.items'))->keyBy('material_id');

        $this->putJson("/api/procurement-stores/stock-counts/{$countId}", [
            'items' => [
                ['id' => $items[$bulk->id]['id'], 'counted_quantity' => 0],
                ['id' => $items[$serialized->id]['id'], 'counted_quantity' => 1, 'opening_unit_cost' => 50],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('items');

        $this->putJson("/api/procurement-stores/stock-counts/{$countId}", [
            'items' => [
                ['id' => $items[$bulk->id]['id'], 'counted_quantity' => 0],
                ['id' => $items[$serialized->id]['id'], 'counted_quantity' => 0],
            ],
        ])->assertOk();
        $this->postJson("/api/procurement-stores/stock-counts/{$countId}/submit")->assertOk();

        Sanctum::actingAs($this->manager->fresh());
        $this->postJson("/api/procurement-stores/stock-counts/{$countId}/approve")->assertOk();

        $this->assertSame(0.0, (float) Stock::where('material_id', $bulk->id)->value('quantity_on_hand'));
        $this->assertSame(0.0, (float) Stock::where('material_id', $serialized->id)->value('quantity_on_hand'));
        $this->assertDatabaseCount('inventory_serial_items', 0);
        $this->assertSame(0, InventoryLog::count());
    }
}
