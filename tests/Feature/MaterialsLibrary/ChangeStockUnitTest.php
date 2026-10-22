<?php

namespace Tests\Feature\MaterialsLibrary;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A stock unit can change after stock has moved only as a re-measurement of
 * the same stock: every stock-unit quantity is restated by the factor, costs
 * by its inverse, history keeps what was typed, and nothing is posted.
 */
class ChangeStockUnitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = User::factory()->create(['is_active' => true]);
        foreach ([Permissions::MATERIALS_LIBRARY_VIEW, Permissions::MATERIALS_LIBRARY_MANAGE] as $permission) {
            $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($this->user->fresh());
    }

    private function uom(string $code): int
    {
        return (int) DB::table('units_of_measure')->where('code', $code)->value('id');
    }

    /** 37 boxes on hand at KES 500 a box, after one receipt of 40 and an issue of 3. */
    private function screws(array $overrides = []): LibraryMaterial
    {
        $material = LibraryMaterial::create(array_merge([
            'material_name' => 'MDF Screws 30mm', 'material_code' => 'SCR-'.uniqid(),
            'unit_of_measure' => 'box', 'base_uom_id' => $this->uom('box'), 'issue_uom_id' => $this->uom('box'),
            'unit_cost' => 500, 'default_unit_cost' => 450, 'item_status' => 'Active', 'is_active' => true,
            'tracking_mode' => 'bulk_quantity', 'issue_disposition' => 'consumed',
        ], $overrides));
        DB::table('stocks')->insert(['material_id' => $material->id, 'quantity_on_hand' => 37, 'quantity_reserved' => 2, 'min_stock_level' => 5, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_logs')->insert([
            ['material_id' => $material->id, 'user_id' => $this->user->id, 'type' => 'check_in', 'quantity' => 40, 'receipt_unit_cost' => 500, 'balance_after' => 40, 'logged_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['material_id' => $material->id, 'user_id' => $this->user->id, 'type' => 'check_out', 'quantity' => -3, 'receipt_unit_cost' => 500, 'balance_after' => 37, 'logged_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $material;
    }

    private function change(LibraryMaterial $material, array $payload)
    {
        return $this->postJson("/api/materials-library/materials/{$material->id}/change-stock-unit", array_merge(['reason' => 'Issued by the piece on site.'], $payload));
    }

    public function test_every_stock_unit_quantity_is_restated_and_value_is_kept(): void
    {
        $material = $this->screws();
        $journals = DB::table('journal_entries')->count();

        $this->change($material, ['to_uom_id' => $this->uom('pcs'), 'factor' => 100])->assertOk()
            ->assertJsonPath('data.base_uom_id', $this->uom('pcs'));

        $stock = DB::table('stocks')->where('material_id', $material->id)->first();
        $this->assertSame(3700.0, (float) $stock->quantity_on_hand);
        $this->assertSame(200.0, (float) $stock->quantity_reserved);
        $this->assertSame(500.0, (float) $stock->min_stock_level);

        $fresh = $material->fresh();
        $this->assertSame('pcs', $fresh->unit_of_measure);
        $this->assertSame(5.0, (float) $fresh->unit_cost);
        $this->assertSame(4.5, (float) $fresh->default_unit_cost);
        $this->assertSame($this->uom('pcs'), (int) $fresh->issue_uom_id);
        $this->assertSame($this->uom('box'), (int) $fresh->purchase_uom_id, 'still bought by the box');

        $logs = DB::table('inventory_logs')->where('material_id', $material->id)->orderBy('id')->get();
        $this->assertSame([4000.0, -300.0], $logs->map(fn ($log) => (float) $log->quantity)->all());
        $this->assertSame(5.0, (float) $logs[0]->receipt_unit_cost);
        $this->assertSame(3700.0, (float) $logs[1]->balance_after);
        // What was typed survives: 40 boxes.
        $this->assertSame(40.0, (float) $logs[0]->entered_quantity);
        $this->assertSame($this->uom('box'), (int) $logs[0]->entered_uom_id);
        $this->assertSame(100.0, (float) $logs[0]->uom_conversion_factor);

        // A box still reads, as 100 pieces.
        $this->assertSame(100.0, (float) DB::table('material_uom_conversions')->where('material_id', $material->id)
            ->where('from_uom_id', $this->uom('box'))->where('to_uom_id', $this->uom('pcs'))->value('factor'));

        $change = DB::table('material_stock_unit_changes')->where('material_id', $material->id)->first();
        $this->assertSame('18500.00', (string) $change->stock_value_before);
        $this->assertSame('18500.00', (string) $change->stock_value_after);
        $this->assertSame($journals, DB::table('journal_entries')->count(), 'a re-measurement posts nothing');
    }

    public function test_existing_conversions_and_approved_project_readings_follow_the_new_unit(): void
    {
        $material = $this->screws();
        DB::table('material_uom_conversions')->insert(['material_id' => $material->id, 'from_uom_id' => $this->uom('pack'), 'to_uom_id' => $this->uom('box'), 'factor' => 0.5, 'created_at' => now(), 'updated_at' => now()]);
        // The project around the line is irrelevant here; only its reading is.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $lineId = DB::table('element_materials')->insertGetId([
            'persistent_id' => (string) \Illuminate\Support\Str::uuid(), 'project_element_id' => 0, 'library_material_id' => $material->id,
            'description' => 'Screws', 'unit_of_measurement' => 'pack', 'quantity' => 4, 'is_included' => true,
            'requirement_uom_id' => $this->uom('pack'), 'requirement_base_uom_id' => $this->uom('box'), 'requirement_base_factor' => 0.5,
            'requirement_unit_basis' => json_encode(['basis' => 'material_conversion', 'material_id' => $material->id, 'factor' => '0.500000', 'to' => 'box']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->change($material, ['to_uom_id' => $this->uom('pcs'), 'factor' => 100])->assertOk();

        $this->assertSame(50.0, (float) DB::table('material_uom_conversions')->where('material_id', $material->id)->where('from_uom_id', $this->uom('pack'))->value('factor'));
        $line = DB::table('element_materials')->find($lineId);
        $this->assertSame($this->uom('pcs'), (int) $line->requirement_base_uom_id);
        $this->assertSame(50.0, (float) $line->requirement_base_factor, '4 packs still mean 200 pieces');
    }

    public function test_piece_tracked_items_and_the_same_unit_are_refused(): void
    {
        $board = $this->screws(['material_name' => 'MDF 18mm', 'tracking_mode' => 'dimension_piece', 'issue_disposition' => 'recoverable_remainder']);
        $this->change($board, ['to_uom_id' => $this->uom('m2'), 'factor' => 2.97])->assertStatus(422)->assertJsonValidationErrors('to_uom_id');
        $this->assertSame('box', $board->fresh()->unit_of_measure);

        $screws = $this->screws(['material_name' => 'Other screws']);
        $this->change($screws, ['to_uom_id' => $this->uom('box'), 'factor' => 1])->assertStatus(422);
        $this->assertSame(37.0, (float) DB::table('stocks')->where('material_id', $screws->id)->value('quantity_on_hand'));
    }

    public function test_it_needs_catalogue_rights_and_a_reason(): void
    {
        $material = $this->screws();
        $this->change($material, ['to_uom_id' => $this->uom('pcs'), 'factor' => 100, 'reason' => ''])->assertStatus(422);

        Sanctum::actingAs(User::factory()->create(['is_active' => true])->fresh());
        $this->change($material, ['to_uom_id' => $this->uom('pcs'), 'factor' => 100])->assertStatus(403);
        $this->assertSame('box', $material->fresh()->unit_of_measure);
    }

    public function test_an_open_stock_count_is_restated_not_a_blocker(): void
    {
        $material = $this->screws();
        $countId = DB::table('stock_counts')->insertGetId(['count_number' => 'OPEN-TEST-1', 'mode' => 'opening_inventory', 'status' => 'draft', 'counted_on' => now()->toDateString(), 'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_count_items')->insert(['stock_count_id' => $countId, 'material_id' => $material->id, 'system_quantity' => 37, 'counted_quantity' => 36, 'variance_quantity' => -1, 'opening_unit_cost' => 500, 'created_at' => now(), 'updated_at' => now()]);

        $this->change($material, ['to_uom_id' => $this->uom('pcs'), 'factor' => 100])->assertOk()
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'OPEN-TEST-1'));

        $item = DB::table('stock_count_items')->where('stock_count_id', $countId)->first();
        $this->assertSame([3700.0, 3600.0, -100.0, 5.0], [(float) $item->system_quantity, (float) $item->counted_quantity, (float) $item->variance_quantity, (float) $item->opening_unit_cost]);
    }
}
