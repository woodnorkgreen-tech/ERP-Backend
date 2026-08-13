<?php

namespace Tests\Feature\CostCollector;

use App\Models\BudgetAddition;
use App\Models\GovernanceAuditLog;
use App\Models\TaskBudgetData;
use App\Models\User;
use App\Modules\Projects\Models\EnquiryTask;
use App\Services\BudgetAdditionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Audit X4 — approved budget additions feed quote pricing, so this is the
 * mechanism by which a project's cost grows after its budget is set. It left no
 * trace at all: status, approved_by and approved_at were mutated directly, with
 * no audit row, unlike quote invalidation in the same subsystem.
 */
class BudgetAdditionAuditTest extends TestCase
{
    use RefreshDatabase;

    private BudgetAdditionService $service;
    private EnquiryTask $task;
    private TaskBudgetData $budgetData;
    private User $approver;
    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BudgetAdditionService::class);
        $this->approver = User::factory()->create();
        // Separation of duties: whoever asks for more budget may not authorise
        // it. These tests are about the audit trail, so they need two people.
        $this->requester = User::factory()->create();

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid() . '@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Activation', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-ADD-001', 'job_number' => 'WNG-ADD-001',
            'created_by' => $this->approver->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->task = EnquiryTask::create([
            'project_enquiry_id' => $enquiryId,
            'title' => 'Budget', 'type' => 'budget', 'created_by' => $this->approver->id,
        ]);

        $this->budgetData = TaskBudgetData::create([
            'enquiry_task_id' => $this->task->id,
            'project_info' => [], 'materials_data' => [], 'budget_summary' => ['grandTotal' => 1],
        ]);
    }

    private function addition(): BudgetAddition
    {
        return BudgetAddition::create([
            'task_budget_data_id' => $this->budgetData->id,
            'title' => 'Extra LED screens',
            'description' => 'Client asked for two more screens',
            'budget_type' => 'supplementary',
            'total_amount' => 45000,
            'status' => 'pending_approval',
            'created_by' => $this->requester->id,
        ]);
    }

    public function test_approving_an_addition_leaves_an_audit_trail(): void
    {
        $addition = $this->addition();

        $this->actingAs($this->approver);
        $this->service->approve($this->task->id, (string) $addition->id, 'Client signed off');

        $log = GovernanceAuditLog::where('model_type', BudgetAddition::class)
            ->where('model_id', $addition->id)
            ->firstOrFail();

        $this->assertSame('Budget Addition', $log->gate_type);
        $this->assertSame('authorized', $log->action_status);
        $this->assertSame($this->approver->id, $log->user_id);
        $this->assertSame($this->task->project_enquiry_id, $log->project_enquiry_id);
        $this->assertStringContainsString('Extra LED screens', $log->message);
        $this->assertStringContainsString('45,000.00', $log->message);
        $this->assertSame('Client signed off', $log->context['notes']);
    }

    public function test_rejecting_an_addition_is_recorded_as_rejected(): void
    {
        $addition = $this->addition();

        $this->actingAs($this->approver);
        $this->service->reject($this->task->id, (string) $addition->id, 'Out of scope');

        $log = GovernanceAuditLog::where('model_id', $addition->id)->firstOrFail();

        $this->assertSame('rejected', $log->action_status);
        $this->assertSame('Out of scope', $log->context['notes']);
    }

    public function test_the_decision_survives_an_audit_write_failure(): void
    {
        $addition = $this->addition();

        // An audit row that cannot be written must not roll back a decision a
        // human has already made.
        DB::statement('DROP TABLE governance_audit_logs');

        $this->actingAs($this->approver);
        $result = $this->service->approve($this->task->id, (string) $addition->id);

        $this->assertSame('approved', $result->status);
    }
}
