<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\GovernanceAuditLog;
use App\Models\ProjectEnquiry;
use App\Models\TaskBudgetData;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\BudgetProjector;
use App\Modules\Projects\Models\EnquiryTask;
use App\Services\Governance\BudgetRevisionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moving a project budget after money is already committed against it.
 *
 * There were two ways out of an over-budget block and only one of them left a
 * trace. Authorising the overrun costs a permission, a written reason and an
 * audit row. Raising the budget until the block went away cost nothing — so the
 * accountable door was the expensive one and the quiet door was free.
 *
 * This closes that without gating the save, because it cannot gate the save: the
 * budget screen autosaves every two seconds while somebody is typing, and
 * refusing a write mid-keystroke would lose their work. It observes the
 * projection instead and records what moved.
 */
class BudgetRevisionRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProjectEnquiry $enquiry;

    private EnquiryTask $budgetTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);

        $client = Client::create([
            'full_name' => 'Revision Client', 'email' => 'revision@example.test', 'phone' => '0700000002',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'referral', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
        ]);

        $this->enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'client_id' => $client->id,
            'title' => 'Revisable job',
            'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-REV-001',
            'job_number' => 'WNG-09-2026-050',
            'status' => EnquiryConstants::STATUS_PLANNING,
            'created_by' => $this->user->id,
        ]);

        $this->budgetTask = EnquiryTask::create([
            'project_enquiry_id' => $this->enquiry->id,
            'type' => 'budget',
            'title' => 'Budget',
            'status' => 'in_progress',
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * The budget as the cost ledger holds it.
     *
     * Written directly rather than through BudgetProjector: this suite is about
     * what happens when the total moves, not about how the JSON is shredded into
     * lines, and a planned line is the thing the expenditure gate reads.
     */
    private function plannedLine(string $amount, string $ref = 'line-1'): CostLine
    {
        $line = CostLine::create([
            'ref' => 'TMP-' . uniqid(),
            'project_enquiry_id' => $this->enquiry->id,
            'job_number' => $this->enquiry->job_number,
            'nature' => CostLine::NATURE_PLANNED,
            'status' => CostLine::STATUS_VERIFIED,
            'currency' => 'KES', 'fx_rate' => 1,
            'amount' => $amount, 'net_amount' => $amount, 'base_net_amount' => $amount,
            'source_type' => 'BudgetLine', 'source_ref' => $ref,
            'verified_at' => now(), 'incurred_at' => now(),
        ]);

        return $line->forceFill(['ref' => 'CL-' . str_pad((string) $line->id, 7, '0', STR_PAD_LEFT)]);
    }

    private function exposure(string $amount): CostLine
    {
        $line = CostLine::create([
            'ref' => 'TMP-' . uniqid(),
            'project_enquiry_id' => $this->enquiry->id,
            'job_number' => $this->enquiry->job_number,
            'nature' => CostLine::NATURE_COMMITTED,
            'status' => CostLine::STATUS_VERIFIED,
            'currency' => 'KES', 'fx_rate' => 1,
            'amount' => $amount, 'net_amount' => $amount, 'base_net_amount' => $amount,
            'verified_at' => now(), 'incurred_at' => now(),
        ]);

        return $line->forceFill(['ref' => 'CL-' . str_pad((string) $line->id, 7, '0', STR_PAD_LEFT)]);
    }

    private function recorder(): BudgetRevisionRecorder
    {
        return app(BudgetRevisionRecorder::class);
    }

    private function revisions()
    {
        return GovernanceAuditLog::where('gate_type', BudgetRevisionRecorder::EVENT);
    }

    /**
     * The case this exists for: the job is over budget, the budget goes up, the
     * block disappears. Nobody approved anything and nothing used to be written.
     */
    public function test_raising_the_budget_over_committed_spend_is_recorded(): void
    {
        $this->exposure('11999.99');

        $this->recorder()->record($this->enquiry, '1650.00', '12000.00', $this->user->id, $this->budgetTask->id);

        $row = $this->revisions()->firstOrFail();

        $this->assertSame($this->user->id, $row->user_id);
        $this->assertSame('1650.00', $row->context['from']);
        $this->assertSame('12000.00', $row->context['to']);
        $this->assertSame('10350.00', $row->context['delta']);
        $this->assertTrue($row->context['cleared_an_overrun']);
        $this->assertStringContainsString('cleared an outstanding overrun', $row->message);
    }

    /**
     * A budget still being written is not being revised.
     *
     * This is also what keeps the two-second autosave from writing a row every
     * couple of seconds while somebody prices a job.
     */
    public function test_a_budget_with_no_spend_against_it_records_nothing(): void
    {
        $this->assertNull(
            $this->recorder()->record($this->enquiry, '1650.00', '12000.00', $this->user->id),
        );
        $this->assertSame(0, $this->revisions()->count());
    }

    /** An unchanged total is not a revision, however many times it is saved. */
    public function test_an_unchanged_total_records_nothing(): void
    {
        $this->exposure('500.00');

        $this->assertNull($this->recorder()->record($this->enquiry, '1650.00', '1650.00', $this->user->id));
        $this->assertSame(0, $this->revisions()->count());
    }

    /**
     * A revision that does not clear an overrun is still recorded — the budget
     * moved while money was riding on it — but it is not flagged as clearing one.
     */
    public function test_a_revision_that_clears_nothing_is_recorded_without_the_flag(): void
    {
        $this->exposure('500.00');

        $this->recorder()->record($this->enquiry, '1650.00', '2000.00', $this->user->id);

        $this->assertFalse($this->revisions()->firstOrFail()->context['cleared_an_overrun']);
    }

    /**
     * Autosave means one editing session produces many writes. They coalesce into
     * one revision that keeps the figure the session started from, because "1,650
     * to 12,000" is the honest record and "11,900 to 12,000" is an artifact of
     * when the debounce happened to fire.
     */
    public function test_successive_saves_by_one_person_read_as_one_revision(): void
    {
        $this->exposure('11999.99');

        $this->recorder()->record($this->enquiry, '1650.00', '5000.00', $this->user->id);
        $this->recorder()->record($this->enquiry, '5000.00', '9000.00', $this->user->id);
        $this->recorder()->record($this->enquiry, '9000.00', '12000.00', $this->user->id);

        $this->assertSame(1, $this->revisions()->count());

        $row = $this->revisions()->firstOrFail();
        $this->assertSame('1650.00', $row->context['from']);
        $this->assertSame('12000.00', $row->context['to']);
        $this->assertTrue($row->context['cleared_an_overrun']);
    }

    /** A different person moving the same budget is a separate decision. */
    public function test_another_person_gets_their_own_revision(): void
    {
        $this->exposure('11999.99');
        $other = User::factory()->create(['is_active' => true]);

        $this->recorder()->record($this->enquiry, '1650.00', '5000.00', $this->user->id);
        $this->recorder()->record($this->enquiry, '5000.00', '12000.00', $other->id);

        $this->assertSame(2, $this->revisions()->count());
    }

    /**
     * End to end through the projector, which is the only place the total is
     * known to have moved — planned lines are superseded one at a time, so no
     * single ledger write can see it.
     */
    public function test_the_projector_records_the_movement_it_causes(): void
    {
        $this->exposure('11999.99');
        $this->plannedLine('1650.00');

        $budget = TaskBudgetData::create([
            'enquiry_task_id' => $this->budgetTask->id,
            'project_info' => [],
            'materials_data' => [],
            'labour_data' => [],
            'expenses_data' => [[
                'id' => 'exp-1', 'description' => 'Revised scope', 'amount' => 12000,
            ]],
            'logistics_data' => [],
            'budget_summary' => ['grandTotal' => 12000],
            'status' => 'draft',
        ]);

        $result = app(BudgetProjector::class)->project($budget->fresh('task'), $this->user->id);

        $this->assertTrue($result['revised']);

        $row = $this->revisions()->firstOrFail();
        $this->assertSame('1650.00', $row->context['from']);
        $this->assertSame($this->budgetTask->id, $row->context['budget_task_id']);
        $this->assertSame($this->user->id, $row->user_id);
    }

    /** A machine-driven sync has no person behind it, and says so. */
    public function test_a_machine_revision_records_a_null_actor(): void
    {
        $this->exposure('11999.99');

        $this->recorder()->record($this->enquiry, '1650.00', '12000.00', null);

        $this->assertNull($this->revisions()->firstOrFail()->user_id);
    }
}
