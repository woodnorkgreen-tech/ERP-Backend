<?php

namespace Tests\Feature\CostCollector;

use App\Models\TaskBudgetData;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\BudgetProjector;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fixtures here reproduce the shapes actually stored in task_budget_data —
 * nested materials with `is_included`, and the flat labour/expenses/logistics
 * rows — rather than an idealised shape the projector would find convenient.
 */
class BudgetProjectorTest extends TestCase
{
    use RefreshDatabase;

    private BudgetProjector $projector;
    private EnquiryTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);

        $this->projector = app(BudgetProjector::class);
        $this->task = $this->makeTask();
    }

    private function makeTask(): EnquiryTask
    {
        $user = User::factory()->create();

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Test Client', 'email' => 'client@test.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Test Activation', 'contact_person' => 'Test Contact',
            'enquiry_number' => 'ENQ-TEST-001', 'job_number' => 'WNG-TEST-001',
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return EnquiryTask::create([
            'project_enquiry_id' => $enquiryId,
            'title' => 'Budget',
            'type' => 'budget',
            'created_by' => $user->id,
        ]);
    }

    private function budget(array $data = []): TaskBudgetData
    {
        return TaskBudgetData::create(array_merge([
            'enquiry_task_id' => $this->task->id,
            'project_info' => [],
            'materials_data' => [],
            'budget_summary' => ['grandTotal' => 1],
        ], $data));
    }

    public function test_it_flattens_nested_materials_and_honours_the_exclusion_flag(): void
    {
        $budget = $this->budget(['materials_data' => [[
            'id' => '55e7e169-ad1d-4d7a-bf27-d0ae11a5a830',
            'name' => 'Reception Counter',
            'materials' => [
                [
                    'id' => '2495f5e6-b609-456c-b506-1ab0ffc1cf83',
                    'description' => 'MDF board 18mm', 'unitOfMeasurement' => 'pcs',
                    'quantity' => 10, 'unitPrice' => 2500, 'totalPrice' => 25000,
                    'is_included' => true,
                ],
                [
                    'id' => '4911e61d-c080-4b46-9cde-881b0dec425d',
                    'description' => 'Excluded item', 'unitOfMeasurement' => 'pcs',
                    'quantity' => 4, 'unitPrice' => 500, 'totalPrice' => 2000,
                    'is_included' => false,
                ],
            ],
        ]]]);

        $result = $this->projector->project($budget);

        $this->assertSame(1, $result['projected']);

        $line = CostLine::where('source_ref', '2495f5e6-b609-456c-b506-1ab0ffc1cf83')->firstOrFail();
        $this->assertSame(CostLine::NATURE_PLANNED, $line->nature);
        $this->assertSame(CostLine::STATUS_VERIFIED, $line->status);
        $this->assertSame('25000.00', $line->net_amount);
        $this->assertSame('pcs', $line->unit);
        $this->assertSame('Reception Counter — MDF board 18mm', $line->description);
        $this->assertSame('materials', $line->details['budget_category']);

        $this->assertDatabaseMissing('cost_lines', ['source_ref' => '4911e61d-c080-4b46-9cde-881b0dec425d']);
    }

    public function test_it_projects_the_three_flat_arrays(): void
    {
        $budget = $this->budget([
            'labour_data' => [[
                'id' => 'labour-1784964925457', 'type' => 'Casual', 'description' => 'Installers',
                'unit' => 'days', 'quantity' => 3, 'unitRate' => 1500, 'amount' => 4500,
            ]],
            'expenses_data' => [[
                'id' => 'expense-1784810717253', 'description' => 'Site permits', 'amount' => 8000,
            ]],
            'logistics_data' => [[
                'id' => 'logistics-1784873901073', 'vehicleReg' => 'KDA 123X',
                'description' => 'Truck hire', 'unit' => 'trips',
                'quantity' => 2, 'unitRate' => 12500, 'amount' => 25000,
            ]],
        ]);

        $this->assertSame(3, $this->projector->project($budget)['projected']);

        $labour = CostLine::where('source_ref', 'labour-1784964925457')->firstOrFail();
        $this->assertSame('4500.00', $labour->net_amount);
        $this->assertSame('labour', $labour->details['budget_category']);

        // Expenses carry only an amount — no quantity or rate to record.
        $expense = CostLine::where('source_ref', 'expense-1784810717253')->firstOrFail();
        $this->assertSame('8000.00', $expense->net_amount);
        $this->assertNull($expense->quantity);

        $logistics = CostLine::where('source_ref', 'logistics-1784873901073')->firstOrFail();
        $this->assertSame('KDA 123X — Truck hire', $logistics->description);
    }

    public function test_reprojecting_an_unchanged_budget_changes_nothing(): void
    {
        $budget = $this->budget(['expenses_data' => [
            ['id' => 'expense-1', 'description' => 'Permits', 'amount' => 8000],
        ]]);

        $this->projector->project($budget);
        $firstId = CostLine::firstOrFail()->id;

        $this->projector->project($budget);

        $this->assertSame(1, CostLine::count());
        $this->assertSame($firstId, CostLine::firstOrFail()->id);
    }

    public function test_a_line_deleted_from_the_budget_stops_counting(): void
    {
        $budget = $this->budget(['expenses_data' => [
            ['id' => 'expense-1', 'description' => 'Permits', 'amount' => 8000],
            ['id' => 'expense-2', 'description' => 'Dropped later', 'amount' => 3000],
        ]]);

        $this->projector->project($budget);
        $this->assertSame(2, CostLine::counting()->count());

        $budget->update(['expenses_data' => [
            ['id' => 'expense-1', 'description' => 'Permits', 'amount' => 8000],
        ]]);

        $this->assertSame(1, $this->projector->project($budget)['retired']);

        $this->assertSame(CostLine::STATUS_REVERSED,
            CostLine::where('source_ref', 'expense-2')->firstOrFail()->status);
        $this->assertSame(1, CostLine::counting()->count());
    }

    public function test_a_consumed_line_is_never_retired_behind_a_real_cost(): void
    {
        $budget = $this->budget(['expenses_data' => [
            ['id' => 'expense-1', 'description' => 'Permits', 'amount' => 8000],
        ]]);

        $this->projector->project($budget);
        $planned = CostLine::where('source_ref', 'expense-1')->firstOrFail();

        // Someone has already spent against this line.
        CostLine::create([
            'ref' => 'CL-9999999', 'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_VERIFIED, 'consumes_line_id' => $planned->id,
            'amount' => '5000.00', 'tax_amount' => '0.00',
            'net_amount' => '5000.00', 'base_net_amount' => '5000.00',
        ]);

        $budget->update(['expenses_data' => []]);

        // Reversing it would orphan the actual, so it is left for a human.
        $this->assertSame(0, $this->projector->project($budget)['retired']);
        $this->assertSame(CostLine::STATUS_VERIFIED, $planned->fresh()->status);
    }

    public function test_a_budget_addition_is_recorded_as_a_client_change(): void
    {
        $budget = $this->budget(['expenses_data' => [
            ['id' => 'expense-1', 'description' => 'Extra scope', 'amount' => 5000, 'isAddition' => true],
        ]]);

        $this->projector->project($budget);

        $line = CostLine::where('source_ref', 'expense-1')->firstOrFail();
        $cause = DB::table('cost_causes')->find($line->cost_cause_id);

        $this->assertSame('CLIENT-CHANGE', $cause->code);
        $this->assertTrue($line->details['is_addition']);
    }

    public function test_zero_priced_placeholder_rows_are_not_budget(): void
    {
        $budget = $this->budget(['expenses_data' => [
            ['id' => 'expense-1', 'description' => 'Not yet priced', 'amount' => 0],
            ['id' => 'expense-2', 'description' => 'Priced', 'amount' => 1200],
        ]]);

        $result = $this->projector->project($budget);

        $this->assertSame(1, $result['projected']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_it_carries_project_identity_and_the_task_stage(): void
    {
        $budget = $this->budget(['expenses_data' => [
            ['id' => 'expense-1', 'description' => 'Permits', 'amount' => 8000],
        ]]);

        $this->projector->project($budget);
        $line = CostLine::firstOrFail();

        $this->assertSame($this->task->project_enquiry_id, $line->project_enquiry_id);
        $this->assertSame('WNG-TEST-001', $line->job_number);

        // Activity resolves from the task's workflow type, not from a guess.
        $activity = DB::table('activities')->find($line->activity_id);
        $this->assertSame('budget', $activity->workflow_task_type);
    }
}
