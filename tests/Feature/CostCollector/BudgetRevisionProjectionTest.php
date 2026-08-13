<?php

namespace Tests\Feature\CostCollector;

use App\Events\BudgetAdditionApproved;
use App\Listeners\ProjectApprovedBudgetAddition;
use App\Models\BudgetAddition;
use App\Models\TaskBudgetData;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\BudgetRevisionProjector;
use App\Modules\Projects\Models\EnquiryTask;
use App\Services\BudgetAdditionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * An approved budget addition has to move the ceiling.
 *
 * The gap this closes: approval changed a status and wrote a governance audit
 * row, and nothing reached the cost account — so a project could be authorised to
 * spend more while every "budget vs actual" figure went on measuring it against
 * the pre-addition budget. Authorised money that no report can see is the same
 * failure as unauthorised money that every report shows.
 */
class BudgetRevisionProjectionTest extends TestCase
{
    use RefreshDatabase;

    private BudgetAdditionService $service;
    private BudgetRevisionProjector $projector;
    private EnquiryTask $task;
    private TaskBudgetData $budgetData;
    private int $enquiryId;
    private User $approver;
    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BudgetAdditionService::class);
        $this->projector = app(BudgetRevisionProjector::class);

        $this->approver = User::factory()->create();
        $this->requester = User::factory()->create();

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid() . '@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Activation', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-REV-001', 'job_number' => 'WNG-REV-001',
            'created_by' => $this->approver->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->task = EnquiryTask::create([
            'project_enquiry_id' => $this->enquiryId,
            'title' => 'Budget', 'type' => 'budget', 'created_by' => $this->approver->id,
        ]);

        $this->budgetData = TaskBudgetData::create([
            'enquiry_task_id' => $this->task->id,
            'project_info' => [], 'materials_data' => [], 'budget_summary' => ['grandTotal' => 1],
        ]);
    }

    private function addition(string $status = 'pending_approval'): BudgetAddition
    {
        return BudgetAddition::create([
            'task_budget_data_id' => $this->budgetData->id,
            'title' => 'Extra LED screens',
            'description' => 'Two more screens needed on site',
            'budget_type' => 'supplementary',
            'status' => $status,
            'total_amount' => 62000,
            'created_by' => $this->requester->id,
            'materials' => [
                ['description' => '3m LED panel', 'quantity' => 2, 'unit_price' => 20000, 'total_price' => 40000],
            ],
            'labour' => [
                ['description' => 'Rigging crew', 'quantity' => 2, 'unit_price' => 6000, 'total_price' => 12000],
            ],
            'expenses' => [
                ['description' => 'Site permit top-up', 'quantity' => 1, 'unit_price' => 10000, 'total_price' => 10000],
            ],
        ]);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, CostLine> */
    private function revisionLines()
    {
        return CostLine::where('source_type', 'BudgetAddition')
            ->where('nature', CostLine::NATURE_PLANNED)
            ->where('status', CostLine::STATUS_VERIFIED)
            ->get();
    }

    public function test_an_approved_addition_becomes_planned_cost_lines(): void
    {
        $addition = $this->addition();
        $this->projector->project($addition->fresh()->forceFill(['status' => 'approved']));

        $lines = $this->revisionLines();

        $this->assertCount(3, $lines);
        $this->assertSame('62000.00', number_format((float) $lines->sum('net_amount'), 2, '.', ''));

        // Every line is attributable to the revision that authorised it and to
        // the project it belongs to.
        foreach ($lines as $line) {
            $this->assertSame((int) $addition->id, (int) $line->source_id);
            $this->assertSame($this->enquiryId, $line->project_enquiry_id);
            $this->assertTrue((bool) $line->details['is_addition']);
        }

        $this->assertEqualsCanonicalizing(
            ['materials', 'labour', 'expenses'],
            $lines->map(fn (CostLine $line) => $line->details['budget_category'])->all(),
        );
    }

    public function test_the_lines_name_the_revision_that_authorised_them(): void
    {
        $addition = $this->addition();
        $this->projector->project($addition->fresh()->forceFill(['status' => 'approved']));

        // Someone charging a cost to this line has to be able to tell it is
        // authorised extra money rather than the original budget.
        $this->assertStringContainsString(
            'Extra LED screens',
            (string) $this->revisionLines()->first()->description,
        );
    }

    public function test_quantity_and_rate_survive_the_projection(): void
    {
        $addition = $this->addition();
        $this->projector->project($addition->fresh()->forceFill(['status' => 'approved']));

        $panel = $this->revisionLines()
            ->first(fn (CostLine $line) => $line->details['budget_category'] === 'materials');

        $this->assertSame('2.000', (string) $panel->quantity);
        $this->assertSame('20000.0000', (string) $panel->unit_rate);
        $this->assertSame('40000.00', (string) $panel->net_amount);
    }

    public function test_nothing_is_projected_until_it_is_approved(): void
    {
        // Rejection creates nothing, and neither does a pending request — the
        // cost account only ever reflects authorised money.
        foreach (['pending_approval', 'rejected', 'draft'] as $status) {
            $this->projector->project($this->addition($status));
        }

        $this->assertCount(0, $this->revisionLines());
    }

    public function test_projecting_twice_converges_instead_of_doubling(): void
    {
        $addition = $this->addition()->fresh()->forceFill(['status' => 'approved']);

        $this->projector->project($addition);
        $this->projector->project($addition);
        $this->projector->project($addition);

        $lines = $this->revisionLines();

        $this->assertCount(3, $lines);
        $this->assertSame('62000.00', number_format((float) $lines->sum('net_amount'), 2, '.', ''));
    }

    public function test_a_revised_line_supersedes_rather_than_accumulates(): void
    {
        $addition = $this->addition()->fresh()->forceFill(['status' => 'approved']);
        $this->projector->project($addition);

        // The revision is corrected upward before anyone spends against it.
        $addition->forceFill([
            'materials' => [
                ['description' => '3m LED panel', 'quantity' => 3, 'unit_price' => 20000, 'total_price' => 60000],
            ],
        ])->save();

        $this->projector->project($addition->fresh()->forceFill(['status' => 'approved']));

        $live = $this->revisionLines();

        $this->assertCount(3, $live, 'the superseded line must not still count');
        $this->assertSame('82000.00', number_format((float) $live->sum('net_amount'), 2, '.', ''));

        // The old fact is retained, reversed, rather than deleted.
        $this->assertSame(1, CostLine::where('source_type', 'BudgetAddition')
            ->where('status', CostLine::STATUS_REVERSED)->count());
    }

    public function test_approving_through_the_service_announces_it_for_projection(): void
    {
        Event::fake([BudgetAdditionApproved::class]);

        $addition = $this->addition();

        $this->actingAs($this->approver);
        $this->service->approve($this->task->id, (string) $addition->id, 'Client signed off');

        Event::assertDispatched(
            BudgetAdditionApproved::class,
            fn (BudgetAdditionApproved $event) => (int) $event->addition->id === (int) $addition->id,
        );
    }

    public function test_rejecting_through_the_service_announces_nothing(): void
    {
        Event::fake([BudgetAdditionApproved::class]);

        $addition = $this->addition();

        $this->actingAs($this->approver);
        $this->service->reject($this->task->id, (string) $addition->id, 'Out of scope');

        Event::assertNotDispatched(BudgetAdditionApproved::class);
    }

    public function test_the_listener_projects_what_the_event_carries(): void
    {
        $addition = $this->addition();

        $this->actingAs($this->approver);
        $approved = $this->service->approve($this->task->id, (string) $addition->id);

        // Exercised directly rather than through the queue, so the assertion is
        // about the wiring and not about a worker being up.
        app(ProjectApprovedBudgetAddition::class)->handle(new BudgetAdditionApproved($approved));

        $this->assertCount(3, $this->revisionLines());
    }
}
