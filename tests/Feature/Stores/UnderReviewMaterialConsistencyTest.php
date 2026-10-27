<?php

namespace Tests\Feature\Stores;

use App\Events\MaterialsListChanged;
use App\Models\ElementMaterial;
use App\Models\User;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Services\ProjectMaterialDemand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Report 79C — Under Review items in the BOM.
 *
 * Choosing an item still waiting for the custodian parks the line on it (a
 * pending reference, not a catalogue identity), the same as Resolve does.
 * Activation links it whether or not the list is signed off yet; rejection
 * returns it to "choose a material" with the reason kept.
 */
class UnderReviewMaterialConsistencyTest extends TestCase
{
    use \Tests\Concerns\GrantsMatrixPermissions;
    use RefreshDatabase;

    private User $user;
    private int $enquiryId;
    private int $projectId;
    private int $taskId;
    private int $dataId;
    private int $elementId;
    private string $elementPid;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(ExpenseCodeSeeder::class);

        foreach (['Stores', 'Project Officer', 'Production'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->grantMatrixPermissions('Stores');
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole(['Stores', 'Project Officer', 'Production']);
        Sanctum::actingAs($this->user->fresh());

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->enquiryId = DB::table('project_enquiries')->insertGetId([
            // Report 78C: a job being delivered — the enquiry status is the lifecycle authority.
            'status' => 'in_progress',
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Stand build', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-78B-001', 'job_number' => 'WNG-78B-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->projectId = DB::table('projects')->insertGetId([
            'enquiry_id' => $this->enquiryId, 'project_id' => 'WNG-78B-001',
            'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->taskId = DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $this->enquiryId, 'type' => 'materials', 'title' => 'Materials',
            'status' => 'in_progress', 'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->dataId = DB::table('task_materials_data')->insertGetId([
            'enquiry_task_id' => $this->taskId,
            'project_info' => json_encode(['approval_status' => [
                'project_officer' => ['approved' => true, 'approved_by' => $this->user->id],
                'production' => ['approved' => true, 'approved_by' => $this->user->id],
                'all_approved' => true,
            ]]),
            'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ]);
        $this->elementPid = (string) Str::uuid();
        $this->elementId = \App\Models\ProjectElement::create([
            'persistent_id' => $this->elementPid, 'task_materials_data_id' => $this->dataId,
            'element_type' => 'stand', 'name' => 'BOOTH1', 'category' => 'production', 'is_included' => true,
        ])->id;

        $stockTypeId = DB::table('material_item_types')->where('code', 'STOCK')->value('id');
        $this->categoryId = DB::table('material_categories')->insertGetId([
            'name' => 'Boards', 'code' => 'BRD', 'item_type_id' => $stockTypeId,
            'allowed_uoms' => json_encode(['sheet', 'pcs', 'm2', 'm']), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /* ── fixtures ──────────────────────────────────────────────────────── */

    private function uom(string $code): int
    {
        return (int) DB::table('units_of_measure')->where('code', $code)->value('id');
    }

    private function line(string $description, string $unit, float $quantity, ?int $libraryId = null, bool $included = true): ElementMaterial
    {
        return ElementMaterial::create([
            'project_element_id' => $this->elementId, 'persistent_id' => (string) Str::uuid(),
            'library_material_id' => $libraryId, 'description' => $description,
            'unit_of_measurement' => $unit, 'quantity' => $quantity, 'is_included' => $included,
        ]);
    }

    private function material(string $name, string $unit, float $onHand = 50, string $status = 'Active'): LibraryMaterial
    {
        $material = LibraryMaterial::create([
            'material_name' => $name, 'material_code' => 'MAT-'.uniqid(),
            'material_category_id' => $this->categoryId, 'category' => 'Boards',
            'unit_of_measure' => $unit, 'base_uom_id' => $this->uom($unit) ?: null,
            'unit_cost' => 1000, 'item_status' => $status, 'is_active' => $status === 'Active',
        ]);
        Stock::create(['material_id' => $material->id, 'quantity_on_hand' => $onHand, 'quantity_reserved' => 0]);

        return $material;
    }

    private function convert(LibraryMaterial $material, string $from, float $factor): void
    {
        DB::table('material_uom_conversions')->insert([
            'material_id' => $material->id, 'from_uom_id' => $this->uom($from), 'to_uom_id' => $material->base_uom_id,
            'factor' => $factor, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function resolve(ElementMaterial $line, array $payload)
    {
        return $this->postJson("/api/procurement-stores/project-materials/{$line->id}/resolve-catalogue", $payload);
    }

    private function issue(ElementMaterial $line, LibraryMaterial $material, float $quantity)
    {
        return $this->postJson('/api/procurement-stores/movements', ['type' => 'issue', 'lines' => [[
            'material_id' => $material->id, 'project_material_id' => $line->id, 'project_id' => $this->projectId,
            'quantity' => $quantity, 'recipient_name' => 'Otieno', 'reference_no' => 'WNG-78B-001',
        ]]]);
    }

    /** The payload the Materials screen sends, for one line. */
    private function save(ElementMaterial $line, array $overrides = [], ?string $sourceUpdatedAt = null)
    {
        $material = array_merge([
            'id' => (string) $line->id, 'persistent_id' => $line->persistent_id,
            'description' => $line->description, 'unitOfMeasurement' => $line->unit_of_measurement,
            'quantity' => (float) $line->quantity, 'isIncluded' => true, 'sourceMetadata' => null,
        ], $overrides);

        return $this->postJson("/api/projects/tasks/{$this->taskId}/materials", array_filter([
            'projectInfo' => ['projectId' => 'WNG-78B-001'],
            'projectElements' => [[
                'id' => (string) $this->elementId, 'persistent_id' => $this->elementPid,
                'elementType' => 'stand', 'name' => 'BOOTH1', 'category' => 'production',
                'materials' => [$material],
            ]],
            'sourceUpdatedAt' => $sourceUpdatedAt,
        ], fn ($v) => $v !== null));
    }

    private function approval(): array
    {
        return json_decode(DB::table('task_materials_data')->where('id', $this->dataId)->value('project_info'), true);
    }

    private function parkedLine(): array
    {
        $pending = $this->material('Gold Leaf Sheet', 'sheet', 0, 'Under Review');
        $line = $this->line('gold leaf', 'sheet', 4);
        $this->save($line, ['libraryMaterialId' => $pending->id, 'libraryMaterialIdBaseline' => null])->assertOk();

        return [$line->fresh(), $pending];
    }

    private function draftList(): void
    {
        DB::table('task_materials_data')->where('id', $this->dataId)->update(['project_info' => json_encode(['approval_status' => [
            'project_officer' => ['approved' => false], 'production' => ['approved' => false], 'all_approved' => false,
        ]])]);
    }

    public function test_choosing_an_under_review_item_in_the_bom_parks_the_line_instead_of_linking_it(): void
    {
        [$line, $pending] = $this->parkedLine();

        $this->assertNull($line->library_material_id);
        $this->assertSame((int) $pending->id, (int) $line->requested_material_id);
        $this->assertSame(ElementMaterial::DISPOSITION_AWAITING_REGISTRATION, $line->requirement_disposition);
        $this->assertSame('bom', $line->source_metadata['material_request']['source']);
    }

    public function test_activation_links_a_line_parked_on_a_draft_list(): void
    {
        $this->draftList();
        [$line, $pending] = $this->parkedLine();

        $pending->update(['item_status' => 'Active', 'is_active' => true]);

        $line->refresh();
        $this->assertSame((int) $pending->id, (int) $line->library_material_id, 'A draft BOM line must link when its material is approved.');
        $this->assertNull($line->requirement_disposition);
        $this->assertSame('Active', $line->source_metadata['material_request']['outcome']['status']);
    }

    public function test_activation_links_a_line_parked_on_a_signed_off_list(): void
    {
        [$line, $pending] = $this->parkedLine();
        // Saving the change reset the sign-off; the job signs it off again.
        DB::table('task_materials_data')->where('id', $this->dataId)->update(['project_info' => json_encode(['approval_status' => [
            'project_officer' => ['approved' => true], 'production' => ['approved' => true], 'all_approved' => true,
        ]])]);

        $pending->update(['item_status' => 'Active', 'is_active' => true]);

        $this->assertSame((int) $pending->id, (int) $line->fresh()->library_material_id);
    }

    public function test_rejection_keeps_the_requirement_and_says_why(): void
    {
        [$line, $pending] = $this->parkedLine();

        $pending->update(['item_status' => 'Inactive', 'is_active' => false]);

        $line->refresh();
        $this->assertNull($line->library_material_id);
        $this->assertNull($line->requirement_disposition, 'The line goes back to "choose a material".');
        $this->assertSame((int) $pending->id, (int) $line->requested_material_id, 'What was asked for stays on record.');
        $this->assertSame('Inactive', $line->source_metadata['material_request']['outcome']['status']);
        $this->assertSame('awaiting_resolution', $line->effectiveDisposition());

        $served = collect($this->getJson("/api/projects/enquiries/{$this->enquiryId}/materials")->assertOk()->json('data.projectElements.0.materials'))->firstWhere('id', (string) $line->id);
        $this->assertSame('Inactive', $served['requestOutcome']['status']);
        $this->assertSame('Inactive', $served['requestedMaterial']['status']);
    }

    public function test_a_stale_screen_never_clears_a_pending_request_but_the_author_can(): void
    {
        [$line, $pending] = $this->parkedLine();

        // An older screen: no key at all, or a baseline that is not this request.
        $this->save($line, ['libraryMaterialId' => null])->assertOk();
        $this->save($line, ['libraryMaterialId' => null, 'requestedMaterialId' => null, 'requestedMaterialIdBaseline' => null])->assertOk();
        $this->assertSame((int) $pending->id, (int) $line->fresh()->requested_material_id);

        // The author, who saw the request, changes the line.
        $this->save($line, ['libraryMaterialId' => null, 'requestedMaterialId' => null, 'requestedMaterialIdBaseline' => $pending->id])->assertOk();
        $line->refresh();
        $this->assertNull($line->requested_material_id);
        $this->assertNull($line->requirement_disposition);
    }

    public function test_choosing_an_active_item_replaces_the_pending_request(): void
    {
        [$line] = $this->parkedLine();
        $active = $this->material('Gold Leaf Roll', 'sheet');

        $this->save($line, ['libraryMaterialId' => $active->id, 'libraryMaterialIdBaseline' => null])->assertOk();

        $line->refresh();
        $this->assertSame((int) $active->id, (int) $line->library_material_id);
        $this->assertNull($line->requested_material_id);
        $this->assertNull($line->requirement_disposition);
    }
}
