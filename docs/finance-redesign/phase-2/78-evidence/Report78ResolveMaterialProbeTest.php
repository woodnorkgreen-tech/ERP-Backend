<?php

namespace Tests\Feature\Stores;

use App\Events\MaterialsListChanged;
use App\Models\ElementMaterial;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Stock;
use App\Services\ProjectElementRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Report 78 — characterization probes for Resolve Material.
 *
 * AUDIT EVIDENCE, NOT A REGRESSION SUITE. Each probe asserts what the code does
 * TODAY, including behaviour the audit classifies as a defect, so a passing
 * probe proves the finding. Not meant to be committed as-is.
 */
class Report78ResolveMaterialProbeTest extends TestCase
{
    use \Tests\Concerns\GrantsMatrixPermissions;
    use RefreshDatabase;
    use \Tests\Concerns\ApprovesGovernedPostingAccounts;

    private int $enquiryId;
    private User $user;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder::class);
        $this->activateStoresPostingAccounts();

        Role::findOrCreate('Stores', 'web');
        $this->grantMatrixPermissions('Stores');
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('Stores');
        $this->user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate(\App\Constants\Permissions::STORES_REVIEW));
        Sanctum::actingAs($this->user->fresh());

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Stand build', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-R78-001', 'job_number' => 'WNG-R78-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $stockTypeId = DB::table('material_item_types')->where('code', 'STOCK')->value('id');
        $this->categoryId = DB::table('material_categories')->insertGetId([
            'name' => 'Boards', 'code' => 'BRD', 'item_type_id' => $stockTypeId,
            'allowed_uoms' => json_encode(['pcs']), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function line(string $description, string $unit, float $quantity, bool $included = true): ElementMaterial
    {
        $taskId = DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $this->enquiryId, 'type' => 'materials', 'title' => 'Materials',
            'status' => 'in_progress', 'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dataId = DB::table('task_materials_data')->insertGetId([
            'enquiry_task_id' => $taskId,
            'project_info' => json_encode(['approval_status' => ['all_approved' => true,
                'project_officer' => ['approved' => true], 'production' => ['approved' => true]]]),
            'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ]);
        $elementId = \App\Models\ProjectElement::create([
            'persistent_id' => (string) Str::uuid(), 'task_materials_data_id' => $dataId,
            'element_type' => 'stand', 'name' => 'BOOTH1', 'category' => 'production', 'is_included' => true,
        ])->id;

        return ElementMaterial::create([
            'project_element_id' => $elementId, 'persistent_id' => (string) Str::uuid(),
            'library_material_id' => null, 'description' => $description,
            'unit_of_measurement' => $unit, 'quantity' => $quantity, 'is_included' => $included,
        ]);
    }

    private function material(string $name, string $unit = 'pcs'): LibraryMaterial
    {
        $material = LibraryMaterial::create([
            'material_name' => $name, 'material_code' => 'MAT-'.uniqid(),
            'material_category_id' => $this->categoryId, 'category' => 'Boards',
            'unit_of_measure' => $unit, 'unit_cost' => 4500, 'item_status' => 'Active', 'is_active' => true,
        ]);
        Stock::create(['material_id' => $material->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);

        return $material;
    }

    private function resolve(int $id, array $payload)
    {
        return $this->postJson("/api/procurement-stores/project-materials/{$id}/resolve-catalogue", $payload);
    }

    private function counts(): array
    {
        return collect(['inventory_logs', 'journal_entries', 'cost_lines', 'stores_finance_postings', 'requisition_items', 'library_materials'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    /** F-01 — the BOM writer erases a resolution when the client sends the pre-resolution copy. */
    public function test_probe_stale_bom_save_erases_resolution_and_version_stamp_is_not_bumped(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'Sheets', 10);
        $mdf = $this->material('MDF Board 18mm');
        $data = $line->element->taskMaterialsData;
        $stampBefore = $data->updated_at->toISOString();

        $this->resolve($line->id, ['material_id' => $mdf->id])->assertOk();

        // The optimistic-lock stamp the materials screen sends back as sourceUpdatedAt.
        $this->assertSame($stampBefore, $data->fresh()->updated_at->toISOString(), 'resolution does not bump task_materials_data.updated_at');

        // What MaterialsModal / materialsService.ts send for a line the browser still believes is custom.
        app(ProjectElementRegistry::class)->saveMaterials($data->fresh(), [[
            'id' => (string) $line->element->id, 'persistent_id' => $line->element->persistent_id,
            'elementType' => 'stand', 'name' => 'BOOTH1', 'category' => 'production',
            'materials' => [[
                'id' => (string) $line->id, 'persistent_id' => $line->persistent_id,
                'libraryMaterialId' => null, 'description' => 'MDF 18mm board (typed)',
                'unitOfMeasurement' => 'Sheets', 'quantity' => 10, 'sourceMetadata' => null,
            ]],
        ]]);

        $this->assertNull($line->fresh()->library_material_id, 'resolution silently erased');
        // The audit stamp survives only because the client sent sourceMetadata = null.
        $this->assertSame($mdf->id, data_get($line->fresh()->source_metadata, 'catalogue_resolution.library_material_id'));
    }

    /** F-02 — once unlinked again, the same line can be resolved to a DIFFERENT material even though stock of the first was issued against it. */
    public function test_probe_line_with_issue_history_can_be_re_resolved_to_another_material(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'Sheets', 10);
        $mdf18 = $this->material('MDF Board 18mm');
        $mdf9 = $this->material('MDF Board 9mm');
        $this->resolve($line->id, ['material_id' => $mdf18->id])->assertOk();

        DB::table('inventory_logs')->insert([
            'material_id' => $mdf18->id, 'type' => 'check_out', 'quantity' => -4, 'balance_after' => 46,
            'project_material_id' => $line->id, 'user_id' => $this->user->id,
            'logged_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Any BOM edit that retypes the description clears the id client-side (MaterialsModal onInlineMaterialInput).
        DB::table('element_materials')->where('id', $line->id)->update(['library_material_id' => null]);

        $r = $this->resolve($line->id, ['material_id' => $mdf9->id]); fwrite(STDERR, "\n[R78] re-resolve: ".$r->getContent()."\n"); $r->assertOk();

        $this->assertSame($mdf9->id, $line->fresh()->library_material_id);
        // The 4 sheets of 18mm now count as fulfilment of a 9mm requirement.
        $this->assertSame(4.0, (float) DB::table('inventory_logs')->where('project_material_id', $line->id)->sum(DB::raw('ABS(quantity)')));
        $this->assertSame($mdf18->id, (int) DB::table('inventory_logs')->where('project_material_id', $line->id)->value('material_id'));
    }

    /** F-03 — no unit compatibility check: a square-metre requirement is linked to a per-sheet item. */
    public function test_probe_incompatible_unit_is_accepted(): void
    {
        $line = $this->line('White PVC 3mm', 'sqm', 12);
        $sheet = $this->material('White PVC Sheet 3mm 8x4', 'pcs');

        $this->resolve($line->id, ['material_id' => $sheet->id])->assertOk();

        $fresh = $line->fresh();
        $this->assertSame($sheet->id, $fresh->library_material_id);
        $this->assertSame('sqm', $fresh->unit_of_measurement);
        $this->assertSame('12.00', (string) $fresh->getRawOriginal('quantity'), 'quantity kept in BOM unit; issue cap compares it against pcs');
    }

    /** F-04 — "Create new" registers a duplicate of an existing Active item without warning. */
    public function test_probe_create_new_allows_exact_duplicate_name(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'Sheets', 10);
        $this->material('MDF Board 18mm');
        $before = LibraryMaterial::where('material_name', 'MDF Board 18mm')->count();

        $this->resolve($line->id, ['new_material' => [
            'material_name' => 'MDF Board 18mm', 'material_category_id' => $this->categoryId,
        ]])->assertOk();

        $this->assertSame($before + 1, LibraryMaterial::where('material_name', 'MDF Board 18mm')->count());
        $created = LibraryMaterial::find($line->fresh()->library_material_id);
        fwrite(STDERR, "\n[R78] duplicate registered with item_status={$created->item_status}\n");
    }

    /** F-05 — resolution does not announce the list change, so budget/procurement copies keep library_material_id = null. */
    public function test_probe_resolution_does_not_dispatch_materials_list_changed(): void
    {
        Event::fake([MaterialsListChanged::class]);
        $line = $this->line('MDF 18mm board (typed)', 'Sheets', 10);
        $mdf = $this->material('MDF Board 18mm');

        $this->resolve($line->id, ['material_id' => $mdf->id])->assertOk();

        Event::assertNotDispatched(MaterialsListChanged::class);
    }

    /** Positive control — match without receipt moves nothing, posts nothing, raises no demand. */
    public function test_probe_match_without_receipt_creates_no_stock_journal_cost_or_requisition(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'Sheets', 10);
        $mdf = $this->material('MDF Board 18mm');
        $before = $this->counts();
        $stockBefore = (float) Stock::where('material_id', $mdf->id)->value('quantity_on_hand');

        $this->resolve($line->id, ['material_id' => $mdf->id])->assertOk();

        $this->assertSame($before, $this->counts());
        $this->assertSame($stockBefore, (float) Stock::where('material_id', $mdf->id)->value('quantity_on_hand'));
    }

    /** F-06 — with "Add stock we already have" ticked, one resolve call posts a stock movement AND a journal. */
    public function test_probe_resolve_with_receipt_posts_stock_and_journal(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'Sheets', 10);
        $mdf = $this->material('MDF Board 18mm');
        $before = $this->counts();

        $this->resolve($line->id, ['material_id' => $mdf->id, 'receive' => [
            'quantity' => 3, 'receipt_unit_cost' => 4500, 'receipt_reason' => 'discovered_stock',
            'notes' => 'Found in the old store during the clear-out',
        ]])->assertOk();

        $after = $this->counts();
        $this->assertSame($before['inventory_logs'] + 1, $after['inventory_logs']);
        $this->assertGreaterThan($before['journal_entries'], $after['journal_entries']);
        $this->assertSame($before['cost_lines'], $after['cost_lines'], 'no project cost');
        $this->assertNull(DB::table('inventory_logs')->latest('id')->value('project_material_id'), 'receipt is general stock, not tied to the line');
    }

    /** Idempotency — a retry after success is refused and changes nothing. */
    public function test_probe_repeat_request_is_refused_without_side_effects(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'Sheets', 10);
        $mdf = $this->material('MDF Board 18mm');
        $payload = ['material_id' => $mdf->id, 'receive' => [
            'quantity' => 3, 'receipt_unit_cost' => 4500, 'receipt_reason' => 'discovered_stock',
            'notes' => 'Found in the old store during the clear-out',
        ]];
        $this->resolve($line->id, $payload)->assertOk();
        $after = $this->counts();

        $this->resolve($line->id, $payload)->assertStatus(422);
        $this->assertSame($after, $this->counts());
    }

    /** F-07 — an excluded line (is_included = false) is still resolvable. */
    public function test_probe_excluded_line_is_resolvable(): void
    {
        $line = $this->line('Unused trim', 'Mtrs', 5, false);
        $mdf = $this->material('Edge trim', 'm');

        $this->resolve($line->id, ['material_id' => $mdf->id])->assertOk();
    }

    /** Authorization — the only gate is the Stores permission; there is no per-project scope. */
    public function test_probe_user_without_stores_manage_is_refused(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'Sheets', 10);
        $mdf = $this->material('MDF Board 18mm');
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->resolve($line->id, ['material_id' => $mdf->id])->assertStatus(403);
        $this->assertNull($line->fresh()->library_material_id);
    }
}
