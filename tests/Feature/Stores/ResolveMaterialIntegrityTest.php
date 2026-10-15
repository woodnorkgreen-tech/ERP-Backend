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
 * Report 78B — Resolve Material integrity.
 *
 * Pins the corrections to Report 78's findings: a resolution cannot be erased
 * by a stale materials-list save (F-01), a line cannot change material under
 * issued stock (F-02), unit and identity changes reset approval (F-11), the
 * requirement is read in the material's stock unit everywhere it is compared
 * with stock (F-03), resolution moves nothing (F-04), new items are requested
 * not created live (F-05), non-stock lines need no inventory item (F-12), and
 * downstream copies follow without duplicated demand (F-07).
 */
class ResolveMaterialIntegrityTest extends TestCase
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

    private function counts(): array
    {
        return collect(['inventory_logs', 'journal_entries', 'cost_lines', 'stores_finance_postings', 'requisitions', 'requisition_items', 'purchase_orders', 'goods_receipt_notes', 'bills'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    /* ── Phase 1: identity protection (F-01, F-02, F-11) ───────────────── */

    public function test_a_stale_save_without_a_baseline_keeps_the_resolution_and_the_approval(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'Sheets', 10);
        $mdf = $this->material('MDF Board 18mm', 'sheet');
        $this->resolve($line, ['material_id' => $mdf->id])->assertOk();

        // An older screen: no baseline, and it believes the line is custom.
        $this->save($line, ['libraryMaterialId' => null])->assertOk();

        $this->assertSame($mdf->id, $line->fresh()->library_material_id);
        $this->assertTrue($this->approval()['approval_status']['all_approved']);
        $this->assertNotEmpty($line->fresh()->source_metadata['catalogue_resolutions']);
    }

    public function test_a_restored_draft_with_a_stale_baseline_keeps_the_resolution(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'Sheets', 10);
        $mdf = $this->material('MDF Board 18mm', 'sheet');
        $this->resolve($line, ['material_id' => $mdf->id])->assertOk();

        // The draft was taken before Stores resolved the line, so it saw null.
        $this->save($line, ['libraryMaterialId' => null, 'libraryMaterialIdBaseline' => null])->assertOk();

        $this->assertSame($mdf->id, $line->fresh()->library_material_id);
    }

    public function test_a_screen_opened_before_the_resolution_gets_a_version_conflict(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'Sheets', 10);
        $mdf = $this->material('MDF Board 18mm', 'sheet');
        $openedAt = DB::table('task_materials_data')->where('id', $this->dataId)->value('updated_at');
        $this->travel(2)->seconds();

        $this->resolve($line, ['material_id' => $mdf->id])->assertOk();

        $this->save($line, ['libraryMaterialId' => null], \Carbon\Carbon::parse($openedAt)->toISOString())
            ->assertStatus(409)->assertJsonPath('code', 'MATERIALS_VERSION_CONFLICT');
        $this->assertSame($mdf->id, $line->fresh()->library_material_id);
    }

    public function test_a_client_that_saw_the_link_may_unlink_it_when_nothing_was_issued_and_approval_resets_with_history(): void
    {
        $mdf = $this->material('MDF Board 18mm', 'sheet');
        $line = $this->line('MDF Board 18mm', 'sheet', 10, $mdf->id);

        $this->save($line, ['libraryMaterialId' => null, 'libraryMaterialIdBaseline' => $mdf->id])->assertOk();

        $this->assertNull($line->fresh()->library_material_id);
        $info = $this->approval();
        $this->assertFalse($info['approval_status']['all_approved']);
        $this->assertCount(1, $info['approval_history']);
        $this->assertTrue($info['approval_history'][0]['approval_status']['project_officer']['approved']);
    }

    public function test_a_substitution_is_refused_while_the_original_material_is_issued(): void
    {
        $mdf18 = $this->material('MDF Board 18mm', 'sheet');
        $mdf9 = $this->material('MDF Board 9mm', 'sheet');
        $line = $this->line('MDF Board 18mm', 'sheet', 10, $mdf18->id);
        $this->issue($line, $mdf18, 4)->assertOk();

        $this->save($line, ['libraryMaterialId' => $mdf9->id, 'libraryMaterialIdBaseline' => $mdf18->id])
            ->assertStatus(422);
        $this->save($line, ['libraryMaterialId' => null, 'libraryMaterialIdBaseline' => $mdf18->id])
            ->assertStatus(422);

        $this->assertSame($mdf18->id, $line->fresh()->library_material_id);
        $this->assertTrue($this->approval()['approval_status']['all_approved'], 'a refused save changes nothing');
    }

    public function test_a_lost_link_can_only_be_restored_to_the_material_that_was_issued(): void
    {
        $mdf18 = $this->material('MDF Board 18mm', 'sheet');
        $mdf9 = $this->material('MDF Board 9mm', 'sheet');
        $line = $this->line('MDF 18mm board (typed)', 'sheet', 10);
        $this->resolve($line, ['material_id' => $mdf18->id])->assertOk();
        $this->issue($line, $mdf18, 4)->assertOk();
        // A link lost before 78B (the historical defect).
        DB::table('element_materials')->where('id', $line->id)->update(['library_material_id' => null]);

        $this->resolve($line, ['material_id' => $mdf9->id])->assertStatus(422)->assertJsonValidationErrors('material_id');
        $this->resolve($line, ['material_id' => $mdf18->id])->assertOk();

        $history = $line->fresh()->source_metadata['catalogue_resolutions'];
        $this->assertCount(2, $history, 'each resolution is appended, never overwritten');
    }

    public function test_a_line_with_issued_stock_cannot_be_removed_from_the_list(): void
    {
        $mdf = $this->material('MDF Board 18mm', 'sheet');
        $issued = $this->line('MDF Board 18mm', 'sheet', 10, $mdf->id);
        $other = $this->line('Edge trim', 'm', 5);
        $this->issue($issued, $mdf, 3)->assertOk();

        // The list saved with only the other line: the issued line would be dropped.
        $this->save($other)->assertStatus(422);

        $this->assertNotNull(ElementMaterial::find($issued->id), 'the issued line is still on the list');
    }

    public function test_an_unissued_line_can_still_be_removed(): void
    {
        $keep = $this->line('Edge trim', 'm', 5);
        $drop = $this->line('Spare line', 'pcs', 2);

        $this->save($keep)->assertOk();

        $this->assertNull(ElementMaterial::find($drop->id));
    }

    public function test_a_resolved_material_cannot_be_force_deleted_out_from_under_its_lines(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'sheet', 10);
        $mdf = $this->material('MDF Board 18mm', 'sheet');
        $this->resolve($line, ['material_id' => $mdf->id])->assertOk();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $admin->givePermissionTo(Permission::findOrCreate(\App\Constants\Permissions::MATERIALS_LIBRARY_MANAGE, 'web'));
        Sanctum::actingAs($admin->fresh());

        $this->deleteJson("/api/materials-library/materials/{$mdf->id}/force")->assertStatus(422);

        $this->assertSame($mdf->id, $line->fresh()->library_material_id);
    }

    public function test_a_unit_change_resets_approval(): void
    {
        $mdf = $this->material('MDF Board 18mm', 'sheet');
        $line = $this->line('MDF Board 18mm', 'sheet', 10, $mdf->id);

        $this->save($line, ['unitOfMeasurement' => 'sqm', 'libraryMaterialId' => $mdf->id, 'libraryMaterialIdBaseline' => $mdf->id])->assertOk();

        $this->assertFalse($this->approval()['approval_status']['all_approved']);
        $this->assertStringContainsString('unit', implode(' ', $this->approval()['approval_history'][0]['changes']));
    }

    public function test_an_unchanged_save_keeps_approval(): void
    {
        $mdf = $this->material('MDF Board 18mm', 'sheet');
        $line = $this->line('MDF Board 18mm', 'sheet', 10, $mdf->id);

        $this->save($line, ['libraryMaterialId' => $mdf->id, 'libraryMaterialIdBaseline' => $mdf->id])->assertOk();

        $this->assertTrue($this->approval()['approval_status']['all_approved']);
    }

    /* ── Phase 2: units of measure (F-03) ──────────────────────────────── */

    public function test_a_spelling_alias_resolves_without_changing_the_quantity(): void
    {
        $line = $this->line('Aluminium profile', 'Mtrs', 5);
        $profile = $this->material('Aluminium Profile 40x40', 'm');

        $this->resolve($line, ['material_id' => $profile->id])->assertOk()->assertJsonPath('data.unit.basis', 'alias');

        $fresh = $line->fresh();
        $this->assertSame('Mtrs', $fresh->unit_of_measurement);
        $this->assertSame('5.00', (string) $fresh->getRawOriginal('quantity'));
        $this->assertSame(1.0, (float) $fresh->requirement_base_factor);
    }

    public function test_an_incompatible_unit_is_refused_with_its_reason(): void
    {
        $line = $this->line('White PVC 3mm', 'sqm', 12);
        $sheet = $this->material('White PVC Sheet 3mm 8x4', 'sheet');

        $this->resolve($line, ['material_id' => $sheet->id])->assertStatus(422)
            ->assertJsonValidationErrors('material_id');
        $this->assertNull($line->fresh()->library_material_id);
    }

    public function test_an_approved_material_conversion_resolves_and_caps_issues_in_stock_units(): void
    {
        $line = $this->line('MDF 18mm board', 'sqm', 12);
        $sheet = $this->material('MDF Board 18mm 8x4', 'sheet');
        $this->convert($sheet, 'm2', 0.335938); // one 8x4 sheet = 2.9768 m2

        $this->resolve($line, ['material_id' => $sheet->id])->assertOk()
            ->assertJsonPath('data.unit.basis', 'material_conversion');

        $fresh = $line->fresh();
        $this->assertSame('0.335938', (string) $fresh->requirement_base_factor);
        $this->assertSame('material_conversion', $fresh->requirement_unit_basis['basis']);
        $this->assertSame('sqm', $fresh->unit_of_measurement, 'BOM unit untouched');

        // 12 m2 is 4.03 sheets, not 12.
        $this->issue($line, $sheet, 5)->assertStatus(422);
        $this->issue($line, $sheet, 4)->assertOk();
        $this->assertSame(46.0, (float) Stock::where('material_id', $sheet->id)->value('quantity_on_hand'));
    }

    public function test_a_standard_metric_conversion_is_used(): void
    {
        $line = $this->line('Edge strip', 'mm', 2500);
        $strip = $this->material('Edge strip 22mm', 'm');

        $this->resolve($line, ['material_id' => $strip->id])->assertOk()->assertJsonPath('data.unit.basis', 'standard');
        $this->issue($line, $strip, 3)->assertStatus(422);
        $this->issue($line, $strip, 2.5)->assertOk();
    }

    public function test_an_issue_against_a_legacy_incompatible_link_is_refused(): void
    {
        $sticker = $this->material('Matt white sticker', 'm');
        $line = $this->line('Matt white sticker', 'sqm', 14, $sticker->id);

        $this->issue($line, $sticker, 8)->assertStatus(422);
        $this->assertSame(50.0, (float) Stock::where('material_id', $sticker->id)->value('quantity_on_hand'));
    }

    public function test_demand_is_counted_in_stock_units_and_unreadable_lines_are_not_guessed(): void
    {
        $sheet = $this->material('MDF Board 18mm 8x4', 'sheet');
        $this->convert($sheet, 'm2', 0.335938);
        $readable = $this->line('MDF 18mm', 'sqm', 12, $sheet->id);
        $sticker = $this->material('Matt white sticker', 'm');
        $this->line('Matt white sticker', 'sqm', 14, $sticker->id);

        $demand = app(ProjectMaterialDemand::class)->pendingByMaterial();

        $this->assertEqualsWithDelta(4.0313, $demand[$sheet->id], 0.0001);
        $this->assertArrayNotHasKey($sticker->id, $demand);

        $served = $this->getJson("/api/projects/enquiries/{$this->enquiryId}/materials")->assertOk()
            ->json('data.projectElements.0.materials');
        $byId = collect($served)->keyBy('id');
        $this->assertSame('compatible', $byId[(string) $readable->id]['requirementUnit']['status']);
        $this->assertSame(0, (int) collect($served)->firstWhere('description', 'Matt white sticker')['availableToIssue']);
    }

    /* ── Phase 3/6: separation and downstream (F-04, F-07) ─────────────── */

    public function test_a_resolution_posts_nothing_and_announces_the_list_change(): void
    {
        Event::fake([MaterialsListChanged::class]);
        $line = $this->line('MDF 18mm board (typed)', 'sheet', 10);
        $mdf = $this->material('MDF Board 18mm', 'sheet');
        $before = $this->counts();
        $stock = (float) Stock::where('material_id', $mdf->id)->value('quantity_on_hand');
        $libraryCount = LibraryMaterial::count();

        $this->resolve($line, ['material_id' => $mdf->id])->assertOk();

        $this->assertSame($before, $this->counts());
        $this->assertSame($stock, (float) Stock::where('material_id', $mdf->id)->value('quantity_on_hand'));
        $this->assertSame($libraryCount, LibraryMaterial::count());
        Event::assertDispatched(MaterialsListChanged::class, fn ($e) => $e->materialsTaskId === $this->taskId);
    }

    public function test_open_unordered_requisition_lines_are_relinked_and_ordered_ones_are_not(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'sheet', 10);
        $mdf = $this->material('MDF Board 18mm', 'sheet');
        $open = $this->requisitionItem($line, 'pending_approval');
        $rejected = $this->requisitionItem($line, 'rejected');
        $ordered = $this->requisitionItem($line, 'approved', withPurchaseOrder: true);
        $requisitions = DB::table('requisitions')->count();

        $this->resolve($line, ['material_id' => $mdf->id])->assertOk()
            ->assertJsonPath('data.requisitions.relinked', [$open]);

        $this->assertSame($mdf->id, (int) DB::table('requisition_items')->where('id', $open)->value('material_id'));
        $this->assertNull(DB::table('requisition_items')->where('id', $rejected)->value('material_id'));
        $this->assertNull(DB::table('requisition_items')->where('id', $ordered)->value('material_id'));
        $this->assertSame($requisitions, DB::table('requisitions')->count(), 'no requisition is created');
    }

    public function test_a_requisition_in_another_unit_is_left_for_the_buyer(): void
    {
        $line = $this->line('MDF 18mm', 'sqm', 12);
        $sheet = $this->material('MDF Board 18mm 8x4', 'sheet');
        $this->convert($sheet, 'm2', 0.335938);
        $item = $this->requisitionItem($line, 'pending_approval');

        $this->resolve($line, ['material_id' => $sheet->id])->assertOk()
            ->assertJsonPath('data.requisitions.needs_review', [$item]);
        $this->assertNull(DB::table('requisition_items')->where('id', $item)->value('material_id'));
    }

    private function requisitionItem(ElementMaterial $line, string $status, bool $withPurchaseOrder = false): int
    {
        $requisitionId = DB::table('requisitions')->insertGetId([
            'requisition_number' => 'REQ-'.uniqid(), 'date' => now()->toDateString(), 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId = DB::table('requisition_items')->insertGetId([
            'requisition_id' => $requisitionId, 'project_enquiry_id' => $this->enquiryId,
            'budget_item_persistent_id' => $line->persistent_id, 'custom_description' => $line->description,
            'quantity' => $line->quantity, 'purpose' => 'Project', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($withPurchaseOrder) {
            $supplierId = DB::table('suppliers')->insertGetId([
                'supplier_name' => 'Board Co', 'contact_person' => 'A', 'phone' => '0700', 'email' => uniqid().'@s.local',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $poId = DB::table('purchase_orders')->insertGetId([
                'po_number' => 'PO-'.uniqid(), 'date' => now()->toDateString(), 'supplier_id' => $supplierId,
                'due_date' => now()->toDateString(), 'delivery_address' => 'Store', 'requisition_id' => $requisitionId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('purchase_order_items')->insert([
                'purchase_order_id' => $poId, 'requisition_item_id' => $itemId, 'quantity' => 1, 'unit_price' => 1, 'total' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $itemId;
    }

    /* ── Phase 4: controlled registration (F-05) ───────────────────────── */

    public function test_an_exact_duplicate_cannot_be_requested(): void
    {
        $line = $this->line('MDF board', 'sheet', 10);
        $this->material('MDF Board 18mm', 'sheet');
        $before = LibraryMaterial::count();

        $this->resolve($line, [
            'new_material' => ['material_name' => '18mm mdf board', 'material_category_id' => $this->categoryId, 'base_uom_id' => $this->uom('sheet')],
            'request_reason' => 'Need it',
        ])->assertStatus(422)->assertJsonValidationErrors('new_material.material_name');

        $this->assertSame($before, LibraryMaterial::count());
    }

    public function test_near_duplicates_must_be_acknowledged_before_a_request(): void
    {
        $line = $this->line('MDF board 9mm', 'sheet', 10);
        $existing = $this->material('MDF Board 18mm', 'sheet');
        $payload = [
            'new_material' => ['material_name' => 'MDF Board 9mm', 'material_category_id' => $this->categoryId, 'base_uom_id' => $this->uom('sheet')],
            'request_reason' => 'Thinner board for the back panel.',
        ];

        $response = $this->resolve($line, $payload)->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_CANDIDATES');
        $candidate = collect($response->json('candidates'))->firstWhere('id', $existing->id);
        $this->assertTrue($candidate['spec_differs'], '18mm vs 9mm is flagged as a different specification');

        $this->resolve($line, $payload + ['acknowledged_candidates' => [$existing->id]])->assertOk();
        $this->assertSame('awaiting_registration', $line->fresh()->effectiveDisposition());
    }

    public function test_a_requested_material_is_unusable_until_the_custodian_activates_it(): void
    {
        $line = $this->line('Acoustic foam panel', 'pcs', 6);
        $this->resolve($line, [
            'new_material' => [
                'material_name' => 'Acoustic Foam Panel 50mm', 'material_category_id' => $this->categoryId,
                'base_uom_id' => $this->uom('pcs'), 'issue_disposition' => 'consumed', 'tracking_mode' => 'bulk_quantity',
            ],
            'request_reason' => 'Not in the catalogue.',
        ])->assertOk();
        $requested = LibraryMaterial::findOrFail($line->fresh()->requested_material_id);
        $this->assertSame('Under Review', $requested->item_status);

        // Under Review: cannot be linked, and the line cannot be issued.
        $this->resolve($line, ['material_id' => $requested->id])->assertStatus(422);

        // The custodian (materials_library.manage) activates through the existing door.
        $custodian = User::factory()->create(['is_active' => true]);
        $custodian->givePermissionTo(Permission::findOrCreate(\App\Constants\Permissions::MATERIALS_LIBRARY_MANAGE, 'web'));
        $custodian->givePermissionTo(Permission::findOrCreate(\App\Constants\Permissions::MATERIALS_LIBRARY_VIEW, 'web'));
        Sanctum::actingAs($custodian->fresh());
        $this->postJson("/api/materials-library/materials/{$requested->id}/activate")->assertOk();
        $this->assertSame('Active', $requested->fresh()->item_status);

        Sanctum::actingAs($this->user->fresh());
        $this->resolve($line, ['material_id' => $requested->id])->assertOk();
        $this->assertSame($requested->id, $line->fresh()->library_material_id);
        $this->assertSame('resolved', $line->fresh()->effectiveDisposition());
    }

    /* ── Phase 5: non-stock (F-12) ─────────────────────────────────────── */

    public function test_a_non_stock_line_needs_no_inventory_item_and_raises_no_stock_demand(): void
    {
        $line = $this->line('Hire of scissor lift', 'pcs', 1);
        $libraryCount = LibraryMaterial::count();

        $this->postJson("/api/procurement-stores/project-materials/{$line->id}/disposition", [
            'disposition' => 'non_stock', 'reason' => 'One-off hire, paid directly to the supplier.',
        ])->assertOk()->assertJsonPath('data.disposition', 'non_stock');

        $this->assertSame($libraryCount, LibraryMaterial::count());
        $this->assertSame([], app(ProjectMaterialDemand::class)->pendingByMaterial());
        $mdf = $this->material('Scissor lift', 'pcs');
        $this->resolve($line, ['material_id' => $mdf->id])->assertStatus(422);

        $this->postJson("/api/procurement-stores/project-materials/{$line->id}/disposition", [
            'disposition' => 'awaiting_resolution', 'reason' => 'Classified by mistake.',
        ])->assertOk()->assertJsonPath('data.disposition', 'awaiting_resolution');
        $this->assertCount(2, $line->fresh()->source_metadata['disposition_history']);
    }

    public function test_a_linked_line_cannot_be_classified_non_stock(): void
    {
        $mdf = $this->material('MDF Board 18mm', 'sheet');
        $line = $this->line('MDF Board 18mm', 'sheet', 10, $mdf->id);

        $this->postJson("/api/procurement-stores/project-materials/{$line->id}/disposition", [
            'disposition' => 'non_stock', 'reason' => 'Trying it.',
        ])->assertStatus(422);
    }

    /* ── lifecycle, repeat and authorization ──────────────────────────── */

    public function test_a_closed_job_cannot_be_resolved(): void
    {
        DB::table('project_enquiries')->where('id', $this->enquiryId)->update(['status' => 'completed']);
        $line = $this->line('MDF 18mm board (typed)', 'sheet', 10);
        $mdf = $this->material('MDF Board 18mm', 'sheet');

        $this->resolve($line, ['material_id' => $mdf->id])->assertStatus(422);
        $this->assertNull($line->fresh()->library_material_id);
    }

    public function test_an_excluded_line_cannot_be_resolved(): void
    {
        $line = $this->line('Unused trim', 'm', 5, null, false);
        $trim = $this->material('Edge trim', 'm');

        $this->resolve($line, ['material_id' => $trim->id])->assertStatus(422);
    }

    public function test_a_repeated_request_is_refused_without_side_effects(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'sheet', 10);
        $mdf = $this->material('MDF Board 18mm', 'sheet');
        $this->resolve($line, ['material_id' => $mdf->id])->assertOk();
        $after = $this->counts();
        $metadata = $line->fresh()->source_metadata;

        $this->resolve($line, ['material_id' => $mdf->id])->assertStatus(422);

        $this->assertSame($after, $this->counts());
        $this->assertSame($metadata, $line->fresh()->source_metadata);
    }

    public function test_every_resolution_action_needs_stores_permission(): void
    {
        $line = $this->line('MDF 18mm board (typed)', 'sheet', 10);
        $mdf = $this->material('MDF Board 18mm', 'sheet');
        Sanctum::actingAs(User::factory()->create(['is_active' => true])->fresh());
        $base = "/api/procurement-stores/project-materials/{$line->id}";

        $this->postJson("{$base}/resolve-catalogue", ['material_id' => $mdf->id])->assertStatus(403);
        $this->getJson("{$base}/compatibility?material_ids[]={$mdf->id}")->assertStatus(403);
        $this->getJson("{$base}/registration-candidates?name=MDF")->assertStatus(403);
        $this->postJson("{$base}/disposition", ['disposition' => 'non_stock', 'reason' => 'Not allowed'])->assertStatus(403);
        $this->assertNull($line->fresh()->library_material_id);
    }

    public function test_compatibility_reports_each_candidate(): void
    {
        $line = $this->line('White PVC 3mm', 'sqm', 12);
        $sheet = $this->material('White PVC Sheet 3mm', 'sheet');
        $byArea = $this->material('White PVC 3mm (per m2)', 'm2');

        $data = $this->getJson("/api/procurement-stores/project-materials/{$line->id}/compatibility?material_ids[]={$sheet->id}&material_ids[]={$byArea->id}")
            ->assertOk()->json('data');

        $this->assertSame('incompatible', $data[$sheet->id]['status']);
        $this->assertNotEmpty($data[$sheet->id]['reason']);
        $this->assertSame('compatible', $data[$byArea->id]['status']);
        $this->assertSame('alias', $data[$byArea->id]['basis']);
    }
}
