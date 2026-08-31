<?php

namespace Tests\Feature\Stores;

use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The stock balance is only trustworthy if the movement ledger fully explains
 * it. Two things used to break that: adjustStock had no floor inside its lock
 * (callers tested sufficiency with an unlocked read taken beforehand), and
 * updateStockSettings assigned quantity_on_hand directly without recording a
 * movement at all. These tests pin both.
 */
class StockLedgerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private LibraryMaterial $material;
    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Stores', 'Manager', 'Super Admin'] as $role) {
            Role::findOrCreate($role);
        }

        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'General', 'code' => 'WS-GEN-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->material = LibraryMaterial::create([
            'workstation_id' => $workstationId,
            'material_name' => 'Contact Adhesive 5L',
            'material_code' => 'MAT-ADH-' . uniqid(),
            'category' => 'Consumables',
            'material_type' => 'consumable',
            'tracking_mode' => 'bulk_quantity',
            'issue_disposition' => 'consumed',
            'unit_of_measure' => 'tin',
            'unit_cost' => 0,
            'item_status' => 'Active',
        ]);

        $this->storekeeper = User::factory()->create();
        $this->storekeeper->assignRole('Stores');

        // ->fresh() so the role assignment above is loaded on the acting model.
        // inventory_logs.user_id is NOT NULL and adjustStock stamps Auth::id(),
        // so even the direct service calls below need an authenticated actor.
        Sanctum::actingAs($this->storekeeper->fresh());
    }

    private function receive(float $quantity): void
    {
        app(InventoryService::class)->adjustStock($this->material->id, $quantity, 'check_in', []);
    }

    private function onHand(): float
    {
        return (float) Stock::where('material_id', $this->material->id)->value('quantity_on_hand');
    }

    /** The ledger sum must always equal the stock balance. */
    private function assertLedgerReconciles(): void
    {
        $ledger = (float) InventoryLog::where('material_id', $this->material->id)
            ->whereIn('type', ['check_in', 'check_out', 'return', 'defective', 'adjustment'])
            ->sum('quantity');

        $this->assertEqualsWithDelta(
            $ledger,
            $this->onHand(),
            0.001,
            'Stock balance no longer reconciles to its own movement ledger.',
        );
    }

    public function test_an_issue_cannot_take_stock_below_zero(): void
    {
        $this->receive(5);

        $this->expectException(ValidationException::class);

        try {
            app(InventoryService::class)->adjustStock($this->material->id, -6, 'check_out', []);
        } finally {
            // The whole movement is refused; nothing is partially applied.
            $this->assertSame(5.0, $this->onHand());
            $this->assertLedgerReconciles();
        }
    }

    public function test_an_issue_cannot_consume_reserved_stock(): void
    {
        $this->receive(10);
        Stock::where('material_id', $this->material->id)->update(['quantity_reserved' => 4]);

        $this->expectException(ValidationException::class);

        try {
            app(InventoryService::class)->adjustStock($this->material->id, -7, 'check_out', []);
        } finally {
            $this->assertSame(10.0, $this->onHand());
        }
    }

    public function test_an_issue_down_to_the_reserved_floor_is_allowed(): void
    {
        $this->receive(10);
        Stock::where('material_id', $this->material->id)->update(['quantity_reserved' => 4]);

        app(InventoryService::class)->adjustStock($this->material->id, -6, 'check_out', []);

        $this->assertSame(4.0, $this->onHand());
        $this->assertLedgerReconciles();
    }

    public function test_check_out_endpoint_refuses_to_oversell_and_reports_what_is_issuable(): void
    {
        $this->receive(3);
        $response = $this->postJson('/api/procurement-stores/check-out', [
            'material_id' => $this->material->id,
            'quantity' => 4,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->assertStringContainsString('3 issuable', $response->json('message'));
        $this->assertSame(3.0, $this->onHand());
        $this->assertLedgerReconciles();
    }

    public function test_setting_a_counted_balance_posts_an_adjustment_movement(): void
    {
        $this->receive(10);
        $response = $this->postJson('/api/procurement-stores/update-settings', [
            'material_id' => $this->material->id,
            'stock_quantity' => 7,
            'stock_adjustment_reason' => 'Physical count — three tins damaged in storage',
        ]);

        $response->assertOk();
        $this->assertSame(7.0, $this->onHand());

        $adjustment = InventoryLog::where('material_id', $this->material->id)
            ->where('type', 'adjustment')->sole();
        $this->assertSame(-3.0, (float) $adjustment->quantity);
        $this->assertStringContainsString('damaged in storage', $adjustment->notes);

        $this->assertLedgerReconciles();
    }

    public function test_a_counted_balance_without_a_reason_is_refused(): void
    {
        $this->receive(10);
        $this->postJson('/api/procurement-stores/update-settings', [
            'material_id' => $this->material->id,
            'stock_quantity' => 7,
        ])->assertStatus(422)->assertJsonValidationErrors('stock_adjustment_reason');

        $this->assertSame(10.0, $this->onHand());
    }

    public function test_saving_settings_without_changing_the_balance_needs_no_reason(): void
    {
        $this->receive(10);
        // The form always posts the current figure back; that is not an adjustment.
        $this->postJson('/api/procurement-stores/update-settings', [
            'material_id' => $this->material->id,
            'stock_quantity' => 10,
            'min_stock_level' => 4,
        ])->assertOk();

        $this->assertSame(0, InventoryLog::where('material_id', $this->material->id)
            ->where('type', 'adjustment')->count());
        $this->assertSame(4.0, (float) Stock::where('material_id', $this->material->id)->value('min_stock_level'));
        $this->assertLedgerReconciles();
    }
}
