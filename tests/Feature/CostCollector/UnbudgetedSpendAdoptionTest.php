<?php

namespace Tests\Feature\CostCollector;

use App\Models\User;
use App\Modules\Finance\CostCollector\Contracts\PlannedLine;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostCollectorService;
use App\Modules\Finance\CostCollector\Services\BudgetProjector;
use App\Modules\Finance\CostCollector\Services\StoresCostProducer;
use App\Modules\Finance\CostCollector\Services\UnbudgetedSpendAdopter;
use App\Models\TaskBudgetData;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spend posted before its budget existed.
 *
 * Stores issues a material on Tuesday; the budget is projected on Wednesday. The
 * issue found no planned line and was recorded unbudgeted — correct at the time,
 * and then permanent, because the match was only ever attempted at posting time.
 *
 * The cost account then reported the same money two contradictory ways at once:
 * as unplanned spend, and as a budget line with nothing reported against it —
 * while the drawdown cap still saw the whole budget as available.
 */
class UnbudgetedSpendAdoptionTest extends TestCase
{
    use RefreshDatabase;

    private StoresCostProducer $producer;
    private CostCollectorService $collector;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(ExpenseCodeSeeder::class);

        $this->producer = app(StoresCostProducer::class);
        $this->collector = app(CostCollectorService::class);
        $this->user = User::factory()->create();
    }

    public function test_budget_line_claims_spend_that_was_posted_before_it_existed(): void
    {
        [$enquiryId, $projectId, $material, $materialLineId, $persistentId] = $this->project();

        $cost = $this->issue($projectId, $material, $materialLineId, quantity: 3, unitCost: '2000.00');

        // Correct at the time: there was no budget to claim.
        $this->assertNull($cost->consumes_line_id);
        $this->assertTrue($cost->details['unbudgeted'] ?? false);

        $planned = $this->projectBudget($enquiryId, $persistentId, $material, '9000.00');
        $this->adopt($enquiryId);

        $cost->refresh();

        $this->assertSame($planned->id, $cost->consumes_line_id);
        $this->assertArrayNotHasKey('unbudgeted', (array) $cost->details);

        // The snapshot has to describe the line the cost now claims, not the
        // nothing it claimed before.
        $this->assertSame('9000.00', (string) $cost->budget_remaining_before);
        $this->assertSame('3000.00', (string) $cost->budget_remaining_after);
    }

    public function test_the_cost_account_stops_reporting_the_same_money_two_ways(): void
    {
        [$enquiryId, $projectId, $material, $materialLineId, $persistentId] = $this->project();

        $this->issue($projectId, $material, $materialLineId, quantity: 3, unitCost: '2000.00');
        $planned = $this->projectBudget($enquiryId, $persistentId, $material, '9000.00');
        $this->adopt($enquiryId);

        $account = app(\App\Modules\Finance\CostCollector\Services\CostAccountService::class)
            ->forEnquiry(\App\Models\ProjectEnquiry::findOrFail($enquiryId));

        // Was 6,000.00 of "unplanned" spend against a budget line reported as
        // having nothing spent on it.
        $this->assertSame('0.00', $account['unbudgeted']['total']);
        $this->assertSame(0, $account['unbudgeted']['count']);
        $this->assertSame(1, $account['coverage']['lines_with_spend']);
        $this->assertSame(0, $account['coverage']['lines_awaiting']);

        $this->assertNotNull($planned->fresh());
    }

    /**
     * An issue that cannot be told apart is left alone.
     *
     * Two budget lines for the same catalogue item in the same element make the
     * match ambiguous, and guessing is what once charged spend to an unrelated
     * budget. Unbudgeted is the honest answer.
     */
    public function test_ambiguous_identity_is_left_unbudgeted(): void
    {
        [$enquiryId, $projectId, $material, $materialLineId] = $this->project();

        $cost = $this->issue($projectId, $material, $materialLineId, quantity: 3, unitCost: '2000.00');

        // Two lines, neither carrying the project material line's identity, so
        // both are claimable on catalogue identity alone.
        $this->projectBudget($enquiryId, null, $material, '9000.00', sourceRef: 'a');
        $this->projectBudget($enquiryId, null, $material, '4000.00', sourceRef: 'b');
        $this->adopt($enquiryId);

        $cost->refresh();

        $this->assertNull($cost->consumes_line_id);
        $this->assertTrue($cost->details['unbudgeted'] ?? false);
    }

    /** A budget line never claims another project's spend. */
    public function test_spend_on_another_project_is_not_claimed(): void
    {
        [, $projectId, $material, $materialLineId] = $this->project();
        [$otherEnquiryId, , , , $otherPersistentId] = $this->project();

        $cost = $this->issue($projectId, $material, $materialLineId, quantity: 3, unitCost: '2000.00');

        $this->projectBudget($otherEnquiryId, $otherPersistentId, $material, '9000.00');
        $this->adopt($otherEnquiryId);

        $cost->refresh();

        $this->assertNull($cost->consumes_line_id);
    }

    /**
     * The wiring, end to end: saving a budget is what triggers the pass.
     *
     * The adopter is correct on its own and still useless if nothing calls it —
     * which was the actual state of affairs, since a repair command existed for
     * this and had simply never been run.
     */
    public function test_projecting_a_budget_adopts_the_spend_that_preceded_it(): void
    {
        [$enquiryId, $projectId, $material, $materialLineId, $persistentId, $taskId] = $this->project();

        $cost = $this->issue($projectId, $material, $materialLineId, quantity: 3, unitCost: '2000.00');
        $this->assertNull($cost->consumes_line_id);

        $budget = TaskBudgetData::create([
            'enquiry_task_id' => $taskId,
            'project_info' => ['projectId' => 'ENQ-' . $enquiryId],
            'materials_data' => [[
                'name' => 'Stand',
                'materials' => [[
                    'id' => 'mat-1',
                    'persistent_id' => $persistentId,
                    'description' => 'MDF 9mm Sheet',
                    'libraryMaterialId' => $material->id,
                    'unitOfMeasurement' => 'sheet',
                    'quantity' => 3,
                    'unitPrice' => 3000,
                    'totalPrice' => 9000,
                ]],
            ]],
            'budget_summary' => ['grandTotal' => 9000],
            'status' => 'approved',
        ]);

        $result = app(BudgetProjector::class)->project($budget);

        $this->assertSame(1, $result['projected']);
        $this->assertSame(1, $result['adopted']);

        $cost->refresh();
        $this->assertNotNull($cost->consumes_line_id);
        $this->assertArrayNotHasKey('unbudgeted', (array) $cost->details);
    }

    /**
     * The pass BudgetProjector runs once a budget has finished projecting.
     *
     * Called explicitly here so each test controls the moment it happens, which
     * is the whole point of the boundary: run per line instead and the first
     * budget line projected claims a cost before its rival exists.
     */
    private function adopt(int $enquiryId): int
    {
        return app(UnbudgetedSpendAdopter::class)->adoptForEnquiry($enquiryId);
    }

    /** A stock issue against a project whose budget line is already there. */
    private function issue(int $projectId, LibraryMaterial $material, int $materialLineId, float $quantity, string $unitCost): CostLine
    {
        $log = InventoryLog::create([
            'material_id' => $material->id,
            'project_id' => $projectId,
            'project_material_id' => $materialLineId,
            'type' => 'check_out',
            'quantity' => $quantity,
            'receipt_unit_cost' => $unitCost,
            'reference_no' => 'ISS-' . uniqid(),
            'recipient_name' => 'Site',
            'user_id' => $this->user->id,
            'balance_after' => 10.00,
            'logged_at' => now(),
        ]);

        $line = $this->producer->postStockIssue($log);
        $this->assertNotNull($line, 'the stock issue should have produced a cost line');

        return $line;
    }

    /** Projects one budget line the way BudgetProjector does. */
    private function projectBudget(
        int $enquiryId,
        ?string $persistentId,
        LibraryMaterial $material,
        string $amount,
        string $sourceRef = 'default',
    ): CostLine {
        return $this->collector->postPlanned(new PlannedLine(
            category: 'materials',
            amount: $amount,
            description: 'Stand — ' . $material->material_name,
            enquiryId: $enquiryId,
            sourceId: $enquiryId,
            sourceRef: $enquiryId . ':materials:' . $sourceRef,
            details: array_filter([
                'element' => 'Stand',
                'material' => $material->material_name,
                'library_material_id' => $material->id,
                'project_material_id' => $persistentId,
            ], fn ($v) => $v !== null),
        ));
    }

    /**
     * A project with a materials plan line but no projected budget yet.
     *
     * @return array{0:int,1:int,2:LibraryMaterial,3:int,4:string,5:int}
     */
    private function project(): array
    {
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid() . '@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Woodwork Job', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-' . uniqid(), 'job_number' => 'WNG-' . uniqid(),
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $projectId = DB::table('projects')->insertGetId([
            'enquiry_id' => $enquiryId, 'project_id' => 'PRJ-' . uniqid(),
            'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $taskId = DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $enquiryId, 'type' => 'materials',
            'title' => 'Materials', 'status' => 'completed',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $materialsDataId = DB::table('task_materials_data')->insertGetId([
            'enquiry_task_id' => $taskId,
            'project_info' => json_encode(['projectId' => 'ENQ-' . $enquiryId]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $elementId = DB::table('project_elements')->insertGetId([
            'persistent_id' => (string) Str::uuid(),
            'task_materials_data_id' => $materialsDataId,
            'element_type' => 'stand', 'name' => 'Stand', 'category' => 'production',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $persistentId = (string) Str::uuid();

        $materialLineId = DB::table('element_materials')->insertGetId([
            'persistent_id' => $persistentId,
            'project_element_id' => $elementId,
            'description' => 'MDF 9mm Sheet',
            'unit_of_measurement' => 'sheet',
            'quantity' => 3, 'unit_cost' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$enquiryId, $projectId, $this->material(), $materialLineId, $persistentId, $taskId];
    }

    private function material(): LibraryMaterial
    {
        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Main Workshop', 'code' => 'WS-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return LibraryMaterial::create([
            'workstation_id' => $workstationId,
            'material_name' => 'MDF 9mm Sheet',
            'material_code' => 'MAT-' . uniqid(),
            'category' => 'Materials',
            'unit_of_measure' => 'sheet',
            'unit_cost' => 0,
            'item_status' => 'Active',
        ]);
    }
}
