<?php

namespace Tests\Feature\Stores;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\MaterialsLibrary\Support\MaterialControl;
use App\Modules\ProcurementStores\Models\{ConsumableUnit, InventoryLog, Stock};
use App\Modules\ProcurementStores\Services\{ConsumableUnitService, InventoryService, InventoryValuationService, StockMovementPoster, StoresValuationReadinessService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ConsumableUnitTest extends TestCase
{
    use RefreshDatabase;
    private LibraryMaterial $material;
    private ConsumableUnitService $units;
    private User $actor;
    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['is_active' => true]);
        foreach ([Permissions::STORES_VIEW, Permissions::STORES_MANAGE, Permissions::STORES_REVIEW, Permissions::STORES_ADJUST_QUANTITY] as $p) $this->actor->givePermissionTo(Permission::findOrCreate($p));
        Sanctum::actingAs($this->actor);
        Event::fake([\App\Events\Stores\StockIssued::class, \App\Events\Stores\StockReturned::class]);
        $ws = DB::table('workstations')->insertGetId(['name' => 'Unit Store', 'code' => 'CU-TEST', 'created_at' => now(), 'updated_at' => now()]);
        $uom = UnitOfMeasure::firstOrCreate(['code' => 'm'], ['name' => 'Metres', 'dimension' => 'length', 'is_active' => true]);
        $this->material = LibraryMaterial::create(['workstation_id' => $ws, 'material_name' => 'Controlled fabric', 'material_code' => 'FAB-CU', 'unit_of_measure' => 'm', 'base_uom_id' => $uom->id, 'item_status' => 'Active', 'is_active' => true, 'tracking_mode' => 'consumable_unit', 'issue_disposition' => 'consumed', 'unit_cost' => '999.00']);
        $this->units = app(ConsumableUnitService::class);
    }
    private function receive(array $quantities = ['50'], ?string $cost = '200'): void
    {
        app(StockMovementPoster::class)->post('receive', ['material_id' => $this->material->id, 'quantity' => \App\Modules\ProcurementStores\Services\StoresDecimal::sum($quantities), 'receipt_unit_cost' => $cost, 'controlled_units' => array_map(fn ($q) => ['quantity' => $q], $quantities)]);
    }
    private function unit(): ConsumableUnit { return ConsumableUnit::where('material_id', $this->material->id)->firstOrFail(); }
    private function issue(string $q = '12', ?ConsumableUnit $unit = null): InventoryLog
    {
        return app(InventoryService::class)->adjustStock($this->material->id, '-'.$q, 'check_out', ['consumable_unit_id' => ($unit ?? $this->unit())->id, 'recipient_name' => 'Production']);
    }
    private function returned(InventoryLog $issue, string $q, string $kind = 'whole_item'): InventoryLog
    {
        return app(StockMovementPoster::class)->post('return', ['material_id' => $this->material->id, 'quantity' => $q, 'original_issue_log_id' => $issue->id, 'return_kind' => $kind])['log'];
    }
    public function test_tracking_is_configured_without_material_name_inference(): void
    {
        $this->assertTrue(MaterialControl::compatible('consumed', 'consumable_unit'));
        $this->assertSame('CONSUMABLE_UNIT', $this->material->tracking_method);
        $this->assertFalse($this->material->isBoardTrackable());
        $this->material->update(['material_name' => 'Vinyl roll', 'tracking_mode' => 'bulk_quantity']);
        $this->assertSame('BULK', $this->material->tracking_method);
    }
    public function test_receipt_creates_five_distinct_units_in_one_ledger_receipt(): void
    {
        $this->receive(['50','50','50','50','50']);
        $this->assertDatabaseCount('consumable_units', 5);
        $this->assertDatabaseCount('inventory_logs', 1);
        $this->assertSame(5, ConsumableUnit::pluck('unit_code')->unique()->count());
        $this->assertSame('250.000000', $this->units->summary($this->material)['total_remaining']);
        $this->assertSame('10000.00', $this->unit()->original_value);
    }
    public function test_supplier_packaging_can_have_different_actual_quantities(): void
    {
        $this->receive(['50','49.5','50']);
        $this->assertSame('149.500000', $this->units->summary($this->material)['total_remaining']);
    }
    public function test_receipt_breakdown_must_reconcile_and_rolls_back_atomically(): void
    {
        try { app(InventoryService::class)->adjustStock($this->material->id, '100', 'check_in', ['controlled_units' => [['quantity'=>'50']]]); $this->fail('Expected validation'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('controlled_units', $e->errors()); }
        $this->assertDatabaseCount('consumable_units', 0); $this->assertDatabaseCount('inventory_logs', 0);
    }
    public function test_first_partial_issue_opens_roll_and_freezes_receipt_value(): void
    {
        $this->receive(); $log = $this->issue(); $unit = $this->unit();
        $this->assertSame('38.000000', $unit->remaining_quantity); $this->assertSame('OPEN', $unit->status);
        $this->assertNotNull($unit->opened_at); $this->assertSame('7600.00', $unit->remaining_value);
        $this->assertSame('2400.00', $log->movement_value); $this->assertSame('200.0000', $log->receipt_unit_cost);
    }
    public function test_decimal_consumption_and_depletion_have_no_negative_residue(): void
    {
        $this->receive(['0.3'], '10'); $this->issue('0.1'); $this->issue('0.2');
        $this->assertSame('0.000000', $this->unit()->remaining_quantity); $this->assertSame('0.00', $this->unit()->remaining_value);
        $this->assertSame('DEPLETED', $this->unit()->status); $this->assertNotNull($this->unit()->depleted_at);
    }
    public function test_multi_roll_issue_preserves_two_explicit_movement_lines(): void
    {
        $this->receive(['20', '50']); $ids = ConsumableUnit::pluck('id');
        $this->postJson('/api/procurement-stores/movements', ['type'=>'issue','lines'=>[
            ['material_id'=>$this->material->id,'consumable_unit_id'=>$ids[0],'quantity'=>'20','recipient_name'=>'Team'],
            ['material_id'=>$this->material->id,'consumable_unit_id'=>$ids[1],'quantity'=>'50','recipient_name'=>'Team']]])->assertOk()->assertJsonPath('lines_posted',2);
        $this->assertSame('0.000000', $this->units->summary($this->material)['total_remaining']);
        $this->assertSame(2, InventoryLog::where('type','check_out')->distinct()->count('consumable_unit_id'));
    }
    public function test_failed_second_roll_issue_rolls_back_entire_batch(): void
    {
        $this->receive(['20','50']); $ids=ConsumableUnit::pluck('id');
        $this->postJson('/api/procurement-stores/movements',['type'=>'issue','lines'=>[
            ['material_id'=>$this->material->id,'consumable_unit_id'=>$ids[0],'quantity'=>'20','recipient_name'=>'Team'],
            ['material_id'=>$this->material->id,'consumable_unit_id'=>$ids[1],'quantity'=>'51','recipient_name'=>'Team']]])->assertUnprocessable();
        $this->assertSame('70.000000', $this->units->summary($this->material)['total_remaining']);
    }
    public function test_negative_stock_is_rejected_under_unit_lock(): void
    {
        $this->receive(); $this->expectException(ValidationException::class); $this->issue('51');
    }
    public function test_stale_second_issue_cannot_consume_previously_read_balance(): void
    {
        $this->receive(); $stale=$this->unit(); $this->issue('40');
        try { $this->issue('40',$stale); $this->fail('Second issue must fail'); } catch (ValidationException) {}
        $this->assertSame('10.000000', $this->unit()->remaining_quantity);
    }
    public function test_issue_requires_an_explicit_valid_unit(): void
    {
        $this->receive(); $this->postJson('/api/procurement-stores/movements',['type'=>'issue','lines'=>[['material_id'=>$this->material->id,'quantity'=>'1','recipient_name'=>'Team']]])->assertUnprocessable();
    }
    public function test_same_roll_return_restores_quantity_and_value(): void
    {
        $this->receive(); $issue=$this->issue(); $return=$this->returned($issue,'3');
        $this->assertSame('41.000000',$this->unit()->remaining_quantity); $this->assertSame('8200.00',$this->unit()->remaining_value);
        $this->assertSame('600.00',$return->movement_value); $this->assertSame($issue->id,$return->original_issue_log_id);
        Event::assertDispatched(\App\Events\Stores\StockReturned::class);
    }
    public function test_over_return_is_rejected(): void
    {
        $this->receive(); $issue=$this->issue(); $this->returned($issue,'3');
        $this->expectException(ValidationException::class); $this->returned($issue,'10');
    }
    public function test_offcut_return_creates_child_and_does_not_rejoin_parent(): void
    {
        $this->receive(); $issue=$this->issue(); $return=$this->returned($issue,'3','recovered_offcut');
        $child=ConsumableUnit::findOrFail($return->consumable_unit_id);
        $this->assertSame($this->unit()->id,$child->parent_unit_id); $this->assertSame('3.000000',$child->remaining_quantity);
        $this->assertSame('OPEN',$child->status); $this->assertSame('38.000000',$this->unit()->remaining_quantity);
        $this->assertSame('41.000000',$this->units->summary($this->material)['total_remaining']);
        $this->assertSame('POLICY REQUIRED',$this->units->summary($this->material)['offcut_policy']);
    }
    public function test_waste_requires_reason_and_has_a_movement(): void
    {
        $this->receive(); app(StockMovementPoster::class)->post('damage',['material_id'=>$this->material->id,'consumable_unit_id'=>$this->unit()->id,'quantity'=>'0.5','notes'=>'Print setup testing']);
        $this->assertSame('49.500000',$this->unit()->remaining_quantity); $this->assertDatabaseHas('consumable_unit_movements',['type'=>'defective','reason'=>'Print setup testing']);
    }
    public function test_physical_count_records_variance_without_overwriting_stock(): void
    {
        $this->receive(); $this->postJson('/api/procurement-stores/consumable-units/'.$this->unit()->id.'/counts',['physical_quantity'=>'49.3','notes'=>'Tape measure verification'])->assertCreated()->assertJsonPath('data.variance','-0.700000')->assertJsonPath('data.status','REQUIRES_REVIEW');
        $this->assertSame('50.000000',$this->unit()->remaining_quantity); $this->assertSame(1,$this->units->indicators()['count_variances']);
    }
    public function test_reconciliation_difference_is_visible_and_blocks_movement(): void
    {
        $this->receive(); DB::table('stocks')->where('material_id',$this->material->id)->update(['quantity_on_hand'=>'55']);
        $summary=$this->units->summary($this->material); $this->assertSame('DIFFERENCE',$summary['reconciliation_status']); $this->assertSame('50.000000',$summary['total_remaining']);
        $this->expectException(ValidationException::class); $this->issue();
    }
    public function test_missing_receipt_cost_is_visible_and_consumption_is_gated(): void
    {
        $this->receive(['50'],null); $this->assertNull($this->unit()->unit_cost);
        $this->assertSame('UNVALUED',$this->units->summary($this->material)['readiness']);
        $this->expectException(ValidationException::class); $this->issue();
    }
    public function test_finance_valuation_uses_unit_specific_value_instead_of_catalogue_average(): void
    {
        $this->receive(); $this->issue();
        $this->assertSame(7600.0,app(InventoryValuationService::class)->valueOf($this->material,38,null));
        $line=app(StoresValuationReadinessService::class)->project()['data']->firstWhere('material_id',$this->material->id);
        $this->assertSame('7600.00',$line['authoritative_value']); $this->assertSame('VALUED',$line['classification']);
    }
    public function test_hold_blocks_issue_and_audits_release(): void
    {
        $this->receive(); $this->units->hold($this->unit(),true,'Damaged packaging review',$this->actor->id);
        $this->assertSame('0.000000',$this->units->summary($this->material)['available_quantity']);
        try { $this->issue(); $this->fail('Held unit cannot issue'); } catch (ValidationException) {}
        $this->units->hold($this->unit(),false,'Packaging inspected clear',$this->actor->id); $this->issue();
        $this->assertDatabaseHas('consumable_unit_movements',['type'=>'release_hold']);
    }
    public function test_opening_conversion_requires_exact_physical_breakdown(): void
    {
        $this->material->update(['tracking_mode'=>'bulk_quantity']);
        Stock::create(['material_id'=>$this->material->id,'quantity_on_hand'=>'127','quantity_reserved'=>0]);
        $this->postJson('/api/procurement-stores/consumable-units/materials/'.$this->material->id.'/convert',['controlled_units'=>[['quantity'=>'27'],['quantity'=>'50'],['quantity'=>'50']],'notes'=>'Physically identified and measured rolls'])->assertCreated()->assertJsonPath('data.reconciliation_status','RECONCILED');
        $this->assertDatabaseCount('inventory_logs',0); $this->assertSame('127.000000',$this->units->summary($this->material->fresh())['total_remaining']);
    }
    public function test_conversion_cannot_silently_write_off_variance(): void
    {
        $this->material->update(['tracking_mode'=>'bulk_quantity']); Stock::create(['material_id'=>$this->material->id,'quantity_on_hand'=>'127']);
        $this->postJson('/api/procurement-stores/consumable-units/materials/'.$this->material->id.'/convert',['controlled_units'=>[['quantity'=>'50']],'notes'=>'Physical breakdown'])->assertUnprocessable();
        $this->assertSame('bulk_quantity',$this->material->fresh()->tracking_mode); $this->assertDatabaseCount('consumable_units',0);
    }
    public function test_history_is_immutable_and_master_cannot_abandon_units(): void
    {
        $this->receive(); $movement=$this->unit()->movements()->first();
        try { $movement->update(['quantity'=>'1']); $this->fail('History edit must fail'); } catch (\DomainException) {}
        try { $movement->delete(); $this->fail('History delete must fail'); } catch (\DomainException) {}
        $this->expectException(ValidationException::class); $this->material->update(['tracking_mode'=>'bulk_quantity']);
    }
    public function test_backend_permissions_apply_to_reads_counts_conversion_and_hold(): void
    {
        $this->receive(); Sanctum::actingAs(User::factory()->create(['is_active'=>true]));
        $this->getJson('/api/procurement-stores/consumable-units')->assertForbidden();
        $this->postJson('/api/procurement-stores/consumable-units/'.$this->unit()->id.'/counts',['physical_quantity'=>'50','notes'=>'Physical verification'])->assertForbidden();
        $this->postJson('/api/procurement-stores/consumable-units/'.$this->unit()->id.'/hold',['hold'=>true,'reason'=>'Damage review'])->assertForbidden();
        $this->postJson('/api/procurement-stores/consumable-units/materials/'.$this->material->id.'/convert',[])->assertForbidden();
    }
    public function test_database_unit_lock_excludes_a_second_connection(): void
    {
        $this->receive();
        $unit = $this->unit();
        ConsumableUnit::whereKey($unit->id)->lockForUpdate()->firstOrFail();
        $config = DB::connection()->getConfig();
        $other = new \PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();
        try {
            $statement = $other->prepare('SELECT id FROM consumable_units WHERE id = ? FOR UPDATE');
            $statement->execute([$unit->id]);
            $this->fail('A concurrent connection must not acquire the locked unit.');
        } catch (\PDOException $e) {
            $this->assertSame(1205, $e->errorInfo[1]);
        } finally { $other->rollBack(); }
    }

    public function test_fragmented_returns_restore_exact_issue_value(): void
    {
        $this->receive(['3'], '0.33333333');
        $issue = $this->issue('3');
        $a = $this->returned($issue, '1');
        $b = $this->returned($issue, '1');
        $c = $this->returned($issue, '1');
        $this->assertSame('1.00', \App\Modules\ProcurementStores\Services\StoresDecimal::sum([$a->movement_value, $b->movement_value, $c->movement_value], 2));
        $this->assertSame('1.00', $this->unit()->remaining_value);
    }

    public function test_grn_receipt_preserves_source_supplier_cost_and_single_confirmation(): void
    {
        $supplier = \App\Modules\ProcurementStores\Models\Supplier::create(['supplier_name' => 'Roll supplier', 'contact_person' => 'Contact', 'phone' => '0700000000', 'email' => 'roll@test.local', 'address' => 'Nairobi', 'payment_terms' => '30 days', 'status' => 'Active', 'user_id' => $this->actor->id]);
        $order = \App\Modules\ProcurementStores\Models\PurchaseOrder::create(['po_number' => 'PO-CU', 'date' => now()->toDateString(), 'supplier_id' => $supplier->id, 'due_date' => now()->addDays(7), 'delivery_address' => 'Stores', 'description' => 'Roll stock', 'total_amount' => 30000, 'status' => 'approved', 'user_id' => $this->actor->id]);
        $orderItem = \App\Modules\ProcurementStores\Models\PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'material_id' => $this->material->id, 'uom_id' => $this->material->base_uom_id, 'quantity' => '149.5', 'unit_price' => '200', 'total' => '29900']);
        $note = \App\Modules\ProcurementStores\Models\GoodsReceiptNote::create(['grn_number' => 'GRN-CU', 'date' => now(), 'purchase_order_id' => $order->id, 'batch_number' => 'BATCH-CU', 'store_location' => 'Stores', 'quality_check' => 'pass', 'store_status' => 'pending_confirmation', 'received_by' => $this->actor->id]);
        $item = \App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem::create(['goods_receipt_note_id' => $note->id, 'purchase_order_item_id' => $orderItem->id, 'material_id' => $this->material->id, 'ordered_quantity' => '149.5', 'received_quantity' => '149.5', 'condition' => 'good', 'accepted' => true, 'store_status' => 'pending', 'stock_status' => 'awaiting_stores_details']);
        $payload = ['type' => 'receive', 'material_id' => $this->material->id, 'grn_item_id' => $item->id, 'quantity' => '149.5', 'controlled_units' => [['quantity' => '50'], ['quantity' => '49.5'], ['quantity' => '50']]];
        $this->postJson('/api/procurement-stores/movements', $payload)->assertOk();
        $this->assertSame($note->id, $this->unit()->source_receipt_id);
        $this->assertSame($supplier->id, $this->unit()->supplier_id);
        $this->assertSame('200.00000000', $this->unit()->unit_cost);
        $this->assertSame('posted', $item->fresh()->stock_status);
        $this->assertSame('confirmed', $note->fresh()->store_status);
        $this->postJson('/api/procurement-stores/movements', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('consumable_units', 3);
        $this->assertDatabaseCount('inventory_logs', 1);
        $this->assertDatabaseCount('goods_receipt_notes', 1);
    }

    public function test_unopened_receipt_reversal_preserves_every_unit_history(): void
    {
        $this->receive(['50', '49.5']);
        $original = InventoryLog::where('type', 'check_in')->firstOrFail();
        app(\App\Modules\ProcurementStores\Services\StockMovementReversalService::class)->reverse($original, 'Supplier delivery correction', $this->actor->id);
        $this->assertSame('0.000000', $this->units->summary($this->material)['total_remaining']);
        $this->assertDatabaseCount('consumable_units', 2);
        $this->assertDatabaseHas('consumable_unit_movements', ['type' => 'receipt_reversal', 'quantity' => '-49.500000']);
        $this->assertDatabaseCount('inventory_logs', 2);
    }
    public function test_opened_receipt_cannot_be_reversed_and_issue_reversal_restores_roll(): void
    {
        $this->receive(); $issue = $this->issue();
        $reverse = app(\App\Modules\ProcurementStores\Services\StockMovementReversalService::class);
        try { $reverse->reverse(InventoryLog::where('type', 'check_in')->firstOrFail(), 'Supplier delivery correction', $this->actor->id); $this->fail('Consumed receipt cannot reverse'); } catch (ValidationException) {}
        $reverse->reverse($issue, 'Incorrect quantity entered', $this->actor->id);
        $this->assertSame('50.000000', $this->unit()->remaining_quantity);
        $this->assertSame('10000.00', $this->unit()->remaining_value);
        $this->assertDatabaseHas('inventory_logs', ['reversal_of_log_id' => $issue->id]);
    }

}
