<?php

namespace Tests\Feature\Stores;

use App\Models\ElementMaterial;
use App\Models\User;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Report 79E — the issue sheet: one server answer per project line, with
 * identity and readiness kept apart, and bulk match confirmation.
 */
class ProjectIssueSheetTest extends TestCase
{
    use \Tests\Concerns\GrantsMatrixPermissions;
    use RefreshDatabase;

    private User $user;
    private int $enquiryId;
    private int $projectId;
    private int $elementId;
    private int $workstationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(ExpenseCodeSeeder::class);

        Role::findOrCreate('Stores', 'web');
        $this->grantMatrixPermissions('Stores');
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('Stores');
        Sanctum::actingAs($this->user->fresh());

        $this->workstationId = DB::table('workstations')->insertGetId(['name' => 'Main', 'code' => 'WS-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        [$this->enquiryId, $this->projectId, $this->elementId] = $this->job('in_progress', approved: true);
    }

    /** @return array{0: int, 1: int, 2: int} enquiry, project, element */
    private function job(string $status, bool $approved): array
    {
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000', 'address' => 'Nairobi',
            'city' => 'Nairobi', 'county' => 'Nairobi', 'customer_type' => 'company', 'lead_source' => 'test',
            'preferred_contact' => 'email', 'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $code = 'WNG-SHEET-'.uniqid();
        $enquiryId = DB::table('project_enquiries')->insertGetId([
            'status' => $status, 'date_received' => now()->toDateString(), 'client_id' => $clientId, 'title' => 'Stand build',
            'contact_person' => 'C', 'enquiry_number' => 'ENQ-'.$code, 'job_number' => $code, 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $projectId = DB::table('projects')->insertGetId(['enquiry_id' => $enquiryId, 'project_id' => $code, 'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now()]);
        $taskId = DB::table('enquiry_tasks')->insertGetId(['project_enquiry_id' => $enquiryId, 'type' => 'materials', 'title' => 'Materials', 'status' => 'in_progress', 'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);
        $dataId = DB::table('task_materials_data')->insertGetId(['enquiry_task_id' => $taskId, 'project_info' => json_encode(['approval_status' => ['all_approved' => $approved]]), 'created_at' => now(), 'updated_at' => now()]);
        $elementId = \App\Models\ProjectElement::create(['persistent_id' => (string) Str::uuid(), 'task_materials_data_id' => $dataId, 'element_type' => 'stand', 'name' => 'BOOTH', 'category' => 'production', 'is_included' => true])->id;

        return [$enquiryId, $projectId, $elementId];
    }

    private function material(string $name, ?float $onHand, string $unit = 'sheet'): LibraryMaterial
    {
        $material = LibraryMaterial::create([
            'workstation_id' => $this->workstationId, 'material_name' => $name, 'material_code' => 'MAT-'.uniqid(),
            'category' => 'Materials', 'unit_of_measure' => $unit, 'unit_cost' => 1500, 'item_status' => 'Active', 'is_active' => true,
        ]);
        if ($onHand !== null) {
            Stock::create(['material_id' => $material->id, 'quantity_on_hand' => $onHand, 'quantity_reserved' => 0]);
        }

        return $material;
    }

    private function line(?LibraryMaterial $material, string $description, float $quantity, string $unit = 'sheet', ?int $elementId = null): ElementMaterial
    {
        return ElementMaterial::create([
            'project_element_id' => $elementId ?? $this->elementId, 'library_material_id' => $material?->id,
            'description' => $description, 'unit_of_measurement' => $unit, 'quantity' => $quantity, 'is_included' => true,
        ]);
    }

    private function sheet(?int $projectId = null): array
    {
        return $this->getJson('/api/procurement-stores/projects/'.($projectId ?? $this->projectId).'/issue-sheet')->assertOk()->json('data');
    }

    private function row(array $sheet, ElementMaterial $line): array
    {
        return collect($sheet['lines'])->firstWhere('line_id', $line->id);
    }

    public function test_a_linked_line_with_stock_is_ready_with_a_suggested_quantity(): void
    {
        $mdf = $this->material('MDF 18mm', 8);
        $line = $this->line($mdf, 'Plain MDF 18mm board', 5);

        $row = $this->row($this->sheet(), $line);

        $this->assertSame('ready', $row['readiness']);
        $this->assertSame('verified', $row['identity']['status']);
        $this->assertSame('Plain MDF 18mm board', $row['description'], 'The typed requirement is never replaced.');
        $this->assertEquals(5, $row['quantities']['remaining']);
        $this->assertEquals(8, $row['stock']['available']);
        $this->assertEquals(5, $row['suggested_issue']);
        $this->assertSame(['issue'], $row['actions']);
    }

    public function test_short_stock_is_partial_and_empty_stock_is_awaiting_stock(): void
    {
        $short = $this->line($this->material('Vinyl Gloss White', 3), 'Gloss white vinyl', 5);
        $empty = $this->line($this->material('Primer Metal', 0), 'Metal primer', 2);

        $sheet = $this->sheet();

        $this->assertSame('partial', $this->row($sheet, $short)['readiness']);
        $this->assertEquals(3, $this->row($sheet, $short)['suggested_issue']);
        $this->assertSame('awaiting_stock', $this->row($sheet, $empty)['readiness']);
        $this->assertEquals(0, $this->row($sheet, $empty)['suggested_issue']);
    }

    public function test_an_issued_line_is_fulfilled(): void
    {
        $mdf = $this->material('MDF 18mm', 8);
        $line = $this->line($mdf, 'MDF 18mm', 2);
        $this->postJson('/api/procurement-stores/movements', ['type' => 'issue', 'lines' => [[
            'material_id' => $mdf->id, 'project_material_id' => $line->id, 'project_id' => $this->projectId,
            'quantity' => 2, 'recipient_name' => 'Otieno', 'reference_no' => 'X',
        ]]])->assertOk();

        $row = $this->row($this->sheet(), $line);

        $this->assertSame('fulfilled', $row['readiness']);
        $this->assertEquals(2, $row['quantities']['issued']);
    }

    public function test_an_unlinked_line_with_one_clear_match_is_offered_for_one_click_confirmation(): void
    {
        $this->material('Duct Tape Black', 10, 'pcs');
        $line = $this->line(null, 'black duct tape', 2, 'pcs');

        $row = $this->row($this->sheet(), $line);

        $this->assertSame('needs_match', $row['readiness']);
        $this->assertSame('suggested', $row['identity']['status']);
        $this->assertTrue($row['identity']['one_click']);
        $this->assertSame('Duct Tape Black', $row['identity']['candidates'][0]['name']);
        $this->assertContains('confirm_match', $row['actions']);
        $this->assertNull(ElementMaterial::find($line->id)->library_material_id, 'A suggestion never links on its own.');
    }

    public function test_a_clear_match_whose_unit_cannot_be_read_is_not_one_click(): void
    {
        $this->material('Duct Tape Black', 10, 'roll');
        $line = $this->line(null, 'black duct tape', 2, 'pcs');

        $identity = $this->row($this->sheet(), $line)['identity'];

        $this->assertSame('suggested', $identity['status']);
        $this->assertFalse($identity['one_click']);
        $this->assertFalse($identity['candidates'][0]['unit_reads']);
    }

    public function test_a_generic_description_asks_only_for_the_size(): void
    {
        foreach (['MDF 6mm', 'MDF 9mm', 'MDF 18mm'] as $name) {
            $this->material($name, 5);
        }
        $line = $this->line(null, 'MDF board', 3);

        $row = $this->row($this->sheet(), $line);

        $this->assertSame('ambiguous', $row['identity']['status']);
        $this->assertSame('size', $row['identity']['distinguish']['label']);
        $this->assertEqualsCanonicalizing(['6mm', '9mm', '18mm'], array_column($row['identity']['distinguish']['values'], 'value'));
        $this->assertContains('choose_variant', $row['actions']);
    }

    public function test_a_line_nothing_matches_is_no_match(): void
    {
        $line = $this->line(null, 'Custom acrylic panel with engraved logo', 1, 'pcs');

        $row = $this->row($this->sheet(), $line);

        $this->assertSame('no_match', $row['identity']['status']);
        $this->assertSame(['resolve'], $row['actions']);
    }

    public function test_an_unreadable_unit_is_unit_review_with_the_reason(): void
    {
        $line = $this->line($this->material('Vinyl Gloss White', 18), 'Gloss white vinyl', 12, 'sqm');

        $row = $this->row($this->sheet(), $line);

        $this->assertSame('unit_review', $row['readiness']);
        $this->assertNotEmpty($row['blocking_reason']);
        $this->assertSame(['set_unit_reading'], $row['actions']);
    }

    public function test_an_unapproved_list_and_a_cancelled_job_cannot_issue(): void
    {
        [, $draftProject, $draftElement] = $this->job('in_progress', approved: false);
        [, $cancelledProject, $cancelledElement] = $this->job('cancelled', approved: true);
        $mdf = $this->material('MDF 18mm', 8);
        $draft = $this->line($mdf, 'MDF 18mm', 1, 'sheet', $draftElement);
        $cancelled = $this->line($mdf, 'MDF 18mm', 1, 'sheet', $cancelledElement);

        $this->assertSame('approval_required', $this->row($this->sheet($draftProject), $draft)['readiness']);
        $sheet = $this->sheet($cancelledProject);
        $this->assertSame('restricted', $this->row($sheet, $cancelled)['readiness']);
        $this->assertFalse($sheet['project']['can_issue']);
    }

    public function test_a_non_stock_line_is_not_issued_from_stores(): void
    {
        $line = $this->line(null, 'Venue electricity', 1, 'pcs');
        $line->forceFill(['requirement_disposition' => ElementMaterial::DISPOSITION_NON_STOCK])->save();

        $this->assertSame('non_stock', $this->row($this->sheet(), $line)['readiness']);
    }

    public function test_counts_summarise_the_lines(): void
    {
        $this->line($this->material('MDF 18mm', 8), 'MDF 18mm', 5);
        $this->line(null, 'Custom acrylic panel', 1, 'pcs');

        $counts = $this->sheet()['counts'];

        $this->assertSame(1, $counts['ready']);
        $this->assertSame(1, $counts['needs_match']);
    }

    public function test_the_sheet_does_not_query_per_line(): void
    {
        foreach (range(1, 3) as $i) {
            $this->line($this->material("Board {$i} ".uniqid(), 5), "Board {$i}", 1);
            $this->line(null, "Unknown thing {$i}", 1, 'pcs');
        }
        DB::enableQueryLog();
        $this->sheet();
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();

        foreach (range(4, 15) as $i) {
            $this->line($this->material("Board {$i} ".uniqid(), 5), "Board {$i}", 1);
            $this->line(null, "Unknown thing {$i}", 1, 'pcs');
        }
        DB::flushQueryLog();
        $this->sheet();
        $large = count(DB::getQueryLog());

        $this->assertLessThanOrEqual($small + 3, $large, "6 lines: {$small} queries; 30 lines: {$large}.");
    }

    public function test_bulk_confirm_links_each_line_and_reports_the_ones_it_could_not(): void
    {
        $tape = $this->material('Duct Tape Black', 10, 'pcs');
        $mdf = $this->material('MDF 18mm', 8);
        $first = $this->line(null, 'black duct tape', 2, 'pcs');
        $second = $this->line(null, 'mdf 18mm', 1);
        $alreadyLinked = $this->line($mdf, 'MDF 18mm', 1);

        $response = $this->postJson('/api/procurement-stores/project-materials/resolve-batch', ['items' => [
            ['project_material_id' => $first->id, 'material_id' => $tape->id],
            ['project_material_id' => $second->id, 'material_id' => $mdf->id],
            ['project_material_id' => $alreadyLinked->id, 'material_id' => $mdf->id],
        ]])->assertOk();

        $this->assertSame(2, $response->json('linked'));
        $this->assertSame(1, $response->json('failed'));
        $this->assertFalse(collect($response->json('results'))->firstWhere('project_material_id', $alreadyLinked->id)['ok']);
        $this->assertSame($tape->id, (int) $first->fresh()->library_material_id);
        $this->assertSame('black duct tape', $first->fresh()->description, 'Linking keeps the typed words.');
        $this->assertSame(0, DB::table('inventory_logs')->count(), 'Matching never moves stock.');
    }

    public function test_people_without_stores_access_cannot_read_or_confirm(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($outsider);

        $this->getJson("/api/procurement-stores/projects/{$this->projectId}/issue-sheet")->assertForbidden();
        $this->postJson('/api/procurement-stores/project-materials/resolve-batch', ['items' => [['project_material_id' => 1, 'material_id' => 1]]])->assertForbidden();
    }
}
