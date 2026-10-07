<?php

namespace Tests\Feature\MaterialsLibrary;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\MaterialItemType;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\MaterialsLibrary\Models\Workstation;
use App\Modules\MaterialsLibrary\Services\MaterialExportService;
use App\Modules\MaterialsLibrary\Services\MaterialImportService;
use App\Modules\MaterialsLibrary\Support\MaterialWorkbook;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MaterialWorkbookTest extends TestCase
{
    use RefreshDatabase;

    private Workstation $ws;

    private MaterialCategory $category;

    private UnitOfMeasure $unit;

    private UnitOfMeasure $pack;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['is_active' => true]);
        foreach ([Permissions::MATERIALS_LIBRARY_VIEW, Permissions::MATERIALS_LIBRARY_IMPORT] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
        }
        Sanctum::actingAs($user);
        $this->ws = Workstation::create(['code' => 'EXCEL', 'name' => 'Excel workshop', 'is_active' => true]);
        $type = MaterialItemType::create(['code' => 'EXCEL', 'name' => 'Excel material type', 'is_active' => true, 'default_issue_disposition' => 'consumed', 'default_tracking_mode' => 'bulk_quantity']);
        $this->unit = UnitOfMeasure::create(['code' => 'EXPCS', 'name' => 'Excel pieces', 'dimension' => 'count', 'is_active' => true]);
        $this->pack = UnitOfMeasure::create(['code' => 'EXPACK', 'name' => 'Excel pack', 'dimension' => 'package', 'is_active' => true]);
        $this->category = MaterialCategory::create(['code' => 'EXCEL', 'name' => 'Excel category', 'item_type_id' => $type->id, 'is_active' => true, 'is_selectable' => true,
            'allowed_uoms' => ['EXPCS'], 'required_attributes' => [['key' => 'finish', 'label' => 'Finish', 'type' => 'select', 'required' => true, 'options' => ['Matte', 'Gloss']]]]);
    }

    private function material(array $overrides = []): LibraryMaterial
    {
        return LibraryMaterial::create(array_merge(['material_code' => 'EX-'.uniqid(), 'material_name' => 'Workbook item', 'workstation_id' => $this->ws->id,
            'material_category_id' => $this->category->id, 'item_type_id' => $this->category->item_type_id, 'base_uom_id' => $this->unit->id, 'issue_uom_id' => $this->unit->id,
            'unit_of_measure' => $this->unit->code, 'issue_disposition' => 'consumed', 'tracking_mode' => 'bulk_quantity', 'item_status' => 'Active', 'is_active' => true,
            'attributes' => ['attributes' => ['finish' => 'Matte', 'custom_key' => 'preserved', 'numeric_custom' => 12, 'bool_custom' => false]], 'unit_cost' => 45], $overrides));
    }

    private function import(Spreadsheet $book): array
    {
        $path = tempnam(sys_get_temp_dir(), 'workbook-').'.xlsx';
        (new Xlsx($book))->save($path);
        try {
            return app(MaterialImportService::class)->import(new \SplFileInfo($path), null);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    private function set(Spreadsheet $book, string $header, mixed $value, int $row = 2): void
    {
        $sheet = $book->getSheetByName('Materials');
        $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1')[0];
        $column = array_search($header, $headers, true) + 1;
        $sheet->setCellValueExplicit([$column, $row], (string) $value, DataType::TYPE_STRING);
    }

    public function test_renamed_downloaded_sheet_still_uses_workbook_import(): void
    {
        $material = $this->material();
        $book = app(MaterialExportService::class)->workbook();
        $this->set($book, 'Material name', 'Renamed sheet edit');
        $book->getSheetByName('Materials')->setTitle('My materials');
        $result = $this->import($book);
        $this->assertSame(1, $result['updated']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('Renamed sheet edit', $material->fresh()->material_name);
    }

    public function test_legacy_sheet_named_materials_uses_legacy_import(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Materials');
        $book->getActiveSheet()->fromArray([['SKU', 'Name', 'UOM'], ['LEGACY-EX', 'Legacy Excel material', 'EXPCS']]);
        $path = tempnam(sys_get_temp_dir(), 'legacy-').'.xlsx';
        (new Xlsx($book))->save($path);
        try {
            $result = app(MaterialImportService::class)->import(new \SplFileInfo($path), $this->ws->id);
            $this->assertSame(1, $result['created']);
            $this->assertSame([], $result['errors']);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_catalogue_paginates_all_and_workshop_lists_without_overlapping_rows(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->material(['material_name' => 'Paging Excel '.$i]);
        }
        foreach (['/api/materials-library/materials', '/api/materials-library/materials/workstation/'.$this->ws->id] as $url) {
            $first = $this->getJson($url.'?search=Paging%20Excel&page=1&sort_by=created_at');
            $first->assertOk()->assertJsonPath('total', 51)->assertJsonPath('per_page', 50)->assertJsonPath('last_page', 2)->assertJsonCount(50, 'data');
            $second = $this->getJson($url.'?search=Paging%20Excel&page=2&sort_by=created_at');
            $second->assertOk()->assertJsonPath('current_page', 2)->assertJsonCount(1, 'data');
            $this->assertSame([], array_values(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'))));
        }
    }

    public function test_upload_endpoint_does_not_report_success_when_all_rows_fail(): void
    {
        $this->material();
        $book = app(MaterialExportService::class)->workbook();
        $this->set($book, 'Material ID', '999999999');
        $path = tempnam(sys_get_temp_dir(), 'failed-upload-').'.xlsx';
        (new Xlsx($book))->save($path);
        try {
            $file = new UploadedFile($path, 'material_library.xlsx', null, null, true);
            $this->postJson('/api/materials-library/import', ['file' => $file])
                ->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('data.success', 0)->assertJsonCount(1, 'data.errors');
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_changed_category_replaces_the_unchanged_exported_item_type(): void
    {
        $material = $this->material();
        $type = MaterialItemType::create(['code' => 'NEWEX', 'name' => 'New category type', 'is_active' => true, 'default_issue_disposition' => 'consumed', 'default_tracking_mode' => 'bulk_quantity']);
        $category = MaterialCategory::create(['code' => 'NEWEX', 'name' => 'New Excel category', 'item_type_id' => $type->id, 'is_active' => true, 'is_selectable' => true, 'allowed_uoms' => ['EXPCS']]);
        $book = app(MaterialExportService::class)->workbook();
        $this->set($book, 'Category', MaterialWorkbook::choice($category));
        $result = $this->import($book);
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame($type->id, $material->fresh()->item_type_id);
        $this->assertSame($category->id, $material->fresh()->material_category_id);
    }

    public function test_category_owner_overrides_a_conflicting_workbook_item_type(): void
    {
        $material = $this->material();
        $type = MaterialItemType::create(['code' => 'WRONGEX', 'name' => 'Wrong Excel type', 'is_active' => true]);
        $book = app(MaterialExportService::class)->workbook();
        $this->set($book, 'Item type', MaterialWorkbook::choice($type));
        $result = $this->import($book);
        $this->assertSame(1, $result['success']);
        $this->assertSame([], $result['errors']);
        $this->assertSame($this->category->item_type_id, $material->fresh()->item_type_id);
    }

    public function test_wrong_stock_unit_names_the_selected_category_and_allowed_units(): void
    {
        $material = $this->material();
        $book = app(MaterialExportService::class)->workbook();
        $this->set($book, 'Stock unit', MaterialWorkbook::choice($this->pack));
        $this->set($book, 'Issue unit', MaterialWorkbook::choice($this->pack));
        $result = $this->import($book);
        $this->assertSame(0, $result['success']);
        $this->assertStringContainsString('Unit [EXPACK]', $result['errors'][0]);
        $this->assertStringContainsString('category [Excel category]', $result['errors'][0]);
        $this->assertStringContainsString('Allowed stock units: EXPCS', $result['errors'][0]);
        $this->assertSame($this->unit->id, $material->fresh()->base_uom_id);
    }

    public function test_stock_unit_and_item_type_dropdowns_use_category_specific_choices_after_save(): void
    {
        $book = app(MaterialExportService::class)->workbook(false);
        $path = tempnam(sys_get_temp_dir(), 'core-dropdowns-').'.xlsx';
        (new Xlsx($book))->save($path);
        try {
            $loaded = IOFactory::load($path);
            $sheet = $loaded->getSheetByName('Materials');
            foreach (['F2' => MaterialWorkbook::choice($this->category->itemType), 'J2' => MaterialWorkbook::choice($this->unit)] as $cell => $expected) {
                $formula = $sheet->getCell($cell)->getDataValidation()->getFormula1();
                $this->assertStringContainsString('VLOOKUP($E2', $formula);
                preg_match("/'Lists'!(\\$[A-Z]+\\$2:\\$[A-Z]+\\$[0-9]+)/", $formula, $match);
                $rows = $loaded->getSheetByName('Lists')->rangeToArray(str_replace('$', '', $match[1]));
                $choice = collect($rows)->first(fn ($row) => $row[0] === MaterialWorkbook::choice($this->category));
                $namedRange = $loaded->getNamedRange($choice[1]);
                $this->assertSame([[$expected]], $namedRange->getWorksheet()->rangeToArray(str_replace('$', '', preg_replace('/^.*!/', '', $namedRange->getRange()))));
            }
            $loaded->disconnectWorksheets();
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_uncategorized_roundtrip_preserves_registered_sheet_roll_set_area_and_liquid_units(): void
    {
        $fallback = MaterialCategory::firstOrCreate(['code' => 'UNCAT'], ['name' => 'Uncategorized']);
        $fallback->update(['name' => 'Uncategorized', 'item_type_id' => $this->category->item_type_id, 'allowed_uoms' => ['pcs'], 'is_active' => true, 'is_selectable' => true]);
        $items = [];
        foreach (['sheet' => 'area', 'set' => 'count', 'roll' => 'package', 'm2' => 'area', 'l' => 'volume'] as $code => $dimension) {
            $unit = UnitOfMeasure::firstOrCreate(['code' => $code], ['name' => $code, 'dimension' => $dimension, 'is_active' => true]);
            $items[] = $this->material(['material_category_id' => $fallback->id, 'base_uom_id' => $unit->id, 'issue_uom_id' => $unit->id, 'unit_of_measure' => $code]);
        }
        $result = $this->import(app(MaterialExportService::class)->workbook());
        $this->assertSame([], $result['errors']);
        $this->assertSame(5, $result['updated']);
        foreach ($items as $item) {
            $this->assertSame($item->base_uom_id, $item->fresh()->base_uom_id);
            $this->assertSame($item->unit_of_measure, $item->fresh()->unit_of_measure);
        }
        $this->assertSame([], $fallback->allowedStockUomCodes());
        $pcs = UnitOfMeasure::firstOrCreate(['code' => 'pcs'], ['name' => 'Pieces', 'dimension' => 'count', 'is_active' => true]);
        $defaults = app(\App\Modules\MaterialsLibrary\Services\MaterialDefaultsService::class)->apply(['material_category_id' => $fallback->id]);
        $this->assertSame($pcs->id, $defaults['base_uom_id']);
    }

    public function test_unchanged_legacy_item_type_is_repaired_for_its_existing_category(): void
    {
        $legacyType = MaterialItemType::create(['code' => 'LEGACYTYPE', 'name' => 'Legacy type', 'is_active' => true]);
        $material = $this->material(['item_type_id' => $legacyType->id]);
        $result = $this->import(app(MaterialExportService::class)->workbook());
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame($this->category->item_type_id, $material->fresh()->item_type_id);
        $this->assertSame($this->category->id, $material->fresh()->material_category_id);
    }

    public function test_reuploading_the_same_category_edited_workbook_keeps_the_derived_type(): void
    {
        $material = $this->material();
        $type = MaterialItemType::create(['code' => 'RETRYTOOL', 'name' => 'Retry Tooling', 'is_active' => true, 'default_issue_disposition' => 'consumed', 'default_tracking_mode' => 'bulk_quantity']);
        $category = MaterialCategory::create(['code' => 'RETRYBITS', 'name' => 'Retry CNC Router Bits', 'item_type_id' => $type->id, 'is_active' => true, 'is_selectable' => true, 'allowed_uoms' => ['EXPCS']]);
        $book = app(MaterialExportService::class)->workbook();
        $this->set($book, 'Category', MaterialWorkbook::choice($category));
        $path = tempnam(sys_get_temp_dir(), 'retry-category-').'.xlsx';
        (new Xlsx($book))->save($path);
        try {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $result = app(MaterialImportService::class)->import(new \SplFileInfo($path), null);
                $this->assertSame([], $result['errors']);
                $this->assertSame(1, $result['updated']);
                $this->assertSame(0, $result['created']);
                $this->assertSame($type->id, $material->fresh()->item_type_id);
                $this->assertSame($category->id, $material->fresh()->material_category_id);
                $this->assertSame($this->unit->id, $material->fresh()->base_uom_id);
            }
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_category_without_an_owner_keeps_the_explicit_workbook_type(): void
    {
        $this->category->update(['item_type_id' => null]);
        $material = $this->material();
        $type = MaterialItemType::create(['code' => 'FREEEX', 'name' => 'Explicit unowned type', 'is_active' => true]);
        $book = app(MaterialExportService::class)->workbook();
        $this->set($book, 'Item type', MaterialWorkbook::choice($type));
        $result = $this->import($book);
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame($type->id, $material->fresh()->item_type_id);
    }

    public function test_export_contains_all_materials_not_only_active_visible_or_current_page(): void
    {
        $this->material();
        $this->material(['item_status' => 'Under Review', 'is_active' => false, 'is_inventory_visible' => false, 'workstation_id' => null]);
        $deleted = $this->material();
        $deleted->delete();
        $book = app(MaterialExportService::class)->workbook();
        $this->assertSame(3, $book->getSheetByName('Materials')->getHighestDataRow());
        $this->assertSame('Materials', $book->getActiveSheet()->getTitle());
        $this->assertNotNull($book->getSheetByName('Instructions'));
        $this->assertGreaterThanOrEqual(15, count($book->getSheetByName('Materials')->getDataValidationCollection()));
        $book->disconnectWorksheets();
    }

    public function test_export_upload_roundtrip_preserves_identity_fields_attributes_and_conversions(): void
    {
        $m = $this->material(['brand_manufacturer' => 'Brand', 'manufacturer_part_number' => '0000123', 'purchase_uom_id' => $this->pack->id, 'default_unit_cost' => 18, 'notes' => '=literal text']);
        $m->uomConversions()->create(['from_uom_id' => $this->pack->id, 'to_uom_id' => $this->unit->id, 'factor' => 12]);
        $book = app(MaterialExportService::class)->workbook();
        $sheet = $book->getActiveSheet();
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('AC2')->getDataType());
        $book->setActiveSheetIndex(2); // Instructions must never be treated as materials.
        $result = $this->import($book);
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['created']);
        $m->refresh();
        $this->assertSame('0000123', $m->manufacturer_part_number);
        $this->assertSame('preserved', $m->attributes['attributes']['custom_key']);
        $this->assertSame(12, $m->attributes['attributes']['numeric_custom']);
        $this->assertFalse($m->attributes['attributes']['bool_custom']);
        $this->assertSame('Matte', $m->attributes['attributes']['finish']);
        $this->assertEquals(12, $m->uomConversions()->sole()->factor);
        $this->assertEquals(45, $m->unit_cost);
        $this->assertSame('Active', $m->item_status);
    }

    public function test_edit_and_new_row_use_modal_validation_and_precise_row_errors(): void
    {
        $m = $this->material();
        $book = app(MaterialExportService::class)->workbook();
        $this->set($book, 'Material name', 'Edited material');
        $this->set($book, 'Attribute: finish', 'Gloss');
        $headers = $book->getActiveSheet()->rangeToArray('A1:'.$book->getActiveSheet()->getHighestDataColumn().'1')[0];
        $this->set($book, 'Material name', 'New draft', 4);
        $this->set($book, 'Item code', 'EX-NEW', 4);
        $this->set($book, 'Status', 'Under Review', 4);
        $this->set($book, 'Material name', 'Bad finish', 6);
        $this->set($book, 'Category', $this->category->id.' | Excel category', 6);
        $this->set($book, 'Attribute: finish', 'Wrong', 6);
        $result = $this->import($book);
        $this->assertSame(3, $result['total']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertStringStartsWith('Row 6:', $result['errors'][0]);
        $this->assertSame('Edited material', $m->fresh()->material_name);
        $this->assertSame('Gloss', $m->fresh()->attributes['attributes']['finish']);
        $this->assertSame('Under Review', LibraryMaterial::where('material_code', 'EX-NEW')->sole()->item_status);
        $this->assertDatabaseMissing('library_materials', ['material_name' => 'Bad finish']);
    }

    public function test_invalid_ids_and_formulas_do_not_create_or_update(): void
    {
        $m = $this->material();
        $book = app(MaterialExportService::class)->workbook();
        $this->set($book, 'Material ID', '999999999');
        $result = $this->import($book);
        $this->assertSame(0, $result['success']);
        $book = app(MaterialExportService::class)->workbook();
        $book->getActiveSheet()->setCellValue('C2', '=1+1');
        $result = $this->import($book);
        $this->assertSame(0, $result['success']);
        $this->assertStringContainsString('Formulas', $result['errors'][0]);
        $this->assertSame('Workbook item', $m->fresh()->material_name);
    }

    public function test_template_is_xlsx_and_export_requires_view_permission(): void
    {
        $response = $this->get('/api/materials-library/template/'.$this->ws->id);
        $response->assertOk();
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));
        $this->get('/api/materials-library/export')->assertOk();
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));
        $this->getJson('/api/materials-library/export')->assertForbidden();
    }

    public function test_stock_unit_lock_rolls_back_the_entire_invalid_row(): void
    {
        $m = $this->material();
        $this->category->update(['allowed_uoms' => [$this->unit->code, $this->pack->code]]);
        InventoryLog::create([
            'material_id' => $m->id, 'user_id' => auth()->id(), 'type' => 'check_in', 'quantity' => 20, 'balance_after' => 20, 'logged_at' => now(),
        ]);
        $book = app(MaterialExportService::class)->workbook();
        $this->set($book, 'Material name', 'Should roll back');
        $this->set($book, 'Stock unit', $this->pack->id.' | '.$this->pack->name);
        $this->set($book, 'Issue unit', $this->pack->id.' | '.$this->pack->name);
        $result = $this->import($book);
        $this->assertSame(0, $result['success']);
        $this->assertStringContainsString('stock movements', $result['errors'][0]);
        $this->assertSame('Workbook item', $m->fresh()->material_name);
        $this->assertSame($this->unit->id, $m->fresh()->base_uom_id);
    }

    public function test_upload_endpoint_accepts_downloaded_workbook_without_target_workstation(): void
    {
        $m = $this->material();
        $book = app(MaterialExportService::class)->workbook();
        $this->set($book, 'Material name', 'Uploaded through API');
        $path = tempnam(sys_get_temp_dir(), 'upload-').'.xlsx';
        (new Xlsx($book))->save($path);
        try {
            $file = new UploadedFile($path, 'material_library.xlsx', null, null, true);
            $this->postJson('/api/materials-library/import', ['file' => $file])->assertOk()->assertJsonPath('data.updated', 1)->assertJsonPath('data.errors', []);
            $this->assertSame('Uploaded through API', $m->fresh()->material_name);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_category_specific_dropdown_is_present_after_excel_save_and_reload(): void
    {
        $book = app(MaterialExportService::class)->workbook(false);
        $path = tempnam(sys_get_temp_dir(), 'dropdown-').'.xlsx';
        (new Xlsx($book))->save($path);
        try {
            $loaded = IOFactory::load($path);
            $sheet = $loaded->getSheetByName('Materials');
            $column = Coordinate::stringFromColumnIndex(count(MaterialWorkbook::COLUMNS) + 1);
            $v = $sheet->getCell($column.'2')->getDataValidation();
            $this->assertStringContainsString('VLOOKUP($E2', $v->getFormula1());
            $this->assertStringContainsString('1001', $v->getSqref());
            $this->assertSame('list', $sheet->getCell('E2')->getDataValidation()->getType());
            $loaded->disconnectWorksheets();
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }
}
