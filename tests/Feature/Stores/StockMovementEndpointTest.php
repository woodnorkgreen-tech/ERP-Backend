<?php

namespace Tests\Feature\Stores;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The one movement endpoint: a type and a list of lines.
 *
 * These pin the two things the consolidation was for — that many lines post as
 * one atomic act, and that a batch is held to exactly the rules a single line
 * is. Before this, batch receiving accepted no lot number and no expiry, so the
 * same delivery obeyed different rules depending on which screen typed it.
 */
class StockMovementEndpointTest extends TestCase
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
            'name' => 'Movement Store', 'code' => 'WS-MOVE-'.uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Permission::findOrCreate(Permissions::MATERIALS_LIBRARY_VIEW);
        $user = User::factory()->create();
        $user->assignRole('Stores');
        $user->givePermissionTo(Permissions::MATERIALS_LIBRARY_VIEW);
        // Roles are read off the fresh record, or every request answers 403.
        Sanctum::actingAs($user->fresh());
    }

    private function material(string $name, float $onHand = 0, array $overrides = []): LibraryMaterial
    {
        $material = LibraryMaterial::create(array_merge([
            'workstation_id' => $this->workstationId,
            'material_name' => $name, 'material_code' => 'MV-'.uniqid(),
            'category' => 'Consumables', 'unit_of_measure' => 'pcs', 'unit_cost' => 5,
            'item_status' => 'Active', 'is_active' => true,
            'issue_disposition' => 'consumed', 'tracking_mode' => 'bulk_quantity',
            'is_serialized' => false, 'is_batch_controlled' => false, 'is_expiry_controlled' => false,
        ], $overrides));

        DB::table('stocks')->insert([
            'material_id' => $material->id, 'quantity_on_hand' => $onHand,
            'quantity_reserved' => 0, 'warehouse_code' => 'MAIN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $material;
    }

    private function onHand(LibraryMaterial $material): float
    {
        return (float) DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand');
    }

    public function test_one_request_receives_many_lines_under_a_single_batch_number(): void
    {
        $first = $this->material('Screws 4x40');
        $second = $this->material('Wood glue');

        $response = $this->postJson('/api/procurement-stores/movements', [
            'type' => 'receive',
            'lines' => [
                ['material_id' => $first->id, 'quantity' => 12, 'receipt_unit_cost' => 3],
                ['material_id' => $second->id, 'quantity' => 5, 'receipt_unit_cost' => 40],
            ],
        ])->assertOk();

        $response->assertJsonPath('lines_posted', 2);
        $this->assertSame(12.0, $this->onHand($first));
        $this->assertSame(5.0, $this->onHand($second));

        // One posting is one event, so the ledger shows it as one batch.
        $batches = InventoryLog::whereIn('material_id', [$first->id, $second->id])
            ->pluck('batch_number')->unique();
        $this->assertCount(1, $batches);
        $this->assertSame($response->json('batch_number'), $batches->first());
    }

    public function test_a_single_line_is_accepted_without_being_wrapped(): void
    {
        $material = $this->material('Sandpaper');

        $this->postJson('/api/procurement-stores/movements', [
            'type' => 'receive',
            'material_id' => $material->id,
            'quantity' => 8,
        ])->assertOk()->assertJsonPath('lines_posted', 1);

        $this->assertSame(8.0, $this->onHand($material));
    }

    /**
     * The reason the whole posting is one transaction: a delivery that posted
     * six of its twelve lines, with no record of which six, is the state that
     * made people count the shelf twice.
     */
    public function test_one_bad_line_rolls_the_whole_posting_back(): void
    {
        $good = $this->material('Good line');
        $draft = $this->material('Unfinished item', 0, ['item_status' => 'Under Review']);

        $this->postJson('/api/procurement-stores/movements', [
            'type' => 'receive',
            'lines' => [
                ['material_id' => $good->id, 'quantity' => 10],
                ['material_id' => $draft->id, 'quantity' => 3],
            ],
        ])->assertStatus(422);

        $this->assertSame(0.0, $this->onHand($good), 'The valid line must not survive its neighbour failing.');
        $this->assertSame(0, InventoryLog::count());
    }

    /** A fifteen-line receipt must say which row it is complaining about. */
    public function test_a_rejected_line_is_named_by_its_position(): void
    {
        $fine = $this->material('Ordinary stock');
        $lotted = $this->material('Adhesive batch', 0, ['is_batch_controlled' => true]);

        $this->postJson('/api/procurement-stores/movements', [
            'type' => 'receive',
            'lines' => [
                ['material_id' => $fine->id, 'quantity' => 2],
                ['material_id' => $lotted->id, 'quantity' => 4],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.1.lot_number');
    }

    /**
     * The point of the consolidation. Batch receiving used to accept no lot
     * number at all, so a batch-controlled material could enter stock through
     * it with no lot recorded — the single receipt refused the same thing.
     */
    public function test_a_batch_is_held_to_the_same_control_rules_as_a_single_line(): void
    {
        $lotted = $this->material('Resin', 0, ['is_batch_controlled' => true, 'is_expiry_controlled' => true]);

        $this->postJson('/api/procurement-stores/batch-check-in', [
            'items' => [['material_id' => $lotted->id, 'quantity' => 6]],
        ])->assertStatus(422);

        $this->assertSame(0.0, $this->onHand($lotted));

        // And it succeeds once the lot and expiry it always needed are given.
        $this->postJson('/api/procurement-stores/batch-check-in', [
            'items' => [[
                'material_id' => $lotted->id, 'quantity' => 6,
                'lot_number' => 'LOT-9931', 'expiry_date' => now()->addYear()->toDateString(),
            ]],
        ])->assertOk();

        $this->assertSame(6.0, $this->onHand($lotted));
        $this->assertSame('LOT-9931', InventoryLog::where('material_id', $lotted->id)->value('lot_number'));
    }

    public function test_issuing_more_than_the_shelf_holds_is_refused(): void
    {
        $material = $this->material('Hinges', 4);

        $this->postJson('/api/procurement-stores/movements', [
            'type' => 'issue',
            'lines' => [['material_id' => $material->id, 'quantity' => 9, 'recipient_name' => 'Joseph']],
        ])->assertStatus(422);

        $this->assertSame(4.0, $this->onHand($material));
    }

    public function test_issuing_reduces_the_balance_and_records_who_took_it(): void
    {
        $material = $this->material('Hinges', 10);

        $this->postJson('/api/procurement-stores/movements', [
            'type' => 'issue',
            'lines' => [['material_id' => $material->id, 'quantity' => 3, 'recipient_name' => 'Achieng']],
        ])->assertOk();

        $this->assertSame(7.0, $this->onHand($material));
        $this->assertSame('Achieng', InventoryLog::where('material_id', $material->id)->value('recipient_name'));
    }

    /** A write-off with no account of what happened is an unexplained hole. */
    public function test_a_write_off_must_say_what_happened(): void
    {
        $material = $this->material('Cracked panel', 6);

        $this->postJson('/api/procurement-stores/movements', [
            'type' => 'damage',
            'lines' => [['material_id' => $material->id, 'quantity' => 2]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.notes');

        $this->postJson('/api/procurement-stores/movements', [
            'type' => 'damage',
            'lines' => [['material_id' => $material->id, 'quantity' => 2, 'notes' => 'Dropped off the rack in the yard.']],
        ])->assertOk();

        $this->assertSame(4.0, $this->onHand($material));
    }

    public function test_an_unknown_movement_type_is_refused(): void
    {
        $material = $this->material('Anything', 5);

        $this->postJson('/api/procurement-stores/movements', [
            'type' => 'transmute',
            'lines' => [['material_id' => $material->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_only_stores_staff_may_post_a_movement(): void
    {
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider->fresh());
        $material = $this->material('Off limits', 5);

        $this->postJson('/api/procurement-stores/movements', [
            'type' => 'issue',
            'lines' => [['material_id' => $material->id, 'quantity' => 1]],
        ])->assertStatus(403);
    }

    public function test_bulk_stock_settings_apply_one_decision_to_many_materials(): void
    {
        $first = $this->material('Shelf A item', 3);
        $second = $this->material('Shelf A other', 1);

        $this->postJson('/api/procurement-stores/bulk-stock-settings', [
            'material_ids' => [$first->id, $second->id],
            'min_stock_level' => 15,
            'location_bin' => 'A-04-02',
        ])->assertOk()->assertJsonPath('updated', 2);

        foreach ([$first, $second] as $material) {
            $stock = DB::table('stocks')->where('material_id', $material->id)->first();
            $this->assertSame(15.0, (float) $stock->min_stock_level);
            $this->assertSame('A-04-02', $stock->location_bin);
        }
    }

    /** A request that names nothing to change must say so, not report success. */
    public function test_bulk_stock_settings_refuse_an_empty_change(): void
    {
        $material = $this->material('Untouched', 2);

        $this->postJson('/api/procurement-stores/bulk-stock-settings', [
            'material_ids' => [$material->id],
        ])->assertStatus(422)->assertJsonValidationErrors('settings');
    }
}
