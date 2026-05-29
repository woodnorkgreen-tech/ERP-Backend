<?php

namespace Tests\Feature\ProcurementStores;

use Tests\TestCase;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\Workstation;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Services\BoardIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BoardIngestionTest extends TestCase
{
    use RefreshDatabase;

    private BoardIngestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BoardIngestionService();
    }

    /** Test that material_type field exists and validates */
    public function test_material_type_field_exists(): void
    {
        $workstation = Workstation::firstOrCreate(['code' => 'CNC', 'name' => 'CNC Router']);
        $category = MaterialCategory::firstOrCreate(['name' => 'Boards']);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstation->id,
            'material_code' => 'TST-001',
            'material_name' => 'Test Material',
            'material_category_id' => $category->id,
            'material_type' => 'reusable',
            'unit_of_measure' => 'piece',
            'unit_cost' => 100.00,
        ]);

        $this->assertEquals('reusable', $material->material_type);
    }

    /** Test consumable material cannot be ingested as boards */
    public function test_consumable_material_cannot_ingest_boards(): void
    {
        $workstation = Workstation::firstOrCreate(['code' => 'GEN', 'name' => 'General']);
        $category = MaterialCategory::firstOrCreate(['name' => 'Hardware']);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstation->id,
            'material_code' => 'CONS-001',
            'material_name' => 'Consumable Item',
            'material_category_id' => $category->id,
            'material_type' => 'consumable',
            'unit_of_measure' => 'piece',
            'unit_cost' => 50.00,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only reusable materials can be board-tracked');

        $this->service->ingestBatch(
            libraryMaterialId: $material->id,
            quantity: 5,
            batchNumber: 'TEST-001'
        );
    }

    /** Test board-eligible material can be ingested */
    public function test_reusable_board_material_ingests_successfully(): void
    {
        $workstation = Workstation::firstOrCreate(['code' => 'CNC', 'name' => 'CNC Router']);
        $boardsCategory = MaterialCategory::firstOrCreate(['name' => 'Boards']);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstation->id,
            'material_code' => 'MDF-001',
            'material_name' => 'MDF Board',
            'material_category_id' => $boardsCategory->id,
            'material_type' => 'reusable',
            'unit_of_measure' => 'm²',
            'unit_cost' => 150.00,
        ]);

        $boards = $this->service->ingestBatch(
            libraryMaterialId: $material->id,
            quantity: 3,
            batchNumber: 'MDF-2026-0528'
        );

        $this->assertCount(3, $boards);
        $this->assertEquals('Available', $boards[0]->status);
        $this->assertEquals(150.00, $boards[0]->current_value);
        $this->assertTrue($boards[0]->relationships()->load('movements'));
    }

    /** Test non-board-eligible category cannot ingest */
    public function test_non_eligible_category_cannot_ingest(): void
    {
        $workstation = Workstation::firstOrCreate(['code' => 'CNC', 'name' => 'CNC Router']);
        $category = MaterialCategory::firstOrCreate(['name' => 'Tools']);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstation->id,
            'material_code' => 'TOOL-001',
            'material_name' => 'Tool Item',
            'material_category_id' => $category->id,
            'material_type' => 'reusable',
            'unit_of_measure' => 'piece',
            'unit_cost' => 200.00,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not board-eligible');

        $this->service->ingestBatch(
            libraryMaterialId: $material->id,
            quantity: 2,
            batchNumber: 'TOOL-001'
        );
    }

    /** Test board status transition validation */
    public function test_board_status_transitions_validate(): void
    {
        $workstation = Workstation::firstOrCreate(['code' => 'CNC', 'name' => 'CNC Router']);
        $boardsCategory = MaterialCategory::firstOrCreate(['name' => 'Boards']);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstation->id,
            'material_code' => 'TRANS-001',
            'material_name' => 'Test Material',
            'material_category_id' => $boardsCategory->id,
            'material_type' => 'reusable',
            'unit_of_measure' => 'm²',
            'unit_cost' => 100.00,
        ]);

        $board = Board::create([
            'library_material_id' => $material->id,
            'batch_number' => 'TEST-001',
            'length' => 2440,
            'width' => 1220,
            'thickness' => 18,
            'status' => 'Available',
            'current_value' => 100.00,
        ]);

        // Valid transition
        $board->transitionTo('Allocated');
        $this->assertEquals('Allocated', $board->fresh()->status);

        // Valid transition
        $board->transitionTo('At Station');
        $this->assertEquals('At Station', $board->fresh()->status);

        // Invalid transition
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot transition board');
        $board->transitionTo('Available');
    }

    /** Test stock tracking mode auto-set for reusable materials */
    public function test_stock_tracking_mode_auto_set_for_reusable(): void
    {
        $workstation = Workstation::firstOrCreate(['code' => 'CNC', 'name' => 'CNC Router']);
        $boardsCategory = MaterialCategory::firstOrCreate(['name' => 'Boards']);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstation->id,
            'material_code' => 'STOCK-001',
            'material_name' => 'Stock Test',
            'material_category_id' => $boardsCategory->id,
            'material_type' => 'reusable',
            'unit_of_measure' => 'm²',
            'unit_cost' => 120.00,
        ]);

        $stock = Stock::create([
            'material_id' => $material->id,
            'quantity_on_hand' => 50,
            'min_stock_level' => 10,
        ]);

        $this->assertEquals(Stock::TRACK_BY_AREA(), $stock->tracking_mode);
    }

    /** Test stock tracking mode for consumable materials */
    public function test_stock_tracking_mode_auto_set_for_consumable(): void
    {
        $workstation = Workstation::firstOrCreate(['code' => 'GEN', 'name' => 'General']);
        $category = MaterialCategory::firstOrCreate(['name' => 'Hardware']);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstation->id,
            'material_code' => 'CONS-STOCK-001',
            'material_name' => 'Consumable Stock Test',
            'material_category_id' => $category->id,
            'material_type' => 'consumable',
            'unit_of_measure' => 'box',
            'unit_cost' => 50.00,
        ]);

        $stock = Stock::create([
            'material_id' => $material->id,
            'quantity_on_hand' => 100,
            'min_stock_level' => 20,
        ]);

        $this->assertEquals(Stock::TRACK_BY_COUNT(), $stock->tracking_mode);
    }

    /** Test board dimensions use config defaults */
    public function test_board_ingestion_uses_config_defaults(): void
    {
        $workstation = Workstation::firstOrCreate(['code' => 'CNC', 'name' => 'CNC Router']);
        $boardsCategory = MaterialCategory::firstOrCreate(['name' => 'Boards']);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstation->id,
            'material_code' => 'DIM-001',
            'material_name' => 'Dimension Test',
            'material_category_id' => $boardsCategory->id,
            'material_type' => 'reusable',
            'unit_of_measure' => 'm²',
            'unit_cost' => 100.00,
        ]);

        $boards = $this->service->ingestBatch(
            libraryMaterialId: $material->id,
            quantity: 1,
            batchNumber: 'DIM-001'
            // No dimensions provided, should use config defaults
        );

        $board = $boards[0];
        $this->assertEquals(2440, $board->length);
        $this->assertEquals(1220, $board->width);
        $this->assertEquals(18, $board->thickness);
    }

    /** Test offcut generation with proportional value */
    public function test_offcut_generation_proportional_value(): void
    {
        $workstation = Workstation::firstOrCreate(['code' => 'CNC', 'name' => 'CNC Router']);
        $boardsCategory = MaterialCategory::firstOrCreate(['name' => 'Boards']);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstation->id,
            'material_code' => 'OFFCUT-001',
            'material_name' => 'Offcut Test',
            'material_category_id' => $boardsCategory->id,
            'material_type' => 'reusable',
            'unit_of_measure' => 'm²',
            'unit_cost' => 200.00,
        ]);

        $board = Board::create([
            'library_material_id' => $material->id,
            'batch_number' => 'OFF-001',
            'length' => 2000,
            'width' => 1000,
            'thickness' => 18,
            'status' => 'WIP',
            'current_value' => 200.00,
        ]);

        // Generate offcut that's 50% of parent area
        $offcut = $board->generateOffcut(1000, 1000, 18);

        $this->assertTrue($board->fresh()->is('Consumed'));
        $this->assertTrue($offcut->is('Available'));
        $this->assertTrue($offcut->is_offcut);
        // Half the area = half the value
        $this->assertEquals(100.00, $offcut->current_value);
    }
}
