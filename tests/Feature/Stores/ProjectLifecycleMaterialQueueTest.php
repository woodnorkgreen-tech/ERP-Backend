<?php

namespace Tests\Feature\Stores;

use App\Modules\HR\Models\Department;
use App\Models\ElementMaterial;
use App\Models\GovernanceAuditLog;
use App\Models\MaterialRequirementRetirement;
use App\Models\User;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\Supplier;
use App\Modules\ProcurementStores\Services\ProjectMaterialDemand;
use App\Modules\ProcurementStores\Services\ProjectMaterialWorkflowEligibility as Eligibility;
use App\Modules\ProcurementStores\Services\ProjectMaterialWorkflowSummary;
use App\Modules\ProcurementStores\Services\ReplenishmentPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Report 78C — material work follows the project lifecycle.
 *
 * Completed, closed, cancelled and fully-retired jobs leave every operational
 * material queue, count and buying suggestion; overdue active jobs stay in;
 * history stays readable; procurement obligations stay visible; and no
 * lifecycle decision ever moves stock or money. The numbers in the brief's test
 * list are noted on each test.
 */
class ProjectLifecycleMaterialQueueTest extends TestCase
{
    use \Tests\Concerns\GrantsMatrixPermissions;
    use RefreshDatabase;

    private User $stores;
    private User $manager;
    private User $projectManager;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        // A project issue posts a project cost (see ProjectMaterialIssueTest).
        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(ExpenseCodeSeeder::class);

        $this->grantMatrixPermissions('Stores', 'Manager', 'Project Manager');
        $this->stores = $this->userWithRole('Stores');
        $this->manager = $this->userWithRole('Manager');
        $this->projectManager = $this->userWithRole('Project Manager');
        Sanctum::actingAs($this->stores);

        $this->clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /* ── fixtures ──────────────────────────────────────────────────────── */

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * A job with a signed-off materials list. The enquiry status is the
     * lifecycle authority; projects.status is set to a stale 'planning' on
     * purpose, as it is on 356 dev rows, to prove nothing reads it.
     *
     * @return array{enquiry: int, project: int, element: int, code: string}
     */
    private function job(string $status, ?string $due = null, bool $withProject = true): array
    {
        $code = 'WNG-'.strtoupper(Str::random(6));
        $enquiry = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->subMonths(3)->toDateString(), 'client_id' => $this->clientId,
            'title' => "Job {$code}", 'contact_person' => 'Contact', 'status' => $status,
            'expected_delivery_date' => $due,
            'enquiry_number' => 'ENQ-'.$code, 'job_number' => $code,
            'created_by' => $this->stores->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $project = $withProject ? DB::table('projects')->insertGetId([
            'enquiry_id' => $enquiry, 'project_id' => $code,
            'status' => 'planning', 'created_at' => now(), 'updated_at' => now(),
        ]) : 0;

        $task = DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $enquiry, 'type' => 'materials', 'title' => 'Materials',
            'status' => 'completed', 'created_by' => $this->stores->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $data = DB::table('task_materials_data')->insertGetId([
            'enquiry_task_id' => $task,
            'project_info' => json_encode(['projectId' => $code, 'approval_status' => ['all_approved' => true]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $element = \App\Models\ProjectElement::create([
            'persistent_id' => (string) Str::uuid(), 'task_materials_data_id' => $data,
            'element_type' => 'stand', 'name' => 'BOOTH1', 'category' => 'production', 'is_included' => true,
        ])->id;

        return ['enquiry' => $enquiry, 'project' => $project, 'element' => $element, 'code' => $code];
    }

    private function material(string $name, float $onHand = 100): LibraryMaterial
    {
        $workstation = DB::table('workstations')->insertGetId([
            'name' => 'Main Workshop', 'code' => 'WS-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $material = LibraryMaterial::create([
            'workstation_id' => $workstation, 'material_name' => $name, 'material_code' => 'MAT-'.uniqid(),
            'category' => 'Materials', 'unit_of_measure' => 'sheet', 'unit_cost' => 1500,
            'item_status' => 'Active', 'is_active' => true,
        ]);
        Stock::create(['material_id' => $material->id, 'quantity_on_hand' => $onHand, 'quantity_reserved' => 0]);

        return $material;
    }

    private function specify(array $job, ?LibraryMaterial $material, float $quantity, string $description = 'Line'): ElementMaterial
    {
        return ElementMaterial::create([
            'project_element_id' => $job['element'], 'library_material_id' => $material?->id,
            'description' => $material?->material_name ?? $description, 'unit_of_measurement' => 'sheet',
            'quantity' => $quantity, 'is_included' => true,
        ]);
    }

    private function issue(array $job, LibraryMaterial $material, ElementMaterial $line, float $quantity, ?string $override = null)
    {
        return $this->postJson('/api/procurement-stores/movements', ['type' => 'issue', 'lines' => [array_filter([
            'material_id' => $material->id, 'project_material_id' => $line->id, 'project_id' => $job['project'],
            'quantity' => $quantity, 'recipient_name' => 'Otieno', 'reference_no' => $job['code'],
            'notes' => 'Approved project material', 'lifecycle_override_reason' => $override,
        ], fn ($value) => $value !== null)]]);
    }

    private function demand(): ProjectMaterialDemand
    {
        return app(ProjectMaterialDemand::class);
    }

    private function summary(): ProjectMaterialWorkflowSummary
    {
        return app(ProjectMaterialWorkflowSummary::class);
    }

    private function pendingProjects(string $capability = Eligibility::CAP_ISSUE): array
    {
        return $this->demand()->pendingLines(null, $capability)->pluck('project_id')->unique()->sort()->values()->all();
    }

    /** @return array<string, int> */
    private function ledgerCounts(): array
    {
        return [
            'inventory_logs' => DB::table('inventory_logs')->count(),
            'journal_entries' => DB::table('journal_entries')->count(),
            'cost_lines' => DB::table('cost_lines')->count(),
            'requisitions' => DB::table('requisitions')->count(),
            'purchase_orders' => DB::table('purchase_orders')->count(),
            'stocks_on_hand' => (int) round((float) DB::table('stocks')->sum('quantity_on_hand') * 1000),
        ];
    }

    /* ── 1–5: who is in the active queue ───────────────────────────────── */

    /** Tests 1, 2, 3, 5 — and projects.status (stale 'planning') is never read. */
    public function test_only_active_jobs_including_overdue_ones_are_pending_to_issue(): void
    {
        $mdf = $this->material('MDF 18mm');
        $active = $this->job('in_progress');
        $overdue = $this->job('planning', now()->subDays(120)->toDateString());
        $completed = $this->job('completed');
        $closed = $this->job('closed');
        $cancelled = $this->job('cancelled');
        foreach ([$active, $overdue, $completed, $closed, $cancelled] as $job) {
            $this->specify($job, $mdf, 10);
        }

        $this->assertSame(collect([$active['project'], $overdue['project']])->sort()->values()->all(), $this->pendingProjects());

        $overdueRow = $this->demand()->pendingLines(null, Eligibility::CAP_ISSUE)->firstWhere('project_id', $overdue['project']);
        $this->assertTrue($overdueRow['overdue'], 'An overdue active job stays in the queue, flagged.');
        $this->assertSame(20.0, $this->demand()->pendingByMaterial()[$mdf->id], 'Procurement demand counts the same two jobs.');

        $described = app(Eligibility::class)->describeMany([$active['enquiry'], $overdue['enquiry'], $completed['enquiry'], $closed['enquiry'], $cancelled['enquiry']]);
        $this->assertSame(Eligibility::QUEUE_ACTIVE, $described[$overdue['enquiry']]['queue']);
        $this->assertSame(120, $described[$overdue['enquiry']]['days_overdue']);
        $this->assertSame(Eligibility::QUEUE_HISTORICAL, $described[$completed['enquiry']]['queue']);
        $this->assertSame(Eligibility::QUEUE_HISTORICAL, $described[$cancelled['enquiry']]['queue']);
        $this->assertTrue($described[$closed['enquiry']]['read_only']);
    }

    /** Test 4 — retiring a whole job takes it out; a later line on it is live again. */
    public function test_a_job_fulfilled_outside_the_erp_leaves_the_queue_but_new_work_on_it_stays(): void
    {
        $mdf = $this->material('MDF 18mm');
        $job = $this->job('planning');
        $line = $this->specify($job, $mdf, 10);
        $unresolved = $this->specify($job, null, 4, 'Brass hinges');

        Sanctum::actingAs($this->projectManager);
        $this->postJson("/api/procurement-stores/projects/{$job['project']}/legacy-retirements", [
            'reason' => 'Issued by hand in May before Stores used the ERP',
        ])->assertCreated();

        $this->assertSame([], $this->pendingProjects());
        $this->assertSame(0, $this->summary()->queue('pending_resolution')->count(), 'The retired unresolved line leaves the resolution queue too.');
        $described = app(Eligibility::class)->describeMany([$job['enquiry']])[$job['enquiry']];
        $this->assertTrue($described['legacy']);
        $this->assertSame(Eligibility::QUEUE_HISTORICAL, $described['queue']);
        $this->assertSame(2, MaterialRequirementRetirement::count(), 'Both lines are kept, recorded as retired — nothing deleted.');
        $this->assertNotNull(ElementMaterial::find($line->id));
        $this->assertNotNull(ElementMaterial::find($unresolved->id));

        // New work on the same job is live; the job returns to active work.
        $this->specify($job, $mdf, 3);
        $pending = $this->demand()->pendingLines(null, Eligibility::CAP_ISSUE);
        $this->assertSame(3.0, (float) $pending->sum('pending'));
        $this->assertFalse(app(Eligibility::class)->describeMany([$job['enquiry']])[$job['enquiry']]['legacy']);

        // A quantity increase on a retired line shows only the increase.
        $line->update(['quantity' => 12]);
        $this->assertSame(5.0, (float) $this->demand()->pendingLines(null, Eligibility::CAP_ISSUE)->sum('pending'));
    }

    public function test_retirement_needs_its_permission_a_reason_and_a_live_job(): void
    {
        $mdf = $this->material('MDF 18mm');
        $job = $this->job('planning');
        $this->specify($job, $mdf, 10);
        $completed = $this->job('completed');
        $this->specify($completed, $mdf, 10);

        // D-1: Stores cannot make demand disappear.
        $this->postJson("/api/procurement-stores/projects/{$job['project']}/legacy-retirements", ['reason' => 'Issued by hand long ago'])->assertForbidden();

        Sanctum::actingAs($this->projectManager);
        $this->postJson("/api/procurement-stores/projects/{$job['project']}/legacy-retirements", ['reason' => 'old'])->assertStatus(422);
        $this->postJson("/api/procurement-stores/projects/{$completed['project']}/legacy-retirements", ['reason' => 'Issued by hand long ago'])
            ->assertStatus(422)->assertJsonValidationErrors('project_id');
        $this->assertSame(0, MaterialRequirementRetirement::count());
    }

    /** Test 6 — on hold: out of issue and buying, still resolvable; release brings it back. */
    public function test_an_on_hold_job_pauses_issue_and_buying_until_released(): void
    {
        $mdf = $this->material('MDF 18mm');
        $job = $this->job('in_progress');
        $line = $this->specify($job, $mdf, 10);
        $this->specify($job, null, 2, 'Unmatched paint');

        Sanctum::actingAs($this->projectManager);
        $this->postJson("/api/projects/enquiries/{$job['enquiry']}/hold", ['reason' => 'Client paused the event'])->assertCreated();

        $this->assertSame([], $this->pendingProjects());
        $this->assertSame([], $this->demand()->pendingByMaterial());
        $this->assertSame(1, $this->summary()->queue('pending_resolution')->count(), 'Planning work (resolution) continues on hold.');

        Sanctum::actingAs($this->stores);
        $this->issue($job, $mdf, $line, 2)->assertStatus(422);

        Sanctum::actingAs($this->projectManager);
        $this->postJson("/api/projects/enquiries/{$job['enquiry']}/hold/release", ['reason' => 'Client confirmed the new date'])->assertOk();
        $this->assertSame([$job['project']], $this->pendingProjects());
        $this->assertSame(2, GovernanceAuditLog::whereIn('gate_type', ['project_hold_placed', 'project_hold_released'])->count());
    }

    /** D-3 — pre-start jobs create demand but cannot be issued to. */
    public function test_a_job_waiting_for_its_deposit_creates_demand_but_is_not_issued_to(): void
    {
        $mdf = $this->material('MDF 18mm');
        $job = $this->job('awaiting_deposit');
        $line = $this->specify($job, $mdf, 6);

        $this->assertSame(6.0, $this->demand()->pendingByMaterial()[$mdf->id] ?? 0.0);
        $this->assertSame([], $this->pendingProjects());
        $this->issue($job, $mdf, $line, 1)->assertStatus(422);
    }

    /* ── 7, 13: reactivation ───────────────────────────────────────────── */

    /** Test 7 + 13 — reopening brings demand back once, and needs a reconciliation note. */
    public function test_reactivating_a_completed_job_restores_its_demand_exactly_once(): void
    {
        $mdf = $this->material('MDF 18mm', onHand: 0);
        $job = $this->job('completed');
        $this->specify($job, $mdf, 10);
        $before = $this->ledgerCounts();

        $this->assertSame([], $this->pendingProjects());

        // Stores cannot reopen a job (D-6).
        $this->postJson("/api/projects/enquiries/{$job['enquiry']}/reactivate", ['reason' => 'Client ordered phase two'])->assertForbidden();

        Sanctum::actingAs($this->projectManager);
        $this->postJson("/api/projects/enquiries/{$job['enquiry']}/reactivate", ['reason' => 'Client ordered phase two'])
            ->assertStatus(422)->assertJsonValidationErrors('reconciliation_note');
        $this->postJson("/api/projects/enquiries/{$job['enquiry']}/reactivate", [
            'reason' => 'Client ordered phase two', 'reconciliation_note' => 'Nothing was issued by hand while closed',
        ])->assertOk()->assertJsonPath('reappearing_demand.lines', 1);

        $this->assertSame('planning', DB::table('project_enquiries')->where('id', $job['enquiry'])->value('status'));
        $this->assertSame([$job['project']], $this->pendingProjects());
        $this->assertSame(10.0, $this->demand()->pendingByMaterial()[$mdf->id]);
        $this->assertSame($before, $this->ledgerCounts(), 'Reactivation creates no stock, cost, requisition or order.');

        // No duplicate requests: once a requisition covers the shortfall, the planner stops suggesting it.
        $suggestion = app(ReplenishmentPlanner::class)->suggestions()->firstWhere('material_id', $mdf->id);
        $this->assertSame(10.0, (float) $suggestion['suggested_quantity']);
        Sanctum::actingAs($this->stores);
        $department = Department::create(['name' => 'Stores', 'code' => 'STO']);
        $this->postJson('/api/procurement-stores/replenishment-suggestions/draft-requisition', [
            'department_id' => $department->id, 'items' => [['material_id' => $mdf->id, 'quantity' => 10]],
        ])->assertCreated();
        $this->assertNull(app(ReplenishmentPlanner::class)->suggestions()->firstWhere('material_id', $mdf->id));
    }

    /* ── 8, 9: obligations stay visible ────────────────────────────────── */

    /** Tests 8, 9, 14 — cancelling reports open POs and bills and touches neither. */
    public function test_cancelling_a_job_reports_but_never_cancels_its_purchase_orders_or_bills(): void
    {
        $mdf = $this->material('MDF 18mm');
        $job = $this->job('in_progress');
        $this->specify($job, $mdf, 10);
        $supplier = Supplier::create([
            'supplier_name' => 'Supplier '.uniqid(), 'contact_person' => 'C', 'phone' => '0700000000',
            'email' => uniqid().'@t.local', 'address' => 'Nairobi', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $this->stores->id,
        ]);
        $requisition = Requisition::create([
            'requisition_number' => 'REQ-'.uniqid(), 'date' => now()->toDateString(), 'requested_by_type' => 'project',
            'project_id' => $job['project'], 'urgency' => 'normal', 'status' => 'approved', 'total_amount' => 15000,
            'user_id' => $this->stores->id,
        ]);
        $order = PurchaseOrder::create([
            'requisition_id' => $requisition->id, 'po_number' => 'PO-'.uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $supplier->id, 'due_date' => now()->addDays(7)->toDateString(), 'delivery_address' => 'Store',
            'description' => 'Job boards', 'total_amount' => 15000, 'status' => 'approved', 'user_id' => $this->stores->id,
        ]);
        $billId = DB::table('bills')->insertGetId([
            'bill_number' => 'BILL-'.uniqid(), 'supplier_id' => $supplier->id, 'project_id' => $job['project'],
            'project_enquiry_id' => $job['enquiry'], 'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 15000, 'paid_amount' => 0, 'balance' => 15000, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = $this->ledgerCounts();

        Sanctum::actingAs($this->projectManager);
        $this->putJson("/api/projects/enquiries/{$job['enquiry']}", ['status' => 'cancelled'])->assertStatus(422);
        $response = $this->postJson("/api/projects/enquiries/{$job['enquiry']}/cancel", ['reason' => 'Client withdrew the brief'])->assertOk();

        $response->assertJsonPath('open_commitments.summary.purchase_orders', 1)
            ->assertJsonPath('open_commitments.summary.unpaid_bills', 1);
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertSame('pending', Bill::find($billId)->status);
        $this->assertSame($before, $this->ledgerCounts());
        $this->assertSame([], $this->pendingProjects());

        // The order is still in Procurement's own view of open orders.
        $this->getJson("/api/projects/enquiries/{$job['enquiry']}/lifecycle")
            ->assertOk()->assertJsonPath('data.open_commitments.summary.purchase_orders', 1)
            ->assertJsonPath('data.lifecycle.state', 'cancelled');
    }

    /* ── 10, 11: history and reconciliation ────────────────────────────── */

    /** Test 10 — historical requirements are kept and listed, not counted as work. */
    public function test_historical_requirements_stay_readable_through_their_own_filter(): void
    {
        $mdf = $this->material('MDF 18mm');
        $completed = $this->job('completed');
        $this->specify($completed, $mdf, 10);
        $this->specify($completed, null, 3, 'Old unmatched line');

        $historical = $this->summary()->queue('historical_outstanding');
        $this->assertCount(2, $historical);
        $this->assertSame([$completed['project']], $historical->pluck('project_id')->unique()->values()->all());
        $this->assertSame(0, $this->summary()->queue('pending_to_issue')->count());
        $this->assertSame(0, $this->summary()->queue('pending_resolution')->count());
    }

    /** Test 11 — every tile is the length of the list it opens, through the API. */
    public function test_every_summary_count_equals_its_queue_list(): void
    {
        $mdf = $this->material('MDF 18mm');
        $active = $this->job('in_progress');
        $overdue = $this->job('planning', now()->subDays(30)->toDateString());
        $completed = $this->job('completed');
        $this->specify($active, $mdf, 10);
        $this->specify($active, null, 2, 'Unmatched A');
        $this->specify($overdue, $mdf, 5);
        $this->specify($completed, $mdf, 7);
        $this->specify($completed, null, 1, 'Unmatched C');

        $summary = $this->getJson('/api/procurement-stores/material-workflow-summary')->assertOk()->json('data');
        $count = fn (string $metric) => $this->getJson("/api/procurement-stores/material-workflow-queue?metric={$metric}")->assertOk()->json('meta.count');

        $this->assertSame(2, $summary['pending_to_issue']['lines']);
        $this->assertSame($count('pending_to_issue'), $summary['pending_to_issue']['lines']);
        $this->assertSame(1, $summary['pending_to_issue']['overdue_lines']);
        $this->assertSame($count('pending_resolution'), $summary['pending_resolution']['lines']);
        $this->assertSame(1, $summary['pending_resolution']['lines']);
        $this->assertSame($count('historical_outstanding'), $summary['historical']['outstanding_lines']);
        $this->assertSame(2, $summary['historical']['outstanding_lines']);
        $this->assertSame($count('unit_exceptions'), $summary['unit_exceptions']['lines']);

        // Job queue counts are the Desk tab counts: the same verdict on the same rows.
        $projects = collect($this->getJson('/api/projects')->assertOk()->json('data'));
        $this->assertSame($summary['queues']['active_work'], $projects->where('lifecycle.queue', 'active_work')->count());
        $this->assertSame($summary['queues']['overdue_active'], $projects->where('lifecycle.queue', 'active_work')->where('lifecycle.overdue', true)->count());
        $this->assertSame($summary['queues']['historical'], $projects->where('lifecycle.queue', 'historical')->count());
        $this->assertSame(2, $summary['queues']['active_work']);
    }

    /* ── 12: one rule for Stores and Procurement ───────────────────────── */

    /** Test 12 — picker, forecast and planner agree with the Stores queue. */
    public function test_procurement_demand_uses_the_stores_eligibility(): void
    {
        $mdf = $this->material('MDF 18mm', onHand: 0);
        $active = $this->job('planning');
        $completed = $this->job('completed');
        $this->specify($active, $mdf, 4);
        $this->specify($completed, $mdf, 50);

        $this->assertSame(4.0, $this->demand()->pendingByMaterial([$mdf->id])[$mdf->id]);

        $forecast = collect($this->getJson('/api/procurement-stores/material-demand-forecast')->assertOk()->json('data'));
        $this->assertSame(4.0, (float) $forecast->firstWhere('material_id', $mdf->id)['pending_demand']);

        $suggestion = app(ReplenishmentPlanner::class)->suggestions()->firstWhere('material_id', $mdf->id);
        $this->assertSame(4.0, (float) $suggestion['pending_demand']);
        $this->assertSame([$active['project']], collect($suggestion['demand_sources'])->pluck('project_id')->unique()->values()->all());
    }

    /** Test 12 — a print request escalated for buying follows its job's lifecycle too. */
    public function test_printing_requests_for_finished_jobs_raise_no_buying(): void
    {
        $roll = $this->material('Sticker roll', onHand: 0);
        $metre = \App\Modules\MaterialsLibrary\Models\UnitOfMeasure::firstOrCreate(
            ['code' => 'm'],
            ['name' => 'Metre', 'dimension' => 'length', 'decimal_places' => 3, 'allows_fraction' => true, 'is_active' => true],
        );
        $roll->update(['unit_of_measure' => 'm', 'base_uom_id' => $metre->id, 'issue_uom_id' => $metre->id]);
        $active = $this->job('in_progress');
        $completed = $this->job('completed');
        foreach ([[$active, 30], [$completed, 70]] as [$job, $metres]) {
            \App\Modules\Printing\Models\PrintMaterialRequest::create([
                'material_id' => $roll->id, 'requested_quantity_m' => $metres, 'project_enquiry_id' => $job['enquiry'],
                'status' => 'awaiting_purchase', 'purchase_requested_at' => now(), 'requested_by' => $this->stores->id,
            ]);
        }
        // Print work with no job is not a lifecycle question and still counts.
        \App\Modules\Printing\Models\PrintMaterialRequest::create([
            'material_id' => $roll->id, 'requested_quantity_m' => 5,
            'status' => 'awaiting_purchase', 'purchase_requested_at' => now(), 'requested_by' => $this->stores->id,
        ]);

        $suggestion = app(ReplenishmentPlanner::class)->suggestions()->firstWhere('material_id', $roll->id);
        $this->assertSame(35.0, (float) $suggestion['pending_demand']);
    }

    /* ── 14, 15: backend enforcement, and nothing moves ─────────────────── */

    /** Test 15 — a direct API issue to a finished job is refused; an authorised override is recorded. */
    public function test_issuing_to_a_finished_job_is_refused_unless_an_authorised_manager_records_why(): void
    {
        $mdf = $this->material('MDF 18mm', onHand: 50);
        $completed = $this->job('completed');
        $line = $this->specify($completed, $mdf, 10);
        $cancelled = $this->job('cancelled');
        $cancelledLine = $this->specify($cancelled, $mdf, 10);

        $this->issue($completed, $mdf, $line, 2)->assertStatus(422);
        $this->issue($completed, $mdf, $line, 2, 'Snag repair requested after handover')->assertStatus(422);

        Sanctum::actingAs($this->manager);
        $this->issue($completed, $mdf, $line, 2, 'short')->assertStatus(422);
        $this->issue($completed, $mdf, $line, 2, 'Snag repair requested after handover')->assertOk();
        $this->assertSame(1, InventoryLog::where('project_material_id', $line->id)->count());
        $this->assertSame(1, GovernanceAuditLog::where('gate_type', 'material_lifecycle_override')->count());

        // No override for a cancelled job.
        $this->issue($cancelled, $mdf, $cancelledLine, 1, 'Snag repair requested after handover')->assertStatus(422);
    }

    /** Test 15 — board requests and project requisitions obey the same rule. */
    public function test_board_requests_and_project_requisitions_are_refused_for_finished_jobs(): void
    {
        $board = $this->material('MDF board 18mm');
        $completed = $this->job('cancelled');
        $line = $this->specify($completed, $board, 2);

        $this->postJson('/api/procurement-stores/board-requests', [
            'job_ref' => $completed['code'], 'material_id' => $board->id, 'project_id' => $completed['project'],
            'project_material_id' => $line->id, 'qty' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('project_id');

        $this->postJson('/api/procurement-stores/requisitions', [
            'date' => now()->toDateString(), 'requested_by_type' => 'project', 'project_id' => $completed['project'],
            'urgency' => 'normal', 'items' => [['material_id' => $board->id, 'quantity' => 2, 'unit_price' => 1500, 'custom_description' => 'MDF']],
        ])->assertStatus(422)->assertJsonValidationErrors('project_id');
        $this->assertSame(0, Requisition::count());
    }

    /** Test 15 — the generic status edit can no longer reopen or cancel. */
    public function test_the_generic_status_update_cannot_bypass_the_governed_actions(): void
    {
        $job = $this->job('completed');
        Sanctum::actingAs($this->projectManager);

        $this->putJson("/api/projects/enquiries/{$job['enquiry']}", ['status' => 'planning'])->assertStatus(422);
        $this->putJson("/api/projects/enquiries/{$job['enquiry']}", ['status' => 'cancelled'])->assertStatus(422);
        $this->assertSame('completed', DB::table('project_enquiries')->where('id', $job['enquiry'])->value('status'));
    }

    /** Test 15 — Resolve Material on a finished job is refused through the shared policy. */
    public function test_resolving_a_line_on_a_finished_job_is_refused(): void
    {
        $mdf = $this->material('MDF 18mm');
        $job = $this->job('closed');
        $line = $this->specify($job, null, 3, 'Plywood');

        $this->postJson("/api/procurement-stores/project-materials/{$line->id}/resolve-catalogue", ['material_id' => $mdf->id])
            ->assertStatus(422);
        $this->assertNull($line->fresh()->library_material_id);
    }

    /** Test 14 — reading every queue and summary moves nothing. */
    public function test_lifecycle_filtering_never_moves_stock_or_money(): void
    {
        $mdf = $this->material('MDF 18mm');
        foreach (['in_progress', 'completed', 'cancelled', 'awaiting_deposit'] as $status) {
            $this->specify($this->job($status), $mdf, 5);
        }
        $before = $this->ledgerCounts();

        $this->getJson('/api/procurement-stores/material-workflow-summary')->assertOk();
        foreach (ProjectMaterialWorkflowSummary::METRICS as $metric) {
            $this->getJson("/api/procurement-stores/material-workflow-queue?metric={$metric}")->assertOk();
        }
        $this->getJson('/api/procurement-stores/material-demand-forecast')->assertOk();
        app(ReplenishmentPlanner::class)->suggestions();

        $this->assertSame($before, $this->ledgerCounts());
    }

    /** Phase 7 — the mirror command reports without writing unless asked. */
    public function test_the_status_mirror_command_is_a_dry_run_by_default(): void
    {
        $job = $this->job('completed');

        $this->artisan('projects:reconcile-status-mirror')->assertSuccessful();
        $this->assertSame('planning', DB::table('projects')->where('id', $job['project'])->value('status'));

        $this->artisan('projects:reconcile-status-mirror', ['--apply' => true])->assertSuccessful();
        $this->assertSame('completed', DB::table('projects')->where('id', $job['project'])->value('status'));
        $this->assertSame('completed', DB::table('project_enquiries')->where('id', $job['enquiry'])->value('status'));
    }
}
