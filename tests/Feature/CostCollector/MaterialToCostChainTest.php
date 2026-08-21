<?php

namespace Tests\Feature\CostCollector;

use App\Events\MaterialsListChanged;
use App\Models\ElementMaterial;
use App\Models\TaskBudgetData;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\StoresCostProducer;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Services\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The whole chain, end to end: materials specification → budget → Stores issue →
 * project cost account.
 *
 * Each hop was already covered on its own. What was not covered is that they
 * connect — and they did not: the budget followed the materials list on every
 * save, but the planned cost lines the account grades spend against only ever
 * refreshed when the budget *task* was completed. A material added afterwards
 * reached the budget and the desk, and never reached the account, so actual
 * spend was measured against a budget several revisions old. Nothing reported
 * that; a stale planned figure looks exactly like a current one.
 *
 * These tests walk the real path — the same events, listeners and producers
 * production uses — rather than asserting each service in isolation.
 */
class MaterialToCostChainTest extends TestCase
{
    use RefreshDatabase;

    private int $enquiryId;
    private int $projectId;
    private int $materialsTaskId;
    private int $materialsDataId;
    private int $budgetTaskId;
    private int $elementId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(ExpenseCodeSeeder::class);

        $this->user = User::factory()->create();

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid() . '@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Safaricom Activation', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-CHAIN-001', 'job_number' => 'WNG-CHAIN-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->projectId = DB::table('projects')->insertGetId([
            'enquiry_id' => $this->enquiryId, 'project_id' => 'WNG-CHAIN-001',
            'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->materialsTaskId = $this->task('materials', 'in_progress');
        $this->budgetTaskId = $this->task('budget', 'in_progress');

        $this->materialsDataId = DB::table('task_materials_data')->insertGetId([
            'enquiry_task_id' => $this->materialsTaskId,
            'project_info' => json_encode(['projectId' => 'WNG-CHAIN-001']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->elementId = DB::table('project_elements')->insertGetId([
            'persistent_id' => (string) Str::uuid(),
            'task_materials_data_id' => $this->materialsDataId,
            'element_type' => 'stand', 'name' => 'BOOTH1', 'category' => 'production',
            'is_included' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function task(string $type, string $status): int
    {
        return DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $this->enquiryId, 'type' => $type,
            'title' => ucfirst($type), 'status' => $status,
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function libraryMaterial(string $name, float $unitCost): LibraryMaterial
    {
        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Main Workshop', 'code' => 'WS-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return LibraryMaterial::create([
            'workstation_id' => $workstationId,
            'material_name' => $name, 'material_code' => 'MAT-' . uniqid(),
            'category' => 'Materials', 'unit_of_measure' => 'sheet',
            'unit_cost' => $unitCost, 'item_status' => 'Active',
        ]);
    }

    /** One line on the materials specification. */
    private function specify(LibraryMaterial $material, float $quantity, ?float $rate = null): ElementMaterial
    {
        return ElementMaterial::create([
            'project_element_id' => $this->elementId,
            'library_material_id' => $material->id,
            'description' => $material->material_name,
            'unit_of_measurement' => 'sheet',
            'quantity' => $quantity,
            'unit_cost' => $rate,
            'is_included' => true,
        ]);
    }

    /** The materials task was saved — the event the controller raises. */
    private function saveSpecification(): void
    {
        MaterialsListChanged::dispatch($this->materialsTaskId);
    }

    /** The movement the desk posts when it issues against a specified line. */
    private function issueLog(LibraryMaterial $material, ElementMaterial $line, float $quantity, float $unitCost): InventoryLog
    {
        return InventoryLog::create([
            'material_id' => $material->id,
            'project_id' => $this->projectId,
            'project_material_id' => $line->id,
            'type' => 'check_out',
            'quantity' => -$quantity,
            'balance_after' => 100,
            'receipt_unit_cost' => $unitCost,
            'reference_no' => 'WNG-CHAIN-001',
            'recipient_name' => 'Site Team',
            'logged_at' => now(),
            'user_id' => $this->user->id,
        ]);
    }

    private function issue(LibraryMaterial $material, ElementMaterial $line, float $quantity, float $unitCost): ?CostLine
    {
        return app(StoresCostProducer::class)
            ->postStockIssue($this->issueLog($material, $line, $quantity, $unitCost));
    }

    private function plannedLines()
    {
        return CostLine::where('project_enquiry_id', $this->enquiryId)
            ->where('nature', CostLine::NATURE_PLANNED)
            ->where('status', CostLine::STATUS_VERIFIED)
            ->get();
    }

    public function test_a_specified_material_reaches_the_budget_and_the_cost_account(): void
    {
        $board = $this->libraryMaterial('MDF 9mm Sheet', 1500);
        $this->specify($board, 10);

        $this->saveSpecification();

        // Budget picked it up...
        $budget = TaskBudgetData::where('enquiry_task_id', $this->budgetTaskId)->firstOrFail();
        $this->assertSame('BOOTH1', $budget->materials_data[0]['name']);
        $this->assertSame('MDF 9mm Sheet', $budget->materials_data[0]['materials'][0]['description']);

        // ...and so did the cost account, without anyone completing a task.
        $planned = $this->plannedLines();
        $this->assertCount(1, $planned);
        $this->assertSame('15000.00', $planned->first()->net_amount);
        $this->assertSame('BOOTH1', $planned->first()->details['element']);
        $this->assertSame('MDF 9mm Sheet', $planned->first()->details['material']);
    }

    public function test_a_material_added_after_the_budget_is_done_still_reaches_the_account(): void
    {
        $board = $this->libraryMaterial('MDF 9mm Sheet', 1500);
        $this->specify($board, 10);
        $this->saveSpecification();

        // The budget task is finished and signed off. This is precisely when a
        // late addition used to stop travelling.
        DB::table('enquiry_tasks')->where('id', $this->budgetTaskId)
            ->update(['status' => 'completed', 'completed_at' => now()]);

        $screws = $this->libraryMaterial('Chipboard Screws 4x40mm', 20);
        $this->specify($screws, 100);
        $this->saveSpecification();

        $planned = $this->plannedLines();
        $this->assertCount(2, $planned);
        $this->assertEqualsCanonicalizing(
            ['MDF 9mm Sheet', 'Chipboard Screws 4x40mm'],
            $planned->pluck('details.material')->all(),
        );
        $this->assertSame('17000.00', number_format((float) $planned->sum('net_amount'), 2, '.', ''));
    }

    public function test_repricing_the_budget_replaces_the_planned_line_rather_than_adding_one(): void
    {
        $board = $this->libraryMaterial('MDF 9mm Sheet', 1500);
        $line = $this->specify($board, 10);
        $this->saveSpecification();

        $budget = TaskBudgetData::where('enquiry_task_id', $this->budgetTaskId)->firstOrFail();
        $materials = $budget->materials_data;
        $materials[0]['materials'][0]['unitPrice'] = 1800;
        $materials[0]['materials'][0]['totalPrice'] = 18000;

        app(BudgetService::class)->saveBudgetData($this->budgetTaskId, [
            'projectInfo' => $budget->project_info,
            'materials' => $materials,
            'labour' => [], 'expenses' => [], 'logistics' => [],
        ]);

        // One live line at the new price — a re-price must never double a budget.
        $planned = $this->plannedLines();
        $this->assertCount(1, $planned);
        $this->assertSame('18000.00', $planned->first()->net_amount);

        // The superseded figure is kept, reversed, not deleted.
        $this->assertSame(1, CostLine::where('project_enquiry_id', $this->enquiryId)
            ->where('nature', CostLine::NATURE_PLANNED)
            ->where('status', CostLine::STATUS_REVERSED)->count());

        $this->assertNotNull($line->fresh());
    }

    public function test_issuing_against_a_specified_line_charges_the_budget_line_it_consumes(): void
    {
        $board = $this->libraryMaterial('MDF 9mm Sheet', 1500);
        $line = $this->specify($board, 10);
        $this->saveSpecification();

        $planned = $this->plannedLines()->first();
        $actual = $this->issue($board, $line, 4, 1500);

        $this->assertNotNull($actual);
        $this->assertSame(CostLine::NATURE_ACTUAL, $actual->nature);
        $this->assertSame('6000.00', $actual->net_amount);

        // It found its budget line rather than landing as unplanned spend...
        $this->assertSame($planned->id, $actual->consumes_line_id);

        // ...and carries the same element and material, so the account can group
        // the plan and the spend on one row.
        $this->assertSame('BOOTH1', $actual->details['element']);
        $this->assertSame('MDF 9mm Sheet', $actual->details['material']);
    }

    public function test_returning_material_gives_the_budget_line_its_headroom_back(): void
    {
        // The loop has to close: material that came back is not material the
        // project consumed, and the budget line it was charged to must be
        // spendable again or the account permanently understates what is left.
        $board = $this->libraryMaterial('MDF 9mm Sheet', 1500);
        $line = $this->specify($board, 10);
        $this->saveSpecification();

        $planned = $this->plannedLines()->first();
        $issue = $this->issueLog($board, $line, 4, 1500);
        app(StoresCostProducer::class)->postStockIssue($issue);

        $return = InventoryLog::create([
            'material_id' => $board->id,
            'project_id' => $this->projectId,
            'project_material_id' => $line->id,
            'original_issue_log_id' => $issue->id,
            'type' => 'return',
            'quantity' => 1,
            'balance_after' => 100,
            'reference_no' => 'WNG-CHAIN-001',
            'logged_at' => now(),
            'user_id' => $this->user->id,
        ]);

        $credit = app(StoresCostProducer::class)->postStockReturn($return);

        $this->assertNotNull($credit);
        $this->assertSame('-1500.00', $credit->net_amount);

        // Charged back to the same budget line, and still grouped with it.
        $this->assertSame($planned->id, $credit->consumes_line_id);
        $this->assertSame('BOOTH1', $credit->details['element']);
        $this->assertSame('MDF 9mm Sheet', $credit->details['material']);

        // Net charge is 3 sheets, not 4.
        $charged = CostLine::where('project_enquiry_id', $this->enquiryId)
            ->where('nature', CostLine::NATURE_ACTUAL)
            ->where('status', CostLine::STATUS_VERIFIED)
            ->sum('net_amount');
        $this->assertSame('4500.00', number_format((float) $charged, 2, '.', ''));
    }

    public function test_renaming_an_element_moves_its_history_with_it(): void
    {
        // Somebody corrects "BOOTH1" to "Booth 1". If spend kept the name it was
        // posted under, one stand would read as two elements that never
        // reconcile — and the correction would look like it created a problem.
        $board = $this->libraryMaterial('MDF 9mm Sheet', 1500);
        $line = $this->specify($board, 10);
        $this->saveSpecification();
        $this->issue($board, $line, 4, 1500);

        DB::table('project_elements')->where('id', $this->elementId)->update(['name' => 'Booth 1']);
        $this->saveSpecification();

        $elements = collect(app(\App\Modules\Finance\CostCollector\Services\CostAccountService::class)
            ->forEnquiry(\App\Models\ProjectEnquiry::findOrFail($this->enquiryId))['elements']);

        $this->assertSame(['Booth 1'], $elements->pluck('element')->all());
        $this->assertSame('6000.00', $elements->first()['spent']);
        $this->assertSame('15000.00', $elements->first()['planned']);
    }

    public function test_issuing_a_material_that_was_never_specified_is_reported_as_unplanned(): void
    {
        $board = $this->libraryMaterial('MDF 9mm Sheet', 1500);
        $this->specify($board, 10);
        $this->saveSpecification();

        // Something grabbed off the shelf that nobody budgeted for.
        $tape = $this->libraryMaterial('Double-sided Tape', 350);
        $log = InventoryLog::create([
            'material_id' => $tape->id, 'project_id' => $this->projectId,
            'type' => 'check_out', 'quantity' => -6, 'balance_after' => 100, 'receipt_unit_cost' => 350,
            'reference_no' => 'WNG-CHAIN-001', 'recipient_name' => 'Site Team',
            'logged_at' => now(), 'user_id' => $this->user->id,
        ]);

        $actual = app(StoresCostProducer::class)->postStockIssue($log);

        $this->assertNotNull($actual);
        $this->assertNull($actual->consumes_line_id, 'Unspecified spend must not adopt an unrelated budget line.');
        $this->assertTrue((bool) $actual->details['unbudgeted']);
        $this->assertSame('Double-sided Tape', $actual->details['material']);
    }

    public function test_removing_a_specified_material_that_was_never_issued_retires_its_budget_line(): void
    {
        $board = $this->libraryMaterial('MDF 9mm Sheet', 1500);
        $screws = $this->libraryMaterial('Chipboard Screws 4x40mm', 20);
        $this->specify($board, 10);
        $dropped = $this->specify($screws, 100);
        $this->saveSpecification();

        $this->assertCount(2, $this->plannedLines());

        $dropped->delete();
        $this->saveSpecification();

        $planned = $this->plannedLines();
        $this->assertCount(1, $planned);
        $this->assertSame('MDF 9mm Sheet', $planned->first()->details['material']);
    }

    public function test_removing_a_material_that_was_already_issued_keeps_its_budget_line(): void
    {
        // Spend has been charged against this line. Retiring it would leave the
        // cost stranded against a budget line that no longer exists, and the
        // project would read as though the money was never planned for.
        $board = $this->libraryMaterial('MDF 9mm Sheet', 1500);
        $line = $this->specify($board, 10);
        $this->saveSpecification();

        $this->issue($board, $line, 4, 1500);

        $line->delete();
        $this->saveSpecification();

        $planned = $this->plannedLines();
        $this->assertCount(1, $planned);
        $this->assertSame('MDF 9mm Sheet', $planned->first()->details['material']);
    }
}
