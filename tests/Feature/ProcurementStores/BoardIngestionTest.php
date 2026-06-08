<?php

namespace Tests\Feature\ProcurementStores;

use Tests\TestCase;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\Workstation;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\BoardMovement;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Services\BoardIngestionService;
use App\Modules\ProcurementStores\Services\BoardRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BoardIngestionTest extends TestCase
{
    use RefreshDatabase;

    private BoardIngestionService    $service;
    private BoardRegistrationService $registration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registration = new BoardRegistrationService();
        $this->service      = new BoardIngestionService($this->registration);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function makeMaterial(array $overrides = []): LibraryMaterial
    {
        $workstation = Workstation::firstOrCreate(['code' => 'CNC'], ['name' => 'CNC Router']);

        return LibraryMaterial::create(array_merge([
            'workstation_id'  => $workstation->id,
            'material_code'   => 'MAT-' . uniqid(),
            'material_name'   => 'Test Material',
            'category'        => 'Boards',
            'material_type'   => 'reusable',
            'unit_of_measure' => 'm²',
            'unit_cost'       => 150.00,
        ], $overrides));
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    public function test_material_type_field_exists(): void
    {
        $material = $this->makeMaterial(['material_type' => 'reusable']);
        $this->assertEquals('reusable', $material->material_type);
    }

    public function test_consumable_material_cannot_ingest_boards(): void
    {
        $material = $this->makeMaterial([
            'material_type' => 'consumable',
            'category'      => 'Hardware',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only reusable materials can be board-tracked');

        $this->service->ingestBatch(
            libraryMaterialId: $material->id,
            quantity:          5,
            batchNumber:       'TEST-001'
        );
    }

    public function test_reusable_board_material_ingests_successfully(): void
    {
        $material = $this->makeMaterial();

        $boards = $this->service->ingestBatch(
            libraryMaterialId: $material->id,
            quantity:          3,
            batchNumber:       'MDF-2026-0528'
        );

        $this->assertCount(3, $boards);
        $this->assertEquals('Available', $boards[0]->status);
        $this->assertEquals(150.00, $boards[0]->current_value);
        $this->assertDatabaseHas('board_movements', ['board_id' => $boards[0]->id]);
    }

    public function test_non_eligible_category_cannot_ingest(): void
    {
        $material = $this->makeMaterial([
            'category'      => 'Tools',
            'material_type' => 'reusable',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not board-eligible');

        $this->service->ingestBatch(
            libraryMaterialId: $material->id,
            quantity:          2,
            batchNumber:       'TOOL-001'
        );
    }

    public function test_board_status_transitions_validate(): void
    {
        $material = $this->makeMaterial();

        $board = Board::create([
            'tracking_code'       => 'WNG-TEST-2026-0001',
            'library_material_id' => $material->id,
            'batch_number'        => 'TEST-001',
            'length'              => 2440,
            'width'               => 1220,
            'thickness'           => 18,
            'status'              => 'Available',
            'current_value'       => 100.00,
        ]);

        $board->transitionTo('Allocated');
        $this->assertEquals('Allocated', $board->fresh()->status);

        $board->transitionTo('At Station');
        $this->assertEquals('At Station', $board->fresh()->status);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot transition board');
        $board->transitionTo('Available');
    }

    public function test_ingestion_sets_stock_tracking_mode_to_individual(): void
    {
        $material = $this->makeMaterial();

        $this->service->ingestBatch(
            libraryMaterialId: $material->id,
            quantity:          2,
            batchNumber:       'STOCK-001'
        );

        $stock = Stock::where('material_id', $material->id)->firstOrFail();
        $this->assertEquals(Stock::TRACK_BY_AREA(), $stock->tracking_mode);
    }

    public function test_board_ingestion_uses_config_defaults(): void
    {
        $material = $this->makeMaterial();

        $boards = $this->service->ingestBatch(
            libraryMaterialId: $material->id,
            quantity:          1,
            batchNumber:       'DIM-001'
        );

        $this->assertEquals(2440, $boards[0]->length);
        $this->assertEquals(1220, $boards[0]->width);
        $this->assertEquals(18,   $boards[0]->thickness);
    }

    public function test_offcut_generation_proportional_value(): void
    {
        $material = $this->makeMaterial(['unit_cost' => 200.00]);

        $board = Board::create([
            'tracking_code'       => 'WNG-TEST-2026-0002',
            'library_material_id' => $material->id,
            'batch_number'        => 'OFF-001',
            'length'              => 2000,
            'width'               => 1000,
            'thickness'           => 18,
            'status'              => 'WIP',
            'current_value'       => 200.00,
        ]);

        // Offcut is half the parent area (1000×1000 vs 2000×1000)
        $offcut = $this->registration->registerOffcut($board, 1000, 1000, 18);

        $this->assertTrue($board->fresh()->hasStatus('Consumed'));
        $this->assertTrue($offcut->hasStatus('Available'));
        $this->assertTrue($offcut->is_offcut);
        $this->assertEquals(100.00, $offcut->current_value);
    }

    public function test_batch_increments_stock_quantity(): void
    {
        $material = $this->makeMaterial();

        $this->service->ingestBatch($material->id, 5, 'BATCH-A');
        $this->service->ingestBatch($material->id, 3, 'BATCH-B');

        $stock = Stock::where('material_id', $material->id)->firstOrFail();
        $this->assertEquals(8, $stock->quantity_on_hand);
    }

    public function test_tracking_codes_are_unique_within_batch(): void
    {
        $material = $this->makeMaterial();

        $boards = $this->service->ingestBatch($material->id, 5, 'UNIQ-001');

        $codes = array_map(fn($b) => $b->tracking_code, $boards);
        $this->assertCount(5, array_unique($codes));
    }
}
