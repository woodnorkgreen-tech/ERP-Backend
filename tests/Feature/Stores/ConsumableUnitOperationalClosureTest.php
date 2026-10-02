<?php
namespace Tests\Feature\Stores;
use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\{LibraryMaterial,UnitOfMeasure};
use App\Modules\ProcurementStores\Models\{ConsumableUnit,ConsumableUnitCountReview,ConsumableUnitValuationRepair,InventoryLog,Stock};
use App\Modules\ProcurementStores\Services\{ConsumableUnitService,InventoryService,StockMovementPoster,StoresValuationReadinessService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Event};
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class ConsumableUnitOperationalClosureTest extends TestCase {
    use RefreshDatabase;
    private User $maker;
    private User $reviewer;
    private LibraryMaterial $material;
    private ConsumableUnitService $service;
    protected function setUp(): void {
        parent::setUp();
        $this->maker=User::factory()->create(['is_active'=>true]); $this->reviewer=User::factory()->create(['is_active'=>true]);
        foreach ([Permissions::STORES_VIEW,Permissions::STORES_MANAGE,Permissions::STORES_REVIEW,Permissions::STORES_ADJUST_QUANTITY,Permissions::MATERIALS_LIBRARY_MANAGE,Permissions::MATERIALS_LIBRARY_VIEW] as $p) {
            $permission=Permission::findOrCreate($p); $this->maker->givePermissionTo($permission); $this->reviewer->givePermissionTo($permission);
        }
        Sanctum::actingAs($this->maker);
        Event::fake([\App\Events\Stores\StockIssued::class,\App\Events\Stores\StockReturned::class]);
        $ws=DB::table('workstations')->insertGetId(['name'=>'Closure stores','code'=>'CLOSURE','created_at'=>now(),'updated_at'=>now()]);
        $uom=UnitOfMeasure::firstOrCreate(['code'=>'m'],['name'=>'Metres','dimension'=>'length','is_active'=>true]);
        $this->material=LibraryMaterial::create(['workstation_id'=>$ws,'material_name'=>'General fabric','material_code'=>'FAB-70B','unit_of_measure'=>'m','base_uom_id'=>$uom->id,'item_status'=>'Active','is_active'=>true,'tracking_mode'=>'consumable_unit','issue_disposition'=>'consumed','unit_cost'=>'999']);
        $this->service=app(ConsumableUnitService::class);
    }
    private function receive(?string $cost='200'): ConsumableUnit {
        app(StockMovementPoster::class)->post('receive',['material_id'=>$this->material->id,'quantity'=>'50','receipt_unit_cost'=>$cost,'controlled_units'=>[['quantity'=>'50']],'reference_no'=>'GRN-70B']);
        return ConsumableUnit::where('material_id',$this->material->id)->firstOrFail();
    }
    private function recordCount(ConsumableUnit $unit,string $quantity='49.3') {
        return $this->service->count($unit,$quantity,'Tape measure and photo evidence',$this->maker->id);
    }
    private function reviewUrl($unit,$count): string { return '/api/procurement-stores/consumable-units/'.$unit->id.'/counts/'.$count->id.'/review'; }
    private function repairUrl($unit): string { return '/api/procurement-stores/consumable-units/'.$unit->id.'/valuation-repair'; }
    private function evidence(): array { return ['source'=>'APPROVED_RECEIPT_VALUATION','unit_cost'=>'200','evidence_reference'=>'GRN-70B approved valuation','evidence'=>'Supplier invoice and approved receipt valuation per metre','reason'=>'Receipt source cost was omitted']; }
    public function test_catalogue_create_and_edit_persist_canonical_tracking(): void {
        $r=$this->postJson('/api/materials-library/materials',['material_name'=>'Neutral description','tracking_mode'=>'consumable_unit','issue_disposition'=>'consumed','base_uom_id'=>$this->material->base_uom_id,'material_code'=>'NEUTRAL-70B'])->assertCreated();
        $id=$r->json('data.id'); $this->assertSame('CONSUMABLE_UNIT',LibraryMaterial::findOrFail($id)->tracking_method);
        $this->putJson('/api/materials-library/materials/'.$id,['material_name'=>'Edited description','tracking_mode'=>'consumable_unit','issue_disposition'=>'consumed'])->assertOk();
        $this->assertSame('CONSUMABLE_UNIT',LibraryMaterial::findOrFail($id)->tracking_method);
    }
    public function test_stock_and_history_block_tracking_change(): void {
        $unit=$this->receive();
        $this->putJson('/api/materials-library/materials/'.$this->material->id,['tracking_mode'=>'bulk_quantity','issue_disposition'=>'consumed'])->assertUnprocessable()->assertJsonValidationErrors('tracking_mode');
        $this->assertSame('CONSUMABLE_UNIT',$this->material->fresh()->tracking_method);
        $bulk=LibraryMaterial::create(['material_name'=>'Bulk','material_code'=>'BULK-70B','tracking_mode'=>'bulk_quantity','issue_disposition'=>'consumed']);
        Stock::create(['material_id'=>$bulk->id,'quantity_on_hand'=>'10','quantity_reserved'=>0,'warehouse_code'=>'MAIN']);
        $this->putJson('/api/materials-library/materials/'.$bulk->id,['tracking_mode'=>'consumable_unit','issue_disposition'=>'consumed'])->assertUnprocessable();
    }
    public function test_receipt_result_contains_unique_composed_references(): void {
        $r=$this->postJson('/api/procurement-stores/movements',['type'=>'receive','lines'=>[['material_id'=>$this->material->id,'quantity'=>'99.5','receipt_unit_cost'=>'200','controlled_units'=>[['quantity'=>'50'],['quantity'=>'49.5']]]]])->assertOk();
        $units=$r->json('controlled_units'); $this->assertCount(2,$units); $this->assertNotSame($units[0]['unit_code'],$units[1]['unit_code']);
        $this->assertSame('FAB-70B / '.$units[0]['unit_code'],$units[0]['operational_reference']);
    }
    public function test_self_approval_is_rejected_without_moving_stock(): void {
        $u=$this->receive();$c=$this->recordCount($u);
        $this->postJson($this->reviewUrl($u,$c),['decision'=>'APPROVED','reason'=>'Verified physical count'])->assertUnprocessable()->assertJsonValidationErrors('reviewer');
        $this->assertSame('50.000000',$u->fresh()->remaining_quantity);$this->assertDatabaseCount('consumable_unit_count_reviews',0);
    }
    public function test_negative_variance_posts_immutable_movement_and_reconciles_without_project_cost(): void {
        $u=$this->receive();$c=$this->recordCount($u); Sanctum::actingAs($this->reviewer);
        $r=$this->postJson($this->reviewUrl($u,$c),['decision'=>'APPROVED','reason'=>'Verified physical shortage'])->assertCreated()->assertJsonPath('data.resulting_quantity','49.300000');
        $log=InventoryLog::findOrFail($r->json('data.adjustment_log_id')); $this->assertSame('adjustment',$log->type);$this->assertSame('-0.700000',$log->quantity);$this->assertSame('-140.00',$log->movement_value);$this->assertNull($log->project_id);
        $this->assertSame('9860.00',$u->fresh()->remaining_value);$this->assertSame('RECONCILED',$this->service->summary($this->material)['reconciliation_status']);
        $this->assertSame('REQUIRES_REVIEW',$c->fresh()->status);$this->assertSame(0,$this->service->indicators()['count_variances']);
        $this->assertDatabaseHas('consumable_unit_movements',['inventory_log_id'=>$log->id,'type'=>'count_adjustment','quantity'=>'-0.7']);
        Event::assertNotDispatched(\App\Events\Stores\StockIssued::class);Event::assertNotDispatched(\App\Events\Stores\StockReturned::class);
        $this->postJson($this->reviewUrl($u,$c),['decision'=>'APPROVED','reason'=>'Try duplicate approval'])->assertUnprocessable();
        $this->expectException(\DomainException::class);ConsumableUnitCountReview::first()->update(['reason'=>'Rewrite']);
    }
    public function test_positive_variance_uses_unit_cost_and_existing_adjustment_accounts(): void {
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder::class);
        $u=$this->receive();app(InventoryService::class)->adjustStock($this->material->id,'-10','check_out',['consumable_unit_id'=>$u->id]);
        $c=$this->recordCount($u->fresh(),'40.5');Sanctum::actingAs($this->reviewer);
        $r=$this->postJson($this->reviewUrl($u,$c),['decision'=>'APPROVED','reason'=>'Verified measured surplus'])->assertCreated()->assertJsonPath('data.accounting_status','POSTED');
        $log=InventoryLog::find($r->json('data.adjustment_log_id'));$this->assertSame('0.500000',$log->quantity);$this->assertSame('100.00',$log->movement_value);
        $this->assertSame('8100.00',$u->fresh()->remaining_value);$this->assertSame('RECONCILED',$this->service->summary($this->material)['reconciliation_status']);
        $this->assertNotNull($r->json('data.journal_entry_id'));
        $journal=\App\Modules\Finance\Models\JournalEntry::find($r->json('data.journal_entry_id'));$this->assertSame(InventoryLog::class,$journal->source_type);
        $this->assertDatabaseMissing('cost_lines',['source_type'=>InventoryLog::class,'source_id'=>$log->id]);
    }
    public function test_rejection_preserves_count_and_balance(): void {
        $u=$this->receive();$c=$this->recordCount($u);Sanctum::actingAs($this->reviewer);
        $this->postJson($this->reviewUrl($u,$c),['decision'=>'REJECTED','reason'=>'Measurement evidence insufficient'])->assertCreated()->assertJsonPath('data.adjustment_log_id',null);
        $this->assertSame('50.000000',$u->fresh()->remaining_quantity);$this->assertDatabaseCount('inventory_logs',1);$this->assertSame('REQUIRES_REVIEW',$c->fresh()->status);
    }
    public function test_stale_count_and_out_of_bounds_observation_are_rejected(): void {
        $u=$this->receive();$c=$this->recordCount($u);app(InventoryService::class)->adjustStock($this->material->id,'-1','check_out',['consumable_unit_id'=>$u->id]);Sanctum::actingAs($this->reviewer);
        $this->postJson($this->reviewUrl($u,$c),['decision'=>'APPROVED','reason'=>'Stale measurement correction'])->assertUnprocessable();
        $c=$this->recordCount($u->fresh(),'51');$this->postJson($this->reviewUrl($u,$c),['decision'=>'APPROVED','reason'=>'Observed beyond receipt size'])->assertUnprocessable();
        $this->assertDatabaseCount('consumable_unit_count_reviews',0);
    }
    public function test_unvalued_count_does_not_fabricate_value(): void {
        $u=$this->receive(null);$c=$this->recordCount($u);Sanctum::actingAs($this->reviewer);
        $r=$this->postJson($this->reviewUrl($u,$c),['decision'=>'APPROVED','reason'=>'Verified physical shortage'])->assertCreated()->assertJsonPath('data.accounting_status','UNVALUED');
        $this->assertNull(InventoryLog::find($r->json('data.adjustment_log_id'))->movement_value);$this->assertNull($u->fresh()->remaining_value);
        $this->assertSame('UNVALUED',$this->service->summary($this->material)['readiness']);
    }
    public function test_review_and_repair_permissions_are_enforced(): void {
        $u=$this->receive(null);$c=$this->recordCount($u);$other=User::factory()->create(['is_active'=>true]);Sanctum::actingAs($other);
        $this->postJson($this->reviewUrl($u,$c),['decision'=>'APPROVED','reason'=>'Physical verified'])->assertForbidden();
        $this->postJson($this->repairUrl($u),$this->evidence())->assertForbidden();
        $other->givePermissionTo(Permissions::STORES_REVIEW);
        $this->postJson($this->repairUrl($u),$this->evidence())->assertForbidden();
        $this->postJson($this->reviewUrl($u,$c),['decision'=>'APPROVED','reason'=>'Physical verified'])->assertForbidden();
        $this->postJson($this->reviewUrl($u,$c),['decision'=>'REJECTED','reason'=>'Evidence is insufficient'])->assertCreated();
    }
    public function test_clean_unvalued_receipt_repair_is_audited_and_restores_readiness(): void {
        $u=$this->receive(null);$this->assertTrue($this->service->repairEligibility($u)['eligible']);
        $this->postJson($this->repairUrl($u),$this->evidence())->assertCreated()->assertJsonPath('data.previous_unit_cost',null)->assertJsonPath('data.new_unit_cost','200.00000000');
        $this->assertSame('10000.00',$u->fresh()->remaining_value);$this->assertSame('VALUED',$this->service->summary($this->material)['readiness']);
        $readiness=app(StoresValuationReadinessService::class)->project()['data']->firstWhere('material_id',$this->material->id);$this->assertSame('VALUED',$readiness['classification']);
        $this->assertDatabaseCount('inventory_logs',1);Event::assertNotDispatched(\App\Events\Stores\StockIssued::class);
        $this->postJson($this->repairUrl($u),$this->evidence())->assertUnprocessable();
        $this->expectException(\DomainException::class);ConsumableUnitValuationRepair::first()->delete();
    }
    public function test_repair_requires_source_evidence_and_never_catalogue_cost(): void {
        $u=$this->receive(null);
        $data=$this->evidence();unset($data['evidence']);$this->postJson($this->repairUrl($u),$data)->assertUnprocessable();
        $data=$this->evidence();$data['source']='RECEIPT_SOURCE';$this->postJson($this->repairUrl($u),$data)->assertUnprocessable();
        $this->assertNull($u->fresh()->unit_cost);$this->assertDatabaseCount('consumable_unit_valuation_repairs',0);
    }
    public function test_repair_prefers_existing_authoritative_receipt_snapshot(): void {
        $u=$this->receive(null);InventoryLog::whereKey($u->source_log_id)->update(['receipt_unit_cost'=>'125.25']);
        $data=$this->evidence();$data['unit_cost']='200';$this->postJson($this->repairUrl($u),$data)->assertUnprocessable();
        unset($data['unit_cost']);$this->postJson($this->repairUrl($u),$data)->assertCreated()->assertJsonPath('data.source','RECEIPT_SOURCE')->assertJsonPath('data.new_unit_cost','125.25000000');
    }
    public function test_economic_movement_requires_accounting_correction_and_does_not_rewrite_cost(): void {
        $u=$this->receive(null);$u->movements()->create(['actor_id'=>$this->maker->id,'type'=>'check_out','quantity'=>'-1','balance_before'=>'50','balance_after'=>'49','value'=>null,'created_at'=>now()]);
        $this->assertSame('ACCOUNTING CORRECTION REQUIRED',$this->service->repairEligibility($u)['blocked_reason']);
        $this->postJson($this->repairUrl($u),$this->evidence())->assertUnprocessable()->assertJsonValidationErrors('valuation');$this->assertNull($u->fresh()->unit_cost);
        $this->assertDatabaseCount('consumable_unit_valuation_repairs',0);
    }
    public function test_identity_and_source_are_immutable(): void {
        $u=$this->receive();$this->expectException(\DomainException::class);$u->update(['unit_code'=>'CU-REPLACED']);
    }
}
