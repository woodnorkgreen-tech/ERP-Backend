<?php

namespace Tests\Feature\CostCollector;

use App\Events\EnquiryTaskCompleted;
use App\Listeners\ProjectBudgetLines;
use App\Listeners\ProjectBudgetLinesOnTaskCompletion;
use App\Models\TaskBudgetData;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Completing a budget task opens the project's cost account.
 *
 * Before this the projector only ran from an artisan command, so a budget
 * approved today did not reach its cost account until somebody remembered to
 * run one — the figure looked live and was a snapshot.
 *
 * Completion is no longer the only trigger — budget writes announce the same
 * thing (see MaterialToCostChainTest) — but it is still a trigger, because a
 * budget priced before that wiring existed reaches its account when its task is
 * closed.
 */
class BudgetProjectionOnCompletionTest extends TestCase
{
    use RefreshDatabase;

    private EnquiryTask $task;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);

        $this->user = User::factory()->create();

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid() . '@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Activation', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-EVT-001', 'job_number' => 'WNG-EVT-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->task = EnquiryTask::create([
            'project_enquiry_id' => $enquiryId,
            'title' => 'Budget', 'type' => 'budget', 'created_by' => $this->user->id,
        ]);
    }

    private function budget(): TaskBudgetData
    {
        return TaskBudgetData::create([
            'enquiry_task_id' => $this->task->id,
            'project_info' => [],
            'materials_data' => [],
            'expenses_data' => [
                ['id' => 'expense-1', 'description' => 'Site permits', 'amount' => 8000],
                ['id' => 'expense-2', 'description' => 'Crew meals', 'amount' => 3500],
            ],
            'budget_summary' => ['grandTotal' => 11500],
        ]);
    }

    private function fire(): void
    {
        app(ProjectBudgetLinesOnTaskCompletion::class)->handle(new EnquiryTaskCompleted(
            $this->task->id, 'budget', $this->task->project_enquiry_id, $this->user->id,
        ));
    }

    public function test_completing_a_budget_task_opens_the_cost_account(): void
    {
        $this->budget();

        $this->fire();

        $this->assertSame(2, CostLine::where('nature', CostLine::NATURE_PLANNED)->count());
        $this->assertSame('11500.00', number_format(
            (float) CostLine::counting()->sum('net_amount'), 2, '.', '',
        ));
    }

    public function test_completing_any_other_task_projects_nothing(): void
    {
        $this->budget();

        app(ProjectBudgetLinesOnTaskCompletion::class)->handle(new EnquiryTaskCompleted(
            $this->task->id, 'production', $this->task->project_enquiry_id, $this->user->id,
        ));

        $this->assertSame(0, CostLine::count());
    }

    public function test_a_task_completed_twice_does_not_double_the_budget(): void
    {
        $this->budget();

        $this->fire();
        $this->fire();

        $this->assertSame(2, CostLine::count());
    }

    public function test_a_budget_task_with_no_budget_data_is_survivable(): void
    {
        // Completion is already gated on a priced budget, so this is a race or a
        // manual status change — it must not throw.
        $this->fire();

        $this->assertSame(0, CostLine::count());
    }

    public function test_the_projection_is_queued(): void
    {
        // The whole point of the seam: a cost-ledger failure must never stop
        // somebody completing their task. The guarantee sits on the listener
        // that does the ledger write — completing a task only announces it, and
        // announcing cannot fail in a way worth queueing.
        $this->assertInstanceOf(ShouldQueue::class, app(ProjectBudgetLines::class));
        $this->assertNotInstanceOf(ShouldQueue::class, app(ProjectBudgetLinesOnTaskCompletion::class));
    }
}
