<?php

namespace Tests\Feature\MaterialsLibrary;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\Workstation;
use App\Modules\MaterialsLibrary\Services\MaterialImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CatalogueAccessAndImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_authenticated_user_without_catalogue_permission_cannot_read_or_write(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/materials-library/materials')->assertForbidden();
        $this->postJson('/api/materials-library/materials', [
            'material_name' => 'Unauthorized material', 'material_category_id' => 1,
        ])->assertForbidden();
    }

    public function test_legacy_spreadsheet_row_is_imported_under_review_not_active(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate(Permissions::MATERIALS_LIBRARY_IMPORT, 'web'));
        Sanctum::actingAs($user);
        $workstation = Workstation::create(['code' => 'AUDIT', 'name' => 'Audit workstation']);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Material Code', 'Material Name', 'Category', 'UOM'],
            ['AUD-0001', 'Imported unfinished item', 'Unknown group', 'PCS'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'material-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        try {
            app(MaterialImportService::class)->import(new \SplFileInfo($path), $workstation->id);
        } finally {
            @unlink($path);
        }

        $material = LibraryMaterial::where('material_code', 'AUD-0001')->sole();
        $this->assertSame('Under Review', $material->item_status);
        $this->assertFalse($material->is_active);
        $this->assertNull($material->material_category_id);
    }
}
