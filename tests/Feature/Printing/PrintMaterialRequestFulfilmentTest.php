<?php

namespace Tests\Feature\Printing;

use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\Printing\Models\PrintMaterialRequest;
use App\Modules\Printing\Models\PrintRoll;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Services\ReplenishmentPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PrintMaterialRequestFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private LibraryMaterial $material;
    private PrintMaterialRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Stores', 'Printing'] as $role) Role::findOrCreate($role);
        $metre = UnitOfMeasure::firstOrCreate(
            ['code' => 'm'],
            ['name' => 'Metre', 'dimension' => 'length', 'decimal_places' => 3, 'allows_fraction' => true, 'is_active' => true],
        );
        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Printing stock', 'code' => 'PRINT-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->material = LibraryMaterial::create([
            'workstation_id' => $workstationId, 'material_name' => 'Sticker roll', 'material_code' => 'STICKER-'.uniqid(),
            'category' => 'Printing', 'unit_of_measure' => 'm', 'base_uom_id' => $metre->id, 'issue_uom_id' => $metre->id,
            'unit_cost' => 250, 'item_status' => 'Active', 'is_active' => true, 'issue_disposition' => 'consumed',
            'tracking_mode' => 'bulk_quantity', 'is_serialized' => false, 'is_batch_controlled' => false, 'is_expiry_controlled' => false,
        ]);
        DB::table('stocks')->insert([
            'material_id' => $this->material->id, 'quantity_on_hand' => 70, 'quantity_reserved' => 0,
            'warehouse_code' => 'MAIN', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->request = PrintMaterialRequest::create([
            'material_id' => $this->material->id, 'requested_quantity_m' => 100,
            'status' => 'awaiting_stores', 'requested_by' => User::factory()->create()->id,
        ]);
    }

    private function storesUser(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Stores');
        Sanctum::actingAs($user->fresh());
    }

    public function test_partial_issue_moves_stock_creates_rolls_and_keeps_the_balance_open(): void
    {
        $this->storesUser();
        $response = $this->postJson("/api/printing/material-requests/{$this->request->id}/issue", [
            'rolls' => [
                ['received_length_m' => 20, 'roll_width_m' => 1.37],
                ['received_length_m' => 20, 'roll_width_m' => 1.37],
            ],
        ])->assertOk();

        $response->assertJsonPath('data.status', 'partially_fulfilled')
            ->assertJsonPath('data.fulfilled_quantity_m', 40)
            ->assertJsonPath('data.remaining_quantity_m', 60);
        $this->assertSame(30.0, (float) DB::table('stocks')->where('material_id', $this->material->id)->value('quantity_on_hand'));
        $this->assertSame(2, PrintRoll::where('print_material_request_id', $this->request->id)->count());
        $issue = InventoryLog::where('material_id', $this->material->id)->sole();
        $this->assertSame('check_out', $issue->type);
        $this->assertSame(-40.0, (float) $issue->quantity);
        $this->assertNull($issue->project_id, 'Custody transfer must not create a project expense at issue time.');

        $this->assertNull(app(ReplenishmentPlanner::class)->suggestions()->firstWhere('material_id', $this->material->id));
        $this->postJson("/api/printing/material-requests/{$this->request->id}/awaiting-purchase")
            ->assertOk()->assertJsonPath('data.status', 'partially_fulfilled');
        $suggestion = app(ReplenishmentPlanner::class)->suggestions()->firstWhere('material_id', $this->material->id);
        $this->assertNotNull($suggestion);
        $this->assertSame(30.0, (float) $suggestion['suggested_quantity']);
        $this->assertSame(60.0, (float) $suggestion['demand_sources'][0]['remaining_metres']);
    }

    public function test_request_can_be_marked_awaiting_purchase_then_fulfilled_in_another_issue(): void
    {
        $this->storesUser();
        $this->postJson("/api/printing/material-requests/{$this->request->id}/awaiting-purchase")
            ->assertOk()->assertJsonPath('data.status', 'awaiting_purchase');

        $request = $this->request->fresh();
        $this->assertNotNull($request->purchase_requested_at);
        $suggestion = app(ReplenishmentPlanner::class)->suggestions()->firstWhere('material_id', $this->material->id);
        $this->assertNotNull($suggestion);
        $this->assertSame(30.0, (float) $suggestion['suggested_quantity']);
        $this->assertSame('printing_request', $suggestion['demand_sources'][0]['source_type']);
        $this->assertSame($this->request->id, $suggestion['demand_sources'][0]['printing_request_id']);

        DB::table('stocks')->where('material_id', $this->material->id)->update(['quantity_on_hand' => 120]);
        $this->postJson("/api/printing/material-requests/{$this->request->id}/issue", [
            'rolls' => [['received_length_m' => 100, 'roll_width_m' => 1.37]],
        ])->assertOk()->assertJsonPath('data.status', 'fulfilled')->assertJsonPath('data.remaining_quantity_m', 0);

        $this->assertNull(app(ReplenishmentPlanner::class)->suggestions()->firstWhere('material_id', $this->material->id));
    }

    public function test_printing_request_does_not_reach_procurement_until_stores_marks_need_to_buy(): void
    {
        $this->assertNull(app(ReplenishmentPlanner::class)->suggestions()->firstWhere('material_id', $this->material->id));
    }

    public function test_cannot_issue_beyond_stock_or_the_request_balance(): void
    {
        $this->storesUser();
        $this->postJson("/api/printing/material-requests/{$this->request->id}/issue", [
            'rolls' => [['received_length_m' => 80, 'roll_width_m' => 1.37]],
        ])->assertUnprocessable();
        $this->assertSame(70.0, (float) DB::table('stocks')->where('material_id', $this->material->id)->value('quantity_on_hand'));

        DB::table('stocks')->where('material_id', $this->material->id)->update(['quantity_on_hand' => 200]);
        $this->postJson("/api/printing/material-requests/{$this->request->id}/issue", [
            'rolls' => [['received_length_m' => 101, 'roll_width_m' => 1.37]],
        ])->assertUnprocessable();
    }

    public function test_only_stores_roles_can_fulfil_requests(): void
    {
        $printing = User::factory()->create(['is_active' => true]);
        $printing->assignRole('Printing');
        Sanctum::actingAs($printing->fresh());
        $this->postJson("/api/printing/material-requests/{$this->request->id}/issue", [
            'rolls' => [['received_length_m' => 10, 'roll_width_m' => 1.37]],
        ])->assertForbidden();
    }
}
