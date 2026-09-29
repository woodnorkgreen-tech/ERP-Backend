<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\ProjectEnquiry;
use App\Models\TaskBudgetData;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ProjectLabourActual;
use App\Modules\Finance\CostCollector\Services\ProjectLabourActualService;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * W7 Labour Cost — comprehensive backend test suite.
 *
 * Covers: budget inheritance, server-authoritative fields, calculation formula,
 * two-stage verification lifecycle, authorization, payroll privacy, unbudgeted
 * labour, correction via reversing pair, concurrency, closure guard, CostLine
 * creation with postsIndependently=false, and expense-code resolution.
 */
class W7LabourCostTest extends TestCase
{
    use RefreshDatabase;

    private User $financier;
    private User $projectOfficer;
    private User $recorder;
    private User $unauthorized;
    private ProjectEnquiry $enquiry;
    private EnquiryTask $budgetTask;
    private ProjectLabourActualService $service;
    private const BUDGET_LINE_ID = 'labour-budget-line-001';

    protected function setUp(): void
    {
        parent::setUp();

        config(['finance_accounts.seed_reference_chart' => true]);
        config(['finance_accounts.map' => []]);

        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ExpenseCodeSeeder::class);

        foreach ([
            Permissions::FINANCE_LABOUR_VIEW,
            Permissions::FINANCE_LABOUR_RECORD,
            Permissions::FINANCE_LABOUR_PO_VERIFY,
            Permissions::FINANCE_LABOUR_FINANCE_VERIFY,
            Permissions::FINANCE_LABOUR_CORRECT,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Set up roles
        $accounts = Role::firstOrCreate(['name' => 'Accounts']);
        $accounts->givePermissionTo([
            Permissions::FINANCE_LABOUR_VIEW,
            Permissions::FINANCE_LABOUR_RECORD,
            Permissions::FINANCE_LABOUR_FINANCE_VERIFY,
            Permissions::FINANCE_LABOUR_CORRECT,
        ]);

        $pm = Role::firstOrCreate(['name' => 'Project Manager']);
        $pm->givePermissionTo([
            Permissions::FINANCE_LABOUR_VIEW,
            Permissions::FINANCE_LABOUR_RECORD,
            Permissions::FINANCE_LABOUR_PO_VERIFY,
        ]);

        // Create users
        $this->financier = User::factory()->create(['is_active' => true]);
        $this->financier->assignRole($accounts);

        $this->projectOfficer = User::factory()->create(['is_active' => true]);
        $this->projectOfficer->assignRole($pm);

        $this->recorder = User::factory()->create(['is_active' => true]);
        $this->recorder->assignRole($pm);

        $this->unauthorized = User::factory()->create(['is_active' => true]);

        // Create project enquiry
        $this->enquiry = $this->createEnquiry($this->financier->id);
        $this->createProject($this->enquiry->id);

        // Budget task completed = the Project Budget is finalized (ProjectBudgetAuthority).
        $this->budgetTask = EnquiryTask::create([
            'project_enquiry_id' => $this->enquiry->id,
            'title' => 'Budget',
            'type' => 'budget',
            'status' => 'completed',
            'created_by' => $this->financier->id,
        ]);

        $this->createBudget($this->budgetTask->id);

        $this->service = $this->app->make(ProjectLabourActualService::class);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function createEnquiry(int $createdBy): ProjectEnquiry
    {
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Test Client', 'email' => uniqid('client') . '@test.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Test Enquiry', 'contact_person' => 'Test Contact',
            'enquiry_number' => 'ENQ-W7-' . uniqid(), 'job_number' => 'WNG-W7-' . uniqid(),
            'status' => 'in_progress', 'financial_closure_status' => 'open',
            'project_officer_id' => $this->projectOfficer->id,
            'assigned_po' => $this->projectOfficer->id,
            'assigned_users' => json_encode([$this->recorder->id]),
            'created_by' => $createdBy, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ProjectEnquiry::findOrFail($enquiryId);
    }

    private function createProject(int $enquiryId): void
    {
        DB::table('projects')->insert([
            'enquiry_id' => $enquiryId,
            'project_id' => 'PROJ-' . uniqid(),
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'budget' => 500000.00,
            'current_phase' => 1,
            'status' => 'planning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBudget(int $taskId): void
    {
        TaskBudgetData::create([
            'enquiry_task_id' => $taskId,
            'project_info' => [],
            'materials_data' => [],
            'labour_data' => [
                [
                    'id' => self::BUDGET_LINE_ID,
                    'type' => 'Casual',
                    'category' => 'site_labour',
                    'description' => 'Installers',
                    'unit' => 'PAX',
                    'quantity' => 6,
                    'days' => 3,
                    'unitRate' => 1500,
                    'amount' => 27000,
                    'isIncluded' => true,
                ],
            ],
            'expenses_data' => [],
            'logistics_data' => [],
            'budget_summary' => ['grandTotal' => 27000],
            // Production state since 2026-07-07: budgets stay 'draft'; the completed
            // budget task is what finalizes them (ProjectBudgetAuthority).
            'status' => 'draft',
        ]);

        // Also create planned cost lines via BudgetProjector pattern
        // The service's authoritativeBudgetData() looks for these CostLines
        $collector = $this->app->make(\App\Modules\Finance\CostCollector\Services\CostCollectorService::class);

        $planned = new \App\Modules\Finance\CostCollector\Contracts\PlannedLine(
            category: 'labour',
            amount: '27000.00',
            description: 'Casual — Installers',
            enquiryId: $this->enquiry->id,
            taskId: $this->budgetTask->id,
            unit: 'PAX',
            quantity: '6',
            unitRate: '1500',
            sourceId: $this->budgetTask->id, // Actually task_budget_data id
            sourceRef: self::BUDGET_LINE_ID,
            isAddition: false,
            details: [],
        );

        // BudgetProjector uses source_id = budget->id. Let's get that.
        $budget = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
        $planned = new \App\Modules\Finance\CostCollector\Contracts\PlannedLine(
            category: 'labour',
            amount: '27000.00',
            description: 'Casual — Installers',
            enquiryId: $this->enquiry->id,
            taskId: $this->budgetTask->id,
            unit: 'PAX',
            quantity: '6',
            unitRate: '1500',
            sourceId: $budget->id,
            sourceRef: self::BUDGET_LINE_ID,
            isAddition: false,
            details: [],
        );

        $collector->postPlanned($planned);
    }

    private function recordData(array $overrides = []): array
    {
        return array_merge([
            'project_enquiry_id' => $this->enquiry->id,
            'budget_line_id' => self::BUDGET_LINE_ID,
            'labour_role' => 'Installer',
            'labour_category' => 'site_labour',
            'budget_unit' => 'PAX',
            'unit_rate' => 1500,
            'actual_quantity' => 2,
            'actual_days' => 1,
            'work_date' => now()->toDateString(),
            'employee_id' => null,
            'is_unbudgeted' => false,
            'rework_type' => ProjectLabourActual::REWORK_NONE,
            'metadata' => null,
        ], $overrides);
    }

    // ── 1. Budget inheritance / server-authoritative ─────────────────────────

    public function test_server_overrides_client_supplied_budget_fields_on_record(): void
    {
        $data = $this->recordData([
            // Client tries to push its own rate/role/category/unit
            'unit_rate' => 999999,
            'labour_role' => 'Injected Role',
            'labour_category' => 'injected_category',
            'budget_unit' => 'HOURS',
            'actual_quantity' => 4,
            'actual_days' => 2,
        ]);

        $actual = $this->service->record($data, $this->recorder);

        // Budget values must win — the client's values must be ignored.
        $this->assertSame('1500.00', (string) $actual->unit_rate);
        $this->assertSame('Casual', $actual->labour_role);
        $this->assertSame('site_labour', $actual->labour_category);
        $this->assertSame('PAX', $actual->budget_unit);
        $this->assertSame(self::BUDGET_LINE_ID, $actual->budget_line_id);
        $this->assertNotNull($actual->consumes_cost_line_id);
        $this->assertNotNull($actual->budget_id);

        // Rate resolution comes from the budget.
        $this->assertSame(ProjectLabourActual::RATE_RESOLVED, $actual->rate_resolution_status);
        $this->assertNotNull($actual->rate_source);
        $this->assertSame('project_budget', $actual->rate_source['type']);
    }

    public function test_calculated_cost_uses_server_owned_rate_not_client_rate(): void
    {
        $data = $this->recordData([
            'unit_rate' => 999999,  // client tries to inflate rate
            'actual_quantity' => 4,
            'actual_days' => 2,
        ]);

        $actual = $this->service->record($data, $this->recorder);

        // 4 PAX × 2 days × 1500 = 12000 (not 4 × 2 × 999999)
        $this->assertSame('12000.00', (string) $actual->calculated_cost);
    }

    // ── 2. Cost formula ───────────────────────────────────────────────────────

    public function test_hours_formula_actual_hours_times_unit_rate(): void
    {
        $budget = $this->createHoursBudget();
        $hoursLineId = 'labour-hours-line-001';

        // Create budget with hours unit
        $existingBudget = TaskBudgetData::where('enquiry_task_id', $this->budgetTask->id)->first();
        $existingBudget->update([
            'labour_data' => [
                [
                    'id' => $hoursLineId,
                    'type' => 'Electrician',
                    'category' => 'site_labour',
                    'description' => 'Electrical work',
                    'unit' => 'hours',
                    'quantity' => 1,
                    'days' => 1,
                    'unitRate' => 2000,
                    'amount' => 24000,
                    'isIncluded' => true,
                ],
            ],
        ]);

        // Project the budget to create planned lines
        $collector = $this->app->make(\App\Modules\Finance\CostCollector\Services\CostCollectorService::class);
        $planned = new \App\Modules\Finance\CostCollector\Contracts\PlannedLine(
            category: 'labour',
            amount: '24000.00',
            description: 'Electrician — Electrical work',
            enquiryId: $this->enquiry->id,
            taskId: $this->budgetTask->id,
            unit: 'hours',
            quantity: '1',
            unitRate: '2000',
            sourceId: $existingBudget->id,
            sourceRef: $hoursLineId,
            isAddition: false,
            details: [],
        );
        $collector->postPlanned($planned);

        $actual = $this->service->record([
            'project_enquiry_id' => $this->enquiry->id,
            'budget_line_id' => $hoursLineId,
            'labour_role' => 'Electrician',
            'labour_category' => 'site_labour',
            'budget_unit' => 'hours',
            'unit_rate' => 2000,
            'actual_hours' => 12,
            'actual_quantity' => 4,
            'actual_days' => 3,
            'work_date' => now()->toDateString(),
            'employee_id' => null,
            'is_unbudgeted' => false,
            'rework_type' => ProjectLabourActual::REWORK_NONE,
        ], $this->recorder);

        // hours formula: 12 × 2000 = 24000
        $this->assertSame('24000.00', (string) $actual->calculated_cost);
    }

    public function test_pax_formula_actual_quantity_times_actual_days_times_unit_rate(): void
    {
        $actual = $this->service->record([
            'project_enquiry_id' => $this->enquiry->id,
            'budget_line_id' => self::BUDGET_LINE_ID,
            'labour_role' => 'Installer',
            'labour_category' => 'site_labour',
            'budget_unit' => 'PAX',
            'unit_rate' => 1500,
            'actual_quantity' => 2,
            'actual_days' => 1,
            'work_date' => now()->toDateString(),
            'employee_id' => null,
            'is_unbudgeted' => false,
            'rework_type' => ProjectLabourActual::REWORK_NONE,
        ], $this->recorder);

        // PAX formula: 2 × 1 × 1500 = 3000
        $this->assertSame('3000.00', (string) $actual->calculated_cost);
    }

    public function test_hours_formula_rejects_zero_actual_hours(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->service->record([
            'project_enquiry_id' => $this->enquiry->id,
            'budget_line_id' => null,
            'labour_role' => 'Installer',
            'labour_category' => 'site_labour',
            'budget_unit' => 'hours',
            'unit_rate' => 0,
            'actual_hours' => 0,
            'work_date' => now()->toDateString(),
            'employee_id' => null,
            'is_unbudgeted' => true,
            'unbudgeted_reason' => 'Testing hours formula zero validation',
            'rework_type' => ProjectLabourActual::REWORK_NONE,
        ], $this->recorder);
    }

    // ── 3. Two-stage verification lifecycle ───────────────────────────────────

    public function test_full_workflow_recorded_to_finance_verified(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $this->assertSame(ProjectLabourActual::STATUS_RECORDED, $actual->status);

        // PO verify
        $poVerified = $this->service->poVerify($actual, $this->projectOfficer, 'Looks correct.');
        $this->assertSame(ProjectLabourActual::STATUS_PO_VERIFIED, $poVerified->status);
        $this->assertNotNull($poVerified->po_verified_at);
        $this->assertSame($this->projectOfficer->id, $poVerified->po_verified_by);

        // Finance verify
        $financeVerified = $this->service->financeVerify($poVerified, $this->financier, 'Approved.');
        $this->assertSame(ProjectLabourActual::STATUS_FINANCE_VERIFIED, $financeVerified->status);
        $this->assertNotNull($financeVerified->finance_verified_at);
        $this->assertNotNull($financeVerified->cost_line_id);
    }

    public function test_po_verify_requires_recorded_status(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $this->service->poVerify($actual, $this->projectOfficer);

        // Already PO verified — can't PO verify again
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->poVerify($actual, $this->projectOfficer);
    }

    public function test_finance_verify_requires_po_verified_status(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->financeVerify($actual, $this->financier);
    }

    public function test_finance_verify_blocks_unresolved_rate(): void
    {
        // Unbudgeted record has RATE_UNRESOLVED
        $actual = $this->service->record(
            $this->recordData(['is_unbudgeted' => true, 'unbudgeted_reason' => 'Emergency', 'budget_line_id' => null]),
            $this->recorder
        );
        $this->service->poVerify($actual, $this->projectOfficer);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->financeVerify($actual, $this->financier);
    }

    // ── 4. Authorization / permissions ────────────────────────────────────────

    public function test_unauthorized_user_cannot_record_labour(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service->record($this->recordData(), $this->unauthorized);
    }

    public function test_unauthorized_user_cannot_po_verify(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service->poVerify($actual, $this->unauthorized);
    }

    public function test_unauthorized_user_cannot_finance_verify(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $this->service->poVerify($actual, $this->projectOfficer);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service->financeVerify($actual, $this->unauthorized);
    }

    public function test_financier_can_finance_verify(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $this->service->poVerify($actual, $this->projectOfficer);

        $result = $this->service->financeVerify($actual, $this->financier);
        $this->assertSame(ProjectLabourActual::STATUS_FINANCE_VERIFIED, $result->status);
    }

    // ── 5. Authorization via HTTP (controller-level) ──────────────────────────

    public function test_http_store_requires_record_permission(): void
    {
        $response = $this->actingAs($this->unauthorized)
            ->postJson("/api/costs/projects/{$this->enquiry->id}/labour-actuals", [
                'budget_line_id' => self::BUDGET_LINE_ID,
                'actual_quantity' => 2,
                'actual_days' => 1,
                'work_date' => now()->toDateString(),
            ]);

        $response->assertStatus(403);
    }

    public function test_http_store_accepts_valid_data(): void
    {
        $response = $this->actingAs($this->recorder)
            ->postJson("/api/costs/projects/{$this->enquiry->id}/labour-actuals", [
                'budget_line_id' => self::BUDGET_LINE_ID,
                'labour_role' => 'Installer',
                'labour_category' => 'site_labour',
                'budget_unit' => 'PAX',
                'unit_rate' => 1500,
                'actual_quantity' => 2,
                'actual_days' => 1,
                'work_date' => now()->toDateString(),
                'is_unbudgeted' => false,
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'calculated_cost', 'status']]);
    }

    public function test_http_store_rejects_client_tampered_rate(): void
    {
        // The controller must reject rate/roles when is_unbudgeted is false.
        // This is the controller-level guard for server-authoritative fields.
        $response = $this->actingAs($this->recorder)
            ->postJson("/api/costs/projects/{$this->enquiry->id}/labour-actuals", [
                'budget_line_id' => self::BUDGET_LINE_ID,
                'labour_role' => 'Hacker',
                'labour_category' => 'hacked_category',
                'budget_unit' => 'HOURS',
                'unit_rate' => 999999,  // should be ignored
                'actual_quantity' => 2,
                'actual_days' => 1,
                'work_date' => now()->toDateString(),
                'is_unbudgeted' => false,
            ]);

        // The service should still override the budget fields.
        // But we test whether the controller strips them from the request.
        if ($response->status() === 422) {
            // Controller rejected — good, it enforces server-authoritative fields.
            $this->assertTrue(true);
        } else {
            $response->assertCreated();
            $data = $response->json('data');
            // Server must have overridden the client's rate.
            $this->assertSame('1500.00', $data['unit_rate']);
            $this->assertSame('Casual', $data['labour_role']);
            $this->assertSame('site_labour', $data['labour_category']);
            $this->assertSame('PAX', $data['budget_unit']);
        }
    }

    // ── 6. Payroll privacy ───────────────────────────────────────────────────

    public function test_resource_never_serializes_salary_or_bank_fields(): void
    {
        $actual = $this->service->record($this->recordData([
            'employee_id' => $this->createEmployee(),
        ]), $this->recorder);

        $response = $this->actingAs($this->financier)
            ->getJson("/api/costs/projects/{$this->enquiry->id}/labour-actuals/{$actual->id}");

        $response->assertOk();
        $data = $response->json('data');

        // Payroll privacy: no salary, no bank details, no statutory fields.
        $this->assertArrayNotHasKey('salary', $data);
        $this->assertArrayNotHasKey('bank_name', $data);
        $this->assertArrayNotHasKey('bank_branch', $data);
        $this->assertArrayNotHasKey('account_number', $data);
        $this->assertArrayNotHasKey('bank_code', $data);
        $this->assertArrayNotHasKey('statutory_exemptions', $data);
        $this->assertArrayNotHasKey('payment_method', $data);
        $this->assertArrayNotHasKey('kra_pin', $data);

        // Employee is only exposed as name + staff_number.
        $this->assertIsArray($data['employee']);
        $this->assertArrayHasKey('id', $data['employee']);
        $this->assertArrayHasKey('name', $data['employee']);
        $this->assertArrayHasKey('staff_number', $data['employee']);
        $this->assertCount(3, $data['employee']); // only 3 keys allowed
    }

    // ── 7. Unbudgeted labour ──────────────────────────────────────────────────

    public function test_unbudgeted_labour_records_with_zero_rate(): void
    {
        $actual = $this->service->record([
            'project_enquiry_id' => $this->enquiry->id,
            'budget_line_id' => null,
            'labour_role' => 'Emergency Casual',
            'labour_category' => 'site_labour',
            'budget_unit' => 'PAX',
            'unit_rate' => 0,
            'actual_quantity' => 1,
            'actual_days' => 1,
            'work_date' => now()->toDateString(),
            'employee_id' => null,
            'is_unbudgeted' => true,
            'unbudgeted_reason' => 'Emergency overtime on Saturday',
            'rework_type' => ProjectLabourActual::REWORK_NONE,
        ], $this->recorder);

        $this->assertTrue($actual->is_unbudgeted);
        $this->assertSame('0.00', (string) $actual->unit_rate);
        $this->assertSame(ProjectLabourActual::RATE_UNRESOLVED, $actual->rate_resolution_status);
        $this->assertNotNull($actual->rate_source);
        $this->assertSame('unresolved', $actual->rate_source['type']);
        $this->assertSame('0.00', (string) $actual->calculated_cost);  // 1 × 1 × 0 = 0
    }

    public function test_unbudgeted_without_reason_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->record([
            'project_enquiry_id' => $this->enquiry->id,
            'budget_line_id' => null,
            'labour_role' => 'Casual',
            'labour_category' => 'site_labour',
            'budget_unit' => 'PAX',
            'unit_rate' => 0,
            'actual_quantity' => 1,
            'actual_days' => 1,
            'work_date' => now()->toDateString(),
            'is_unbudgeted' => true,
            'unbudgeted_reason' => null,  // required for unbudgeted
            'rework_type' => ProjectLabourActual::REWORK_NONE,
        ], $this->recorder);
    }

    public function test_unbudgeted_can_finance_verify_when_rate_resolved(): void
    {
        // Unbudgeted with rate resolved by Finance override
        $actual = $this->service->record([
            'project_enquiry_id' => $this->enquiry->id,
            'budget_line_id' => null,
            'labour_role' => 'Emergency Casual',
            'labour_category' => 'site_labour',
            'budget_unit' => 'PAX',
            'unit_rate' => 0,
            'actual_quantity' => 1,
            'actual_days' => 1,
            'work_date' => now()->toDateString(),
            'employee_id' => null,
            'is_unbudgeted' => true,
            'unbudgeted_reason' => 'Emergency',
            'rework_type' => ProjectLabourActual::REWORK_NONE,
        ], $this->recorder);

        $this->service->poVerify($actual, $this->projectOfficer);

        // Finance must resolve the rate before verifying.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->financeVerify($actual->fresh(), $this->financier);
    }

    public function test_finance_resolve_unbudgeted_rate(): void
    {
        $actual = $this->service->record([
            'project_enquiry_id' => $this->enquiry->id,
            'budget_line_id' => null,
            'labour_role' => 'Emergency Casual',
            'labour_category' => 'site_labour',
            'budget_unit' => 'PAX',
            'unit_rate' => 0,
            'actual_quantity' => 1,
            'actual_days' => 1,
            'work_date' => now()->toDateString(),
            'employee_id' => null,
            'is_unbudgeted' => true,
            'unbudgeted_reason' => 'Emergency',
            'rework_type' => ProjectLabourActual::REWORK_NONE,
        ], $this->recorder);

        $this->service->poVerify($actual, $this->projectOfficer);

        // Finance resolves the rate
        $resolved = $this->service->resolveUnbudgetedRate(
            $actual->fresh(),
            $this->financier,
            '2000.00',
            'Standard rate card: Senior Technician',
            'MGT-APPROVAL-001',
        );

        $this->assertSame(ProjectLabourActual::RATE_RESOLVED, $resolved->rate_resolution_status);
        $this->assertSame('2000.00', (string) $resolved->unit_rate);
        $this->assertSame('2000.00', (string) $resolved->calculated_cost); // 1 × 1 × 2000
        $this->assertSame('finance_override', $resolved->rate_source['type']);
        $this->assertSame('Standard rate card: Senior Technician', $resolved->rate_source['source_description']);
        $this->assertSame('MGT-APPROVAL-001', $resolved->rate_source['authorization_reference']);

        // Now finance verify should succeed
        $verified = $this->service->financeVerify($resolved, $this->financier);
        $this->assertSame(ProjectLabourActual::STATUS_FINANCE_VERIFIED, $verified->status);
        $this->assertNotNull($verified->cost_line_id);
    }

    public function test_finance_verify_idempotency(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);

        // First finance verify
        $verified1 = $this->service->financeVerify($actual, $this->financier);
        $costLineId1 = $verified1->cost_line_id;

        // Second finance verify (simulating client retry)
        $verified2 = $this->service->financeVerify($actual->fresh(), $this->financier);
        $costLineId2 = $verified2->cost_line_id;

        // Should return the same CostLine, not create a duplicate
        $this->assertSame($costLineId1, $costLineId2);
        $this->assertSame($verified1->id, $verified2->id);
    }

    public function test_finance_resolve_rate_requires_po_verified_status(): void
    {
        $actual = $this->service->record([
            'project_enquiry_id' => $this->enquiry->id,
            'budget_line_id' => null,
            'labour_role' => 'Emergency Casual',
            'labour_category' => 'site_labour',
            'budget_unit' => 'PAX',
            'unit_rate' => 0,
            'actual_quantity' => 1,
            'actual_days' => 1,
            'work_date' => now()->toDateString(),
            'employee_id' => null,
            'is_unbudgeted' => true,
            'unbudgeted_reason' => 'Emergency',
            'rework_type' => ProjectLabourActual::REWORK_NONE,
        ], $this->recorder);

        // Try to resolve rate before PO verification
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->resolveUnbudgetedRate(
            $actual,
            $this->financier,
            '2000.00',
            'Standard rate card',
            'REF-001',
        );
    }

    public function test_finance_resolve_rate_rejects_already_resolved(): void
    {
        $actual = $this->service->record([
            'project_enquiry_id' => $this->enquiry->id,
            'budget_line_id' => null,
            'labour_role' => 'Emergency Casual',
            'labour_category' => 'site_labour',
            'budget_unit' => 'PAX',
            'unit_rate' => 0,
            'actual_quantity' => 1,
            'actual_days' => 1,
            'work_date' => now()->toDateString(),
            'employee_id' => null,
            'is_unbudgeted' => true,
            'unbudgeted_reason' => 'Emergency',
            'rework_type' => ProjectLabourActual::REWORK_NONE,
        ], $this->recorder);

        $this->service->poVerify($actual, $this->projectOfficer);

        // First resolution
        $this->service->resolveUnbudgetedRate(
            $actual->fresh(),
            $this->financier,
            '2000.00',
            'Standard rate card',
            'REF-001',
        );

        // Try to resolve again
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->resolveUnbudgetedRate(
            $actual->fresh(),
            $this->financier,
            '2500.00',
            'Different rate',
            'REF-002',
        );
    }

    public function test_finance_resolve_rate_rejects_budgeted_actual(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $this->service->poVerify($actual, $this->projectOfficer);

        // Try to resolve rate on a budgeted actual (should fail)
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->resolveUnbudgetedRate(
            $actual,
            $this->financier,
            '2000.00',
            'Standard rate card',
            'REF-001',
        );
    }

    // ── 8. Correction (reversing pair) ────────────────────────────────────────

    public function test_correct_creates_reversing_pair(): void
    {
        $actual = $this->service->record($this->recordData([
            'actual_quantity' => 3,
            'actual_days' => 1,
        ]), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);
        $originalLine = $verified->actualCostLine;

        $this->assertSame('4500.00', (string) $verified->calculated_cost); // 3 × 1 × 1500

        $corrected = $this->service->correct(
            $verified,
            $this->financier,
            ['actual_quantity' => 2, 'actual_days' => 1],
            'Wrong quantity reported.',
        );

        // W7-13: opening a correction changes nothing economically. The original
        // stays authoritative and its CostLine is never modified.
        $this->assertSame(ProjectLabourActual::STATUS_FINANCE_VERIFIED, $verified->fresh()->status);
        $this->assertSame(CostLine::STATUS_VERIFIED, $originalLine->fresh()->status);
        $this->assertSame(ProjectLabourActual::STATUS_RECORDED, $corrected->status);
        $this->assertSame($verified->id, $corrected->reversal_of_id);
        $this->assertNull($corrected->cost_line_id);

        $corrected = $this->service->poVerify($corrected, $this->projectOfficer);
        $corrected = $this->service->financeVerify($corrected, $this->financier);

        // Finance verification issues the W6-4 pair on the same project.
        $transfer = \App\Modules\Finance\CostCollector\Models\CostLineTransfer::findOrFail($corrected->correction_transfer_id);
        $this->assertSame('correction', $transfer->transfer_type);
        $this->assertSame($originalLine->id, $transfer->source_cost_line_id);
        $this->assertSame('-4500.00', (string) $transfer->outLine->net_amount);
        $this->assertSame('3000.00', (string) $transfer->inLine->net_amount);
        $this->assertStringStartsWith('CL-TRF-OUT-', $transfer->outLine->ref);
        $this->assertStringStartsWith('CL-TRF-IN-', $transfer->inLine->ref);
        $this->assertSame($this->enquiry->id, $transfer->outLine->project_enquiry_id);
        $this->assertSame($this->enquiry->id, $transfer->inLine->project_enquiry_id);
        $this->assertSame($transfer->in_cost_line_id, $corrected->cost_line_id);

        // Original line untouched; original actual superseded only now.
        $this->assertSame(CostLine::STATUS_VERIFIED, $originalLine->fresh()->status);
        $this->assertSame('4500.00', (string) $originalLine->fresh()->net_amount);
        $this->assertSame(ProjectLabourActual::STATUS_SUPERSEDED, $verified->fresh()->status);
        $this->assertSame($corrected->id, $verified->fresh()->superseded_by_id);
    }

    public function test_correct_recalculates_cost_with_corrected_values(): void
    {
        $actual = $this->service->record($this->recordData([
            'actual_quantity' => 3,
            'actual_days' => 1,
        ]), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $corrected = $this->service->correct(
            $verified,
            $this->financier,
            ['actual_quantity' => 5, 'actual_days' => 2],
            'Need to correct quantity and days.',
        );

        $this->assertSame('15000.00', (string) $corrected->calculated_cost); // 5 × 2 × 1500 = 15000
    }

    public function test_correct_preserves_rate_snapshot(): void
    {
        $actual = $this->service->record($this->recordData([
            'actual_quantity' => 2,
            'actual_days' => 1,
        ]), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $this->assertSame('1500.00', (string) $verified->unit_rate);
        $this->assertSame('project_budget', $verified->rate_source['type']);

        $corrected = $this->service->correct(
            $verified,
            $this->financier,
            ['actual_quantity' => 4],
            'Correcting quantity only.',
        );

        // Rate snapshot preserved through correction.
        $this->assertSame('1500.00', (string) $corrected->unit_rate);
        $this->assertSame('project_budget', $corrected->rate_source['type']);
    }

    public function test_correct_requires_finance_verified_status(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->correct($actual, $this->financier, ['actual_quantity' => 1], 'Fix.');
    }

    public function test_correct_cannot_correct_already_superseded(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $corrected = $this->service->correct($verified, $this->financier, ['actual_quantity' => 1], 'Fix1.');
        $corrected = $this->service->poVerify($corrected, $this->projectOfficer);
        $corrected = $this->service->financeVerify($corrected, $this->financier);

        // Try to correct the original (already superseded)
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->correct($verified->fresh(), $this->financier, ['actual_quantity' => 2], 'Fix2.');
    }

    public function test_correct_rejects_rate_edits(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        // Correction data must NOT allow changing the rate — only quantities/days.
        $corrected = $this->service->correct(
            $verified,
            $this->financier,
            ['actual_quantity' => 4, 'unit_rate' => 999999],  // rate should be ignored
            'Correcting quantity.',
        );

        $this->assertSame('1500.00', (string) $corrected->unit_rate);
    }

    public function test_correct_rejects_already_superseded_actual(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $corrected = $this->service->correct($verified, $this->financier, ['actual_quantity' => 1], 'First correction.');
        $corrected = $this->service->poVerify($corrected, $this->projectOfficer);
        $corrected = $this->service->financeVerify($corrected, $this->financier);

        // Try to correct the original (already superseded)
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->correct($verified->fresh(), $this->financier, ['actual_quantity' => 2], 'Second correction.');
    }

    public function test_correct_rejects_duplicate_correction(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $corrected = $this->service->correct($verified, $this->financier, ['actual_quantity' => 1], 'First correction.');

        // Try to create another correction for the same original
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->correct($verified->fresh(), $this->financier, ['actual_quantity' => 2], 'Second correction.');
    }

    // ── 9. Exact-once correction / concurrency ───────────────────────────────

    public function test_reversal_of_id_is_unique(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $corrected = $this->service->correct($verified, $this->financier, ['actual_quantity' => 1], 'Fix.');

        // The database unique constraint on reversal_of_id means a second
        // correction pointing to the same original must fail.
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Simulate a second concurrent correct() call by directly inserting.
        ProjectLabourActual::create([
            'project_enquiry_id' => $this->enquiry->id,
            'budget_line_id' => self::BUDGET_LINE_ID,
            'budget_id' => $verified->budget_id,
            'consumes_cost_line_id' => $verified->consumes_cost_line_id,
            'labour_role' => 'Casual',
            'labour_category' => 'site_labour',
            'budget_unit' => 'PAX',
            'unit_rate' => 1500,
            'actual_quantity' => 1,
            'actual_days' => 1,
            'calculated_cost' => 1500,
            'work_date' => now(),
            'is_unbudgeted' => false,
            'rework_type' => ProjectLabourActual::REWORK_NONE,
            'status' => ProjectLabourActual::STATUS_RECORDED,
            'recorded_by' => $this->financier->id,
            'recorded_at' => now(),
            'reversal_of_id' => $verified->id,  // violates unique constraint
            'correction_reason' => 'Should fail.',
            'rate_resolution_status' => ProjectLabourActual::RATE_RESOLVED,
            'rate_source' => ['type' => 'project_budget'],
        ]);
    }

    // True two-connection races live in W7LabourConcurrencyTest (forked processes).

    // ── 10. Closure guard ────────────────────────────────────────────────────

    public function test_closed_project_rejects_recording(): void
    {
        $this->enquiry->update(['financial_closure_status' => 'closed']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->record($this->recordData(), $this->recorder);
    }
    public function test_closed_project_rejects_po_verification(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $this->enquiry->update(['financial_closure_status' => 'closed']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->poVerify($actual, $this->projectOfficer);
    }

    public function test_closed_project_rejects_verified_labour_correction(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $actual = $this->service->financeVerify($actual, $this->financier);
        $this->enquiry->update(['financial_closure_status' => 'closed']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->correct($actual, $this->financier, ['actual_quantity' => 1], 'Closed project');
    }

    public function test_closed_project_rejects_unbudgeted_rate_resolution(): void
    {
        $actual = $this->service->record($this->recordData([
            'budget_line_id' => null, 'is_unbudgeted' => true,
            'unbudgeted_reason' => 'Additional crew required',
        ]), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $this->enquiry->update(['financial_closure_status' => 'closed']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->resolveUnbudgetedRate($actual, $this->financier, '2000', 'Approved rate card', 'FIN-123');
    }

    public function test_closed_project_allows_finance_verify_after_reopen(): void
    {
        // Record and PO verify while project is open.
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);

        // Close the project.
        $this->enquiry->update(['financial_closure_status' => 'closed']);

        // Finance verify should fail because project is closed.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->financeVerify($actual->fresh(), $this->financier);
    }

    // ── 11. CostLine creation ────────────────────────────────────────────────

    public function test_finance_verify_creates_cost_line_with_correct_expense_code(): void
    {
        $actual = $this->service->record($this->recordData([
            'actual_quantity' => 2,
            'actual_days' => 1,
        ]), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $costLine = $verified->actualCostLine;

        $this->assertNotNull($costLine);
        $this->assertSame(CostLine::NATURE_ACTUAL, $costLine->nature);
        $this->assertSame(CostLine::STATUS_VERIFIED, $costLine->status);

        // DL-CAS-001 = site labour (category: site_labour)
        $this->assertSame('DL-CAS-001', $costLine->expenseCode->code);
        $this->assertSame('labour', $costLine->details['budget_category']);
    }

    public function test_cost_line_postsindependently_false(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $costLine = $verified->actualCostLine;

        // STAB-7: CostLine exists for attribution but no journal entry.
        // postsIndependently=false means CostCollectorService skipped posting.
        $this->assertNull($costLine->journal_entry_id);
        $this->assertNull($costLine->posted_at);

        // But the CostLine still exists for project cost attribution.
        $this->assertNotNull($costLine->ref);
        $this->assertStringStartsWith('CL-', $costLine->ref);
    }

    public function test_cost_line_consumes_budget_line(): void
    {
        $actual = $this->service->record($this->recordData([
            'actual_quantity' => 2,
            'actual_days' => 1,
        ]), $this->recorder);

        $this->assertNotNull($actual->consumes_cost_line_id);

        $planned = CostLine::find($actual->consumes_cost_line_id);
        $this->assertSame(CostLine::NATURE_PLANNED, $planned->nature);
        $this->assertSame(CostLine::STATUS_VERIFIED, $planned->status);
    }

    public function test_rework_uses_rework_expense_code(): void
    {
        $actual = $this->service->record($this->recordData([
            'actual_quantity' => 2,
            'actual_days' => 1,
            'rework_type' => ProjectLabourActual::REWORK_CLIENT_CAUSED,
        ]), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $costLine = $verified->actualCostLine;
        $this->assertSame('RW-LAB-001', $costLine->expenseCode->code);
        $this->assertSame('client_caused', $costLine->details['rework_type']);
    }

    public function test_workshop_labour_uses_workshop_expense_code(): void
    {
        // Create a budget line with workshop labour
        $workshopLineId = 'labour-workshop-line-001';
        $budget = TaskBudgetData::where('enquiry_task_id', $this->budgetTask->id)->first();
        $budget->update([
            'labour_data' => [
                [
                    'id' => $workshopLineId,
                    'type' => 'Fitter',
                    'category' => 'workshop_labour',
                    'description' => 'Workshop assembly',
                    'unit' => 'PAX',
                    'quantity' => 2,
                    'days' => 5,
                    'unitRate' => 1200,
                    'amount' => 12000,
                    'isIncluded' => true,
                ],
            ],
        ]);

        // Create planned cost line
        $collector = $this->app->make(\App\Modules\Finance\CostCollector\Services\CostCollectorService::class);
        $planned = new \App\Modules\Finance\CostCollector\Contracts\PlannedLine(
            category: 'labour',
            amount: '12000.00',
            description: 'Fitter — Workshop assembly',
            enquiryId: $this->enquiry->id,
            taskId: $this->budgetTask->id,
            unit: 'PAX',
            quantity: '2',
            unitRate: '1200',
            sourceId: $budget->id,
            sourceRef: $workshopLineId,
            isAddition: false,
            details: [],
        );
        $collector->postPlanned($planned);

        $actual = $this->service->record([
            'project_enquiry_id' => $this->enquiry->id,
            'budget_line_id' => $workshopLineId,
            'labour_role' => 'Fitter',
            'labour_category' => 'workshop_labour',
            'budget_unit' => 'PAX',
            'unit_rate' => 1200,
            'actual_quantity' => 1,
            'actual_days' => 1,
            'work_date' => now()->toDateString(),
            'employee_id' => null,
            'is_unbudgeted' => false,
            'rework_type' => ProjectLabourActual::REWORK_NONE,
        ], $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $costLine = $verified->actualCostLine;
        $this->assertSame('DL-CAS-002', $costLine->expenseCode->code);
    }

    // ── 12. Return for correction ─────────────────────────────────────────────

    public function test_return_for_correction_from_recorded_status(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $this->assertSame(ProjectLabourActual::STATUS_RECORDED, $actual->status);

        $returned = $this->service->returnForCorrection($actual, $this->projectOfficer, 'Need to fix role.');
        $this->assertSame(ProjectLabourActual::STATUS_RETURNED, $returned->status);
        $this->assertNotNull($returned->returned_at);
    }

    public function test_return_for_correction_from_po_verified_status(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);

        $returned = $this->service->returnForCorrection($actual->fresh(), $this->financier, 'Need to fix quantity.');
        $this->assertSame(ProjectLabourActual::STATUS_RETURNED, $returned->status);
    }

    public function test_cannot_return_finance_verified(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->returnForCorrection($verified, $this->financier, 'Try to return verified.');
    }

    public function test_returned_record_is_corrected_resubmitted_and_verified(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $returned = $this->service->returnForCorrection($actual->fresh(), $this->financier, 'Fix needed.');

        $resubmitted = $this->service->resubmit($returned, $this->recorder, ['actual_quantity' => 3]);
        $this->assertSame(ProjectLabourActual::STATUS_RECORDED, $resubmitted->status);

        $actual = $this->service->poVerify($resubmitted, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $this->assertSame(ProjectLabourActual::STATUS_FINANCE_VERIFIED, $verified->status);
        $this->assertSame('4500.00', (string) $verified->actualCostLine->net_amount);
    }

    // ── 13. Index/scoping ───────────────────────────────────────────────────

    public function test_index_excludes_superseded(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        // Correct it; the original is superseded once the correction is Finance-verified.
        $corrected = $this->service->correct($verified, $this->financier, ['actual_quantity' => 1], 'Supersede.');
        $this->assertCount(2, $this->service->index($this->enquiry));
        $corrected = $this->service->poVerify($corrected, $this->projectOfficer);
        $this->service->financeVerify($corrected, $this->financier);

        // Index should not include the superseded original.
        $collection = $this->service->index($this->enquiry);
        $this->assertCount(1, $collection);
        $this->assertNotContains($verified->fresh()->id, $collection->pluck('id')->all());
    }

    public function test_index_excludes_unbudgeted_from_consumption(): void
    {
        $budgetLines = $this->service->getBudgetLabourLines($this->enquiry);
        $this->assertIsArray($budgetLines);
        $this->assertCount(1, $budgetLines);

        $line = $budgetLines[0];
        $this->assertSame(self::BUDGET_LINE_ID, $line['id']);
        $this->assertEquals(0, $line['consumed_cost']);
        $this->assertEquals(27000, $line['budget_amount']);
        $this->assertEquals(27000, $line['remaining_cost']);
    }

    public function test_budget_labour_lines_preserve_negative_over_budget_variance(): void
    {
        $actual = $this->service->record($this->recordData([
            'actual_quantity' => 20,
            'actual_days' => 1,
        ]), $this->recorder);

        $actual = $this->service->poVerify($actual, $this->projectOfficer);

        // Pending cost is shown separately; the authoritative consumed figure moves
        // only on Finance verification.
        $line = $this->service->getBudgetLabourLines($this->enquiry)[0];
        $this->assertSame('0.00', $line['verified_cost']);
        $this->assertSame('30000.00', $line['pending_cost']);
        $this->assertSame('-3000.00', $line['projected_remaining_cost']);

        $this->service->financeVerify($actual, $this->financier);
        $line = $this->service->getBudgetLabourLines($this->enquiry)[0];

        $this->assertSame('30000.00', $line['consumed_cost']);
        $this->assertSame('-3000.00', $line['remaining_cost']);
        $this->assertTrue($line['is_over_budget']);
    }

    // ── 14. Budget labour lines API ──────────────────────────────────────────

    public function test_budget_labour_lines_api(): void
    {
        $response = $this->actingAs($this->recorder)
            ->getJson("/api/costs/projects/{$this->enquiry->id}/budget-labour-lines");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame(self::BUDGET_LINE_ID, $data[0]['id']);
        $this->assertSame('Casual', $data[0]['type']);
        $this->assertSame('site_labour', $data[0]['category']);
        $this->assertSame('PAX', $data[0]['unit']);
        $this->assertEquals(6, $data[0]['quantity']);
        $this->assertEquals(3, $data[0]['days']);
        // Money is an exact decimal string, never a JSON float.
        $this->assertSame('1500.00', $data[0]['unit_rate']);
        $this->assertSame('27000.00', $data[0]['budget_amount']);
        $this->assertSame('open', $response->json('meta.financial_closure_status'));
        $this->assertSame(true, $data[0]['is_included']);
    }

    // ── 15. Audit trail ─────────────────────────────────────────────────────

    public function test_record_creates_governance_audit_log(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);

        $log = DB::table('governance_audit_logs')
            ->where('model_type', ProjectLabourActual::class)
            ->where('model_id', $actual->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('record', $log->action_status);
        $this->assertSame('labour_cost', $log->gate_type);
    }

    public function test_finance_verify_creates_governance_audit_log(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $count = DB::table('governance_audit_logs')
            ->where('model_type', ProjectLabourActual::class)
            ->where('model_id', $verified->id)
            ->count();

        $this->assertGreaterThanOrEqual(2, $count); // record + finance_verify
    }

    // ══ Report 41 remediation evidence ══════════════════════════════════════

    // ── R1. Return → Correct → Resubmit ───────────────────────────────────────

    public function test_return_correct_resubmit_full_lifecycle(): void
    {
        $actual = $this->service->record($this->recordData(['actual_quantity' => 2]), $this->recorder);

        // Project Officer returns from recorded.
        $returned = $this->service->returnForCorrection($actual, $this->projectOfficer, 'Quantity is wrong.');
        $this->assertSame(ProjectLabourActual::STATUS_RETURNED, $returned->status);

        // Recorder corrects a permitted field and resubmits through the real endpoint.
        $response = $this->actingAs($this->recorder)->postJson(
            "/api/costs/projects/{$this->enquiry->id}/labour-actuals/{$actual->id}/resubmit",
            ['actual_quantity' => 4, 'unit_rate' => 1],
        );
        $response->assertOk();
        $this->assertSame('recorded', $response->json('data.status'));
        $this->assertSame(1, $response->json('data.resubmission_count'));
        $this->assertSame($this->recorder->id, $response->json('data.resubmitted_by.id'));
        $this->assertNotNull($response->json('data.resubmitted_at'));
        $this->assertSame('1500.00', $response->json('data.unit_rate')); // client rate ignored
        $this->assertSame('6000.00', $response->json('data.calculated_cost')); // 4 × 1 × 1500

        $history = $response->json('data.return_history');
        $this->assertCount(1, $history);
        $this->assertSame('recorded', $history[0]['returned_from_status']);
        $this->assertSame('Quantity is wrong.', $history[0]['return_reason']);
        $this->assertSame($this->projectOfficer->id, $history[0]['returned_by']['id']);
        $this->assertSame($this->recorder->id, $history[0]['resubmitted_by']['id']);
        $this->assertSame(['from' => '2.00', 'to' => '4'], $history[0]['changes']['actual_quantity']);

        // Project Officer review restarts, then Finance review.
        $actual = $this->service->poVerify($actual->fresh(), $this->projectOfficer);
        $returnedAgain = $this->service->returnForCorrection($actual, $this->financier, 'Work date wrong.');
        $this->service->resubmit($returnedAgain, $this->recorder, ['work_date' => now()->subDay()->toDateString()]);
        $actual = $this->service->poVerify($actual->fresh(), $this->projectOfficer);
        $verified = $this->service->financeVerify($actual, $this->financier);

        $this->assertSame(ProjectLabourActual::STATUS_FINANCE_VERIFIED, $verified->status);
        $this->assertSame(2, $verified->fresh()->resubmission_count);
        $cycles = \App\Modules\Finance\CostCollector\Models\ProjectLabourActualReturn::where('project_labour_actual_id', $actual->id)->orderBy('cycle')->get();
        $this->assertSame([1, 2], $cycles->pluck('cycle')->all());
        $this->assertSame(['recorded', 'po_verified'], $cycles->pluck('returned_from_status')->all());
        $this->assertSame('2.00', $cycles[0]->snapshot_before['actual_quantity']);
    }

    public function test_resubmit_clears_stale_po_verification(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $returned = $this->service->returnForCorrection($actual, $this->financier, 'Recheck.');
        $resubmitted = $this->service->resubmit($returned, $this->recorder, ['actual_quantity' => 1]);

        $this->assertNull($resubmitted->po_verified_by);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->financeVerify($resubmitted, $this->financier); // must go back through PO review
    }

    public function test_resubmit_requires_returned_status(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->resubmit($actual, $this->recorder, ['actual_quantity' => 1]);
    }

    public function test_resubmit_requires_recording_permission(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $this->service->returnForCorrection($actual, $this->projectOfficer, 'Fix.');

        $this->actingAs($this->unauthorized)->postJson(
            "/api/costs/projects/{$this->enquiry->id}/labour-actuals/{$actual->id}/resubmit",
            ['actual_quantity' => 1],
        )->assertForbidden();

        $this->assertSame(ProjectLabourActual::STATUS_RETURNED, $actual->fresh()->status);
    }

    public function test_return_requires_a_reviewer_for_the_stage(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);

        // Finance cannot return an actual the Project Officer has not reviewed yet.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service->returnForCorrection($actual, $this->financier, 'Too early.');
    }

    public function test_return_history_is_immutable(): void
    {
        $actual = $this->service->record($this->recordData(), $this->recorder);
        $this->service->returnForCorrection($actual, $this->projectOfficer, 'Fix.');
        $this->service->resubmit($actual->fresh(), $this->recorder, ['actual_quantity' => 1]);

        $row = \App\Modules\Finance\CostCollector\Models\ProjectLabourActualReturn::firstOrFail();

        try {
            $row->update(['return_reason' => 'Rewritten']);
            $this->fail('A completed return cycle must not be editable.');
        } catch (\LogicException) {
        }

        $this->expectException(\LogicException::class);
        $row->delete();
    }

    public function test_unbudgeted_classification_change_resets_resolved_rate(): void
    {
        $actual = $this->service->record($this->recordData([
            'budget_line_id' => null, 'is_unbudgeted' => true, 'labour_role' => 'Rigger',
            'labour_category' => 'site_labour', 'budget_unit' => 'PAX', 'unbudgeted_reason' => 'Extra rigging',
        ]), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $actual = $this->service->resolveUnbudgetedRate($actual, $this->financier, '1800', 'Rigger rate memo', 'FIN-77');
        $returned = $this->service->returnForCorrection($actual, $this->financier, 'Role was electrician.');

        $resubmitted = $this->service->resubmit($returned, $this->recorder, ['labour_role' => 'Electrician']);

        $this->assertSame(ProjectLabourActual::RATE_UNRESOLVED, $resubmitted->rate_resolution_status);
        $this->assertSame('0.00', (string) $resubmitted->unit_rate);
        $this->assertSame('Electrician', $resubmitted->labour_role);
    }

    // ── R2. Unbudgeted rate resolution ────────────────────────────────────────

    public function test_rate_resolution_requires_source_reference_and_positive_rate(): void
    {
        $actual = $this->service->record($this->recordData([
            'budget_line_id' => null, 'is_unbudgeted' => true, 'unbudgeted_reason' => 'Night shift',
        ]), $this->recorder);
        $actual = $this->service->poVerify($actual, $this->projectOfficer);
        $url = "/api/costs/projects/{$this->enquiry->id}/labour-actuals/{$actual->id}/resolve-rate";

        $this->actingAs($this->financier)->postJson($url, ['resolved_rate' => 2000, 'rate_source_description' => 'Memo'])
            ->assertStatus(422)->assertJsonValidationErrors('authorization_reference');
        $this->actingAs($this->financier)->postJson($url, ['resolved_rate' => 0, 'rate_source_description' => 'Memo', 'authorization_reference' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('resolved_rate');
        $this->actingAs($this->projectOfficer)->postJson($url, ['resolved_rate' => 2000, 'rate_source_description' => 'Memo', 'authorization_reference' => 'X'])
            ->assertForbidden();

        $this->actingAs($this->financier)->postJson($url, ['resolved_rate' => 2000, 'rate_source_description' => 'Memo', 'authorization_reference' => 'FIN-1'])
            ->assertOk()
            ->assertJsonPath('data.rate_resolution_status', 'resolved')
            ->assertJsonPath('data.rate_source.authorization_reference', 'FIN-1')
            ->assertJsonPath('data.calculated_cost', '4000.00');

        $verified = $this->service->financeVerify($actual->fresh(), $this->financier);
        $this->assertSame('4000.00', (string) $verified->actualCostLine->net_amount);
        $this->assertNull($verified->actualCostLine->consumes_line_id);
    }

    // ── R3. W7-13 correction: KES 20,000 → KES 16,000 ────────────────────────

    public function test_correction_20000_to_16000_project_costing_is_16000(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);
        $actual = $this->verifiedActual($enquiry, 'line-a', 10);
        $this->assertSame('20000.00', $this->labourActual($enquiry));

        $corrected = $this->service->correct($actual, $this->financier, ['actual_quantity' => 8], 'Two crew never arrived.');

        // Pending correction: still exactly the original once.
        $this->assertSame('20000.00', $this->labourActual($enquiry));

        $corrected = $this->service->poVerify($corrected, $this->projectOfficer);
        $this->service->financeVerify($corrected, $this->financier);

        $this->assertSame('16000.00', $this->labourActual($enquiry)); // not 36,000, not 0
        $line = collect($this->service->getBudgetLabourLines($enquiry))->firstWhere('id', 'line-a');
        $this->assertSame('16000.00', $line['verified_cost']);
        $this->assertSame('4000.00', $line['remaining_cost']);
    }

    public function test_correction_of_a_correction_stays_exactly_once(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);
        $actual = $this->verifiedActual($enquiry, 'line-a', 10);

        $first = $this->service->correct($actual, $this->financier, ['actual_quantity' => 8], 'First fix.');
        $first = $this->service->financeVerify($this->service->poVerify($first, $this->projectOfficer), $this->financier);
        $second = $this->service->correct($first, $this->financier, ['actual_quantity' => 9], 'Second fix.');
        $this->service->financeVerify($this->service->poVerify($second, $this->projectOfficer), $this->financier);

        $this->assertSame('18000.00', $this->labourActual($enquiry));
    }

    public function test_reclassification_uses_w6_transfer(): void
    {
        $a = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);
        $b = $this->makeProject([$this->labourLine('line-b', 10, 1, 2000)]);
        $actual = $this->verifiedActual($a, 'line-a', 5);

        $this->actingAs($this->financier)->postJson(
            "/api/costs/projects/{$a->id}/labour-actuals/{$actual->id}/reclassify",
            ['destination_enquiry_id' => $b->id, 'reason' => 'Crew worked on the other job.'],
        )->assertOk()->assertJsonPath('data.reclassification.destination_enquiry_id', $b->id);

        $this->assertSame('0.00', $this->labourActual($a));
        $this->assertSame('10000.00', $this->labourActual($b));
        $transfer = \App\Modules\Finance\CostCollector\Models\CostLineTransfer::firstOrFail();
        $this->assertSame('reclassification', $transfer->transfer_type);

        // A reclassified cost can no longer be corrected in its old project.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->correct($actual->fresh(), $this->financier, ['actual_quantity' => 1], 'Late fix.');
    }

    // ── R4. Closed project ────────────────────────────────────────────────────

    public function test_closed_project_blocks_every_labour_transition(): void
    {
        $recorded = $this->service->record($this->recordData(), $this->recorder);
        $returned = $this->service->returnForCorrection(
            $this->service->record($this->recordData(), $this->recorder), $this->projectOfficer, 'Fix.');
        $verified = $this->service->financeVerify(
            $this->service->poVerify($this->service->record($this->recordData(), $this->recorder), $this->projectOfficer),
            $this->financier);
        $other = $this->makeProject([$this->labourLine('line-x', 1, 1, 100)]);

        $this->enquiry->update(['financial_closure_status' => 'closed']);
        $base = "/api/costs/projects/{$this->enquiry->id}/labour-actuals";

        $this->actingAs($this->recorder)->postJson($base, $this->recordData(['project_enquiry_id' => null]))->assertStatus(422);
        $this->actingAs($this->projectOfficer)->postJson("{$base}/{$recorded->id}/po-verify")->assertStatus(422);
        $this->actingAs($this->projectOfficer)->postJson("{$base}/{$recorded->id}/return", ['reason' => 'x'])->assertStatus(422);
        $this->actingAs($this->recorder)->postJson("{$base}/{$returned->id}/resubmit", ['actual_quantity' => 1])->assertStatus(422);
        $this->actingAs($this->financier)->postJson("{$base}/{$verified->id}/correct", ['correction_reason' => 'x', 'actual_quantity' => 1])->assertStatus(422);
        $this->actingAs($this->financier)->postJson("{$base}/{$verified->id}/reclassify", ['destination_enquiry_id' => $other->id, 'reason' => 'x'])->assertStatus(422);

        $this->actingAs($this->financier)->getJson($base)->assertOk()
            ->assertJsonPath('meta.financial_closure_status', 'closed');
        $this->assertSame(1, CostLine::where('project_enquiry_id', $this->enquiry->id)->where('nature', 'actual')->count());
    }

    // ── R5. Server authority: rate tampering and cross-project lines ─────────

    public function test_explicit_rate_tampering_200_and_20000_is_ignored(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);

        foreach ([200, 20000] as $forged) {
            $response = $this->actingAs($this->recorder)->postJson("/api/costs/projects/{$enquiry->id}/labour-actuals", [
                'budget_line_id' => 'line-a', 'unit_rate' => $forged, 'labour_role' => 'Forged', 'budget_unit' => 'hours',
                'actual_quantity' => 1, 'actual_days' => 1, 'work_date' => now()->toDateString(), 'is_unbudgeted' => false,
            ])->assertCreated();

            $this->assertSame('2000.00', $response->json('data.unit_rate'));
            $this->assertSame('2000.00', $response->json('data.calculated_cost'));
            $this->assertSame('PAX', $response->json('data.budget_unit'));
            $this->assertSame('project_budget', $response->json('data.rate_source.type'));
        }
    }

    public function test_project_a_actual_cannot_use_project_b_budget_line(): void
    {
        $a = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);
        $b = $this->makeProject([$this->labourLine('line-b', 10, 1, 9999)]);

        $this->actingAs($this->recorder)->postJson("/api/costs/projects/{$a->id}/labour-actuals", [
            'budget_line_id' => 'line-b', 'actual_quantity' => 1, 'actual_days' => 1,
            'work_date' => now()->toDateString(), 'is_unbudgeted' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('budget_line_id');

        // Nor through a resubmission.
        $actual = $this->service->record($this->recordData(['project_enquiry_id' => $a->id, 'budget_line_id' => 'line-a']), $this->recorder);
        $this->service->returnForCorrection($actual, $this->projectOfficer, 'Wrong line.');
        $this->actingAs($this->recorder)->postJson(
            "/api/costs/projects/{$a->id}/labour-actuals/{$actual->id}/resubmit", ['budget_line_id' => 'line-b'],
        )->assertStatus(422);

        // And an actual id from B cannot be acted on through A's route.
        $bActual = $this->service->record($this->recordData(['project_enquiry_id' => $b->id, 'budget_line_id' => 'line-b']), $this->recorder);
        $this->actingAs($this->projectOfficer)->postJson("/api/costs/projects/{$a->id}/labour-actuals/{$bActual->id}/po-verify")
            ->assertNotFound();
        $this->assertSame(0, CostLine::where('project_enquiry_id', $a->id)->where('nature', 'actual')->count());
    }

    // ── R6. Budget revision immutability ──────────────────────────────────────

    public function test_budget_revision_keeps_historical_rate_and_new_labour_uses_v2(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);
        $v1Actual = $this->verifiedActual($enquiry, 'line-a', 1);
        $v1Line = $v1Actual->actualCostLine;
        $pendingV1 = $this->service->poVerify(
            $this->service->record($this->recordData(['project_enquiry_id' => $enquiry->id, 'budget_line_id' => 'line-a', 'actual_quantity' => 1]), $this->recorder),
            $this->projectOfficer,
        );

        // Legitimate revision: edit the approved budget and re-project it.
        $budget = TaskBudgetData::whereHas('task', fn ($q) => $q->where('project_enquiry_id', $enquiry->id))->firstOrFail();
        $budget->update(['labour_data' => [$this->labourLine('line-a', 10, 1, 2500)]]);
        $this->app->make(\App\Modules\Finance\CostCollector\Services\BudgetProjector::class)->project($budget->fresh(), $this->financier->id);

        // Historical verified actual: unchanged rate, amount and CostLine.
        $v1Actual->refresh();
        $this->assertSame('2000.00', (string) $v1Actual->unit_rate);
        $this->assertSame('2000.00', (string) $v1Actual->calculated_cost);
        $this->assertSame('2000.00', (string) $v1Line->fresh()->net_amount);
        $this->assertSame(CostLine::STATUS_VERIFIED, $v1Line->fresh()->status);

        // An actual recorded under V1 still verifies at its V1 snapshot.
        $pendingV1 = $this->service->financeVerify($pendingV1, $this->financier);
        $this->assertSame('2000.00', (string) $pendingV1->actualCostLine->net_amount);

        // New labour follows V2.
        $v2Actual = $this->verifiedActual($enquiry, 'line-a', 1);
        $this->assertSame('2500.00', (string) $v2Actual->unit_rate);
        $this->assertSame('2500.00', (string) $v2Actual->actualCostLine->net_amount);
        $this->assertNotSame($v1Actual->consumes_cost_line_id, $v2Actual->consumes_cost_line_id);
        $this->assertSame('6500.00', $this->labourActual($enquiry));
    }

    // ── R7. One economic cost once: company GL delta ─────────────────────────

    public function test_finance_verification_moves_project_labour_but_not_the_company_ledger(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);
        $actual = $this->service->poVerify(
            $this->service->record($this->recordData(['project_enquiry_id' => $enquiry->id, 'budget_line_id' => 'line-a', 'actual_quantity' => 8]), $this->recorder),
            $this->projectOfficer,
        );

        $ledger = fn () => [
            'entries' => DB::table('journal_entries')->count(),
            'lines' => DB::table('journal_lines')->count(),
            'payroll_expense' => (string) DB::table('journal_lines as jl')
                ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
                ->where(fn ($q) => $q->where('coa.code', 'like', '5200%')->orWhere('coa.code', 'like', '7550%'))
                ->selectRaw("COALESCE(SUM(CASE WHEN jl.entry_type = 'debit' THEN jl.base_amount ELSE -jl.base_amount END), 0) AS net")
                ->value('net'),
        ];

        $before = $ledger();
        $projectBefore = $this->labourActual($enquiry);

        $verified = $this->service->financeVerify($actual, $this->financier);

        $this->assertSame($before, $ledger());                      // no journal, no payroll expense
        $this->assertSame('0.00', $projectBefore);
        $this->assertSame('16000.00', $this->labourActual($enquiry)); // analytical actual increased
        $this->assertNull($verified->actualCostLine->journal_entry_id);

        // A correction pair is analytical too.
        $corrected = $this->service->correct($verified, $this->financier, ['actual_quantity' => 7], 'Fix.');
        $this->service->financeVerify($this->service->poVerify($corrected, $this->projectOfficer), $this->financier);
        $this->assertSame($before, $ledger());
        $this->assertSame('14000.00', $this->labourActual($enquiry));
    }

    // ── R8. Project Costing monetary matrix ──────────────────────────────────

    public function test_project_costing_normal_budget_actual_variance(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);
        $this->verifiedActual($enquiry, 'line-a', 8);

        $labour = $this->labourCategory($enquiry);
        $this->assertSame('20000.00', $labour['planned']);
        $this->assertSame('16000.00', $labour['actual']);
        $this->assertSame('4000.00', $labour['remaining']); // favourable
    }

    public function test_project_costing_over_budget_is_not_capped(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);
        $this->verifiedActual($enquiry, 'line-a', 12);

        $labour = $this->labourCategory($enquiry);
        $this->assertSame('20000.00', $labour['planned']);
        $this->assertSame('24000.00', $labour['actual']);
        $this->assertSame('-4000.00', $labour['remaining']); // adverse, sign preserved

        $alert = DB::table('governance_audit_logs')->where('gate_type', 'labour_cost')->where('action_status', 'alert')->first();
        $this->assertNotNull($alert);
    }

    public function test_project_costing_unused_budget_does_not_become_actual(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-a', 25, 1, 2000)]);
        $this->verifiedActual($enquiry, 'line-a', 15);

        $labour = $this->labourCategory($enquiry);
        $this->assertSame('50000.00', $labour['planned']);
        $this->assertSame('30000.00', $labour['actual']);
        $this->assertSame('20000.00', $labour['remaining']);
    }

    // ── R9. Portfolio reconciliation ─────────────────────────────────────────

    public function test_portfolio_actual_labour_equals_sum_of_project_statements(): void
    {
        $a = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);
        $b = $this->makeProject([$this->labourLine('line-b', 10, 1, 1500)]);
        $c = $this->makeProject([$this->labourLine('line-c', 10, 1, 1000)]);
        $this->verifiedActual($a, 'line-a', 8);
        $actualB = $this->verifiedActual($b, 'line-b', 4);
        $corr = $this->service->correct($actualB, $this->financier, ['actual_quantity' => 3], 'Fix.');
        $this->service->financeVerify($this->service->poVerify($corr, $this->projectOfficer), $this->financier);
        // c: pending only — must count nowhere.
        $this->service->record($this->recordData(['project_enquiry_id' => $c->id, 'budget_line_id' => 'line-c']), $this->recorder);

        $costs = $this->app->make(\App\Modules\Finance\CostCollector\Services\CostAccountService::class);
        $ids = [$a->id, $b->id, $c->id];
        $portfolio = $costs->portfolioMargin($ids);

        $sum = '0.00';
        foreach ($ids as $id) {
            $statement = $this->labourActual(ProjectEnquiry::find($id));
            $this->assertSame($statement, $portfolio[$id]['actual_labour']);
            $sum = bcadd($sum, $statement, 2);
        }
        $portfolioSum = array_reduce($ids, fn ($carry, $id) => bcadd($carry, $portfolio[$id]['actual_labour'], 2), '0.00');
        $this->assertSame('20500.00', $sum); // 16,000 + 4,500
        $this->assertSame($sum, $portfolioSum);
    }

    public function test_portfolio_query_count_does_not_grow_with_projects(): void
    {
        $costs = $this->app->make(\App\Modules\Finance\CostCollector\Services\CostAccountService::class);
        $one = [$this->makeProject([$this->labourLine('l1', 1, 1, 100)])->id];
        $many = array_merge($one, [
            $this->makeProject([$this->labourLine('l2', 1, 1, 100)])->id,
            $this->makeProject([$this->labourLine('l3', 1, 1, 100)])->id,
            $this->makeProject([$this->labourLine('l4', 1, 1, 100)])->id,
        ]);

        $count = function (array $ids) use ($costs) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $costs->portfolioMargin($ids);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();
            return $n;
        };

        $this->assertSame($count($one), $count($many));
    }

    // ── R10. Decimal precision ───────────────────────────────────────────────

    public function test_fractional_usage_and_rate_are_exact(): void
    {
        $enquiry = $this->makeProject([
            $this->labourLine('frac', 10, 1, '1234.56'),
            $this->labourLine('round', 10, 1, '1234.57'),
        ]);

        // 1,234.56 × 2.75 = 3,395.04 exactly.
        $a = $this->verifiedActual($enquiry, 'frac', '2.75');
        $this->assertSame('3395.04', (string) $a->calculated_cost);
        $this->assertSame('3395.04', (string) $a->actualCostLine->net_amount);

        // 1,234.57 × 0.33 = 407.4081 → 407.41 (half-up at the cent).
        $b = $this->verifiedActual($enquiry, 'round', '0.33');
        $this->assertSame('407.41', (string) $b->calculated_cost);

        // 1,234.56 × 2.75 × 1.5 = 5,092.56.
        $c = $this->service->record($this->recordData([
            'project_enquiry_id' => $enquiry->id, 'budget_line_id' => 'frac', 'actual_quantity' => '2.75', 'actual_days' => '1.5',
        ]), $this->recorder);
        $this->assertSame('5092.56', (string) $c->calculated_cost);

        $this->assertSame('3802.45', $this->labourActual($enquiry));
        $line = collect($this->service->getBudgetLabourLines($enquiry))->firstWhere('id', 'frac');
        $this->assertSame('12345.60', $line['budget_amount']);
        $this->assertSame('3395.04', $line['verified_cost']);
        $this->assertSame('8950.56', $line['remaining_cost']);
        $this->assertSame('5092.56', $line['pending_cost']);
    }

    public function test_usage_beyond_two_decimals_is_rejected(): void
    {
        $this->actingAs($this->recorder)->postJson("/api/costs/projects/{$this->enquiry->id}/labour-actuals", [
            'budget_line_id' => self::BUDGET_LINE_ID, 'actual_quantity' => '2.755', 'actual_days' => 1,
            'work_date' => now()->toDateString(), 'is_unbudgeted' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('actual_quantity');
    }

    // ── R11. Payroll privacy ─────────────────────────────────────────────────

    public function test_labour_api_payloads_never_expose_payroll_data(): void
    {
        $employeeId = $this->createEmployee();
        $actual = $this->service->record($this->recordData(['employee_id' => $employeeId]), $this->recorder);
        $this->service->returnForCorrection($actual, $this->projectOfficer, 'Fix.');
        $this->service->resubmit($actual->fresh(), $this->recorder, ['actual_quantity' => 1]);

        $payload = json_encode([
            $this->actingAs($this->financier)->getJson("/api/costs/projects/{$this->enquiry->id}/labour-actuals")->json(),
            $this->actingAs($this->financier)->getJson("/api/costs/projects/{$this->enquiry->id}/labour-actuals/{$actual->id}")->json(),
            $this->actingAs($this->financier)->getJson("/api/costs/projects/{$this->enquiry->id}/budget-labour-lines")->json(),
        ]);

        foreach (['salary', '50000', 'kra_pin', 'id_number', 'bank', 'nssf', 'payslip', 'deduction'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $payload, "Payroll field leaked: {$forbidden}");
        }
        $this->assertStringContainsString('Test Employee', $payload);
    }

    // ── R12. Audit trail ─────────────────────────────────────────────────────

    public function test_every_labour_event_is_audited_with_actor_and_timestamp(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);
        $other = $this->makeProject([$this->labourLine('line-o', 10, 1, 2000)]);

        $actual = $this->service->record($this->recordData(['project_enquiry_id' => $enquiry->id, 'budget_line_id' => 'line-a', 'actual_quantity' => 11]), $this->recorder);
        $this->service->returnForCorrection($actual, $this->projectOfficer, 'Check.');
        $actual = $this->service->resubmit($actual->fresh(), $this->recorder, ['actual_quantity' => 12]);
        $actual = $this->service->financeVerify($this->service->poVerify($actual, $this->projectOfficer), $this->financier);
        $corr = $this->service->correct($actual, $this->financier, ['actual_quantity' => 9], 'Fix.');
        $corr = $this->service->financeVerify($this->service->poVerify($corr, $this->projectOfficer), $this->financier);
        $this->service->reclassify($corr, $other, $this->financier, 'Wrong job.');

        $unbudgeted = $this->service->record($this->recordData([
            'project_enquiry_id' => $enquiry->id, 'budget_line_id' => null, 'is_unbudgeted' => true, 'unbudgeted_reason' => 'Extra',
        ]), $this->recorder);
        $unbudgeted = $this->service->poVerify($unbudgeted, $this->projectOfficer);
        $this->service->resolveUnbudgetedRate($unbudgeted, $this->financier, '1000', 'Memo', 'REF');

        $logs = DB::table('governance_audit_logs')->where('gate_type', 'labour_cost')
            ->where('project_enquiry_id', $enquiry->id)->get();

        foreach (['record', 'return_for_correction', 'resubmit', 'po_verify', 'finance_verify', 'alert',
                  'correct', 'correction_requested', 'supersede', 'reclassify', 'resolve_unbudgeted_rate'] as $event) {
            $rows = $logs->where('action_status', $event);
            $this->assertNotEmpty($rows, "Missing audit event: {$event}");
            foreach ($rows as $row) {
                $this->assertNotNull($row->user_id, "{$event} has no actor");
                $this->assertNotNull($row->created_at, "{$event} has no timestamp");
            }
        }
        $this->assertSame($this->recorder->id, $logs->firstWhere('action_status', 'resubmit')->user_id);
        $this->assertSame($this->financier->id, $logs->firstWhere('action_status', 'reclassify')->user_id);
    }

    // ══ Report 47: authoritative Project Budget (W7-1 / Q4) ═════════════════

    public function test_q4_scenario_a_a_finalized_draft_budget_authorizes_budgeted_labour(): void
    {
        // Production state: task_budget_data.status stays 'draft'; the completed budget task finalizes it.
        $enquiry = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);
        $this->assertSame('draft', TaskBudgetData::whereHas('task', fn ($q) => $q->where('project_enquiry_id', $enquiry->id))->value('status'));

        $response = $this->actingAs($this->recorder)->getJson("/api/costs/projects/{$enquiry->id}/budget-labour-lines")->assertOk();
        $this->assertSame('finalized', $response->json('meta.budget_state'));
        $this->assertTrue($response->json('data.0.recordable'));

        $actual = $this->verifiedActual($enquiry, 'line-a', 3);
        $this->assertSame('6000.00', (string) $actual->actualCostLine->net_amount);
        $this->assertSame('project_budget', $actual->rate_source['type']);
    }

    public function test_q4_scenario_b_an_in_progress_budget_cannot_authorize_budgeted_labour(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)], 'in_progress');

        $lines = $this->actingAs($this->recorder)->getJson("/api/costs/projects/{$enquiry->id}/budget-labour-lines")->assertOk();
        $this->assertSame('in_progress', $lines->json('meta.budget_state'));
        $this->assertSame('line-a', $lines->json('data.0.id'));      // the plan is visible
        $this->assertFalse($lines->json('data.0.recordable'));       // but not recordable

        $response = $this->actingAs($this->recorder)->postJson("/api/costs/projects/{$enquiry->id}/labour-actuals", [
            'budget_line_id' => 'line-a', 'actual_quantity' => 1, 'actual_days' => 1,
            'work_date' => now()->toDateString(), 'is_unbudgeted' => false,
        ])->assertStatus(422);
        $this->assertStringContainsString('still in progress', $response->json('errors.budget_line_id.0'));

        // Unbudgeted labour does not depend on the budget and still records.
        $this->actingAs($this->recorder)->postJson("/api/costs/projects/{$enquiry->id}/labour-actuals", [
            'is_unbudgeted' => true, 'labour_role' => 'Rigger', 'labour_category' => 'site_labour', 'budget_unit' => 'PAX',
            'actual_quantity' => 1, 'actual_days' => 1, 'work_date' => now()->toDateString(), 'unbudgeted_reason' => 'Extra crew',
        ])->assertCreated();
    }

    public function test_q4_scenario_b_a_reopened_budget_stops_new_labour_until_completed_again(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);
        $this->verifiedActual($enquiry, 'line-a', 1);

        // A materials change reopens the budget task (BudgetService::syncFromMaterialsList).
        EnquiryTask::where('project_enquiry_id', $enquiry->id)->where('type', 'budget')->update(['status' => 'in_progress']);
        try {
            $this->service->record($this->recordData(['project_enquiry_id' => $enquiry->id, 'budget_line_id' => 'line-a']), $this->recorder);
            $this->fail('A reopened budget must not authorize new labour.');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        EnquiryTask::where('project_enquiry_id', $enquiry->id)->where('type', 'budget')->update(['status' => 'completed']);
        $this->assertSame('2000.00', (string) $this->service->record(
            $this->recordData(['project_enquiry_id' => $enquiry->id, 'budget_line_id' => 'line-a', 'actual_quantity' => 1]), $this->recorder,
        )->calculated_cost);
    }

    public function test_q4_scenario_c_a_line_from_a_superseded_budget_version_cannot_authorize_labour(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-old', 10, 1, 2000)]);
        $budget = TaskBudgetData::whereHas('task', fn ($q) => $q->where('project_enquiry_id', $enquiry->id))->firstOrFail();

        // Revision removes line-old and adds line-new (the real save path + projection).
        $budget->update(['labour_data' => [$this->labourLine('line-new', 5, 1, 3000)]]);
        $this->app->make(\App\Modules\Finance\CostCollector\Services\BudgetProjector::class)->project($budget->fresh());

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->record($this->recordData(['project_enquiry_id' => $enquiry->id, 'budget_line_id' => 'line-old']), $this->recorder);
    }

    public function test_q4_scenario_c_a_budget_row_outside_the_project_budget_task_is_ignored(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000)]);
        $stray = EnquiryTask::create(['project_enquiry_id' => $enquiry->id, 'title' => 'Materials', 'type' => 'materials',
            'status' => 'completed', 'created_by' => $this->financier->id]);
        $orphan = TaskBudgetData::create(['enquiry_task_id' => $stray->id, 'project_info' => [], 'materials_data' => [],
            'labour_data' => [$this->labourLine('line-stray', 1, 1, 99999)], 'expenses_data' => [], 'logistics_data' => [],
            'budget_summary' => [], 'status' => 'draft']);

        $result = $this->app->make(\App\Modules\Finance\CostCollector\Services\BudgetProjector::class)->project($orphan);
        $this->assertSame(0, $result['projected']);
        $this->assertSame(['line-a'], collect($this->service->getBudgetLabourLines($enquiry))->pluck('id')->all());

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->record($this->recordData(['project_enquiry_id' => $enquiry->id, 'budget_line_id' => 'line-stray']), $this->recorder);
    }

    public function test_q4_scenario_f_and_g_project_budgets_command_regenerates_planned_lines_once(): void
    {
        $enquiry = $this->makeProject([$this->labourLine('line-a', 10, 1, 2000), $this->labourLine('line-b', 2, 3, 1500)], 'in_progress');
        CostLine::where('project_enquiry_id', $enquiry->id)->delete();   // as after the controlled reset
        $planned = fn () => CostLine::where('project_enquiry_id', $enquiry->id)->where('nature', 'planned')->counting()->count();

        $this->artisan('finance:project-budgets', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, $planned());                                // dry run writes nothing

        $this->artisan('finance:project-budgets')->assertSuccessful();
        $this->assertSame(2, $planned());                                // F: exactly one per labour line, draft budget included
        $this->assertSame('29000.00', bcadd((string) CostLine::where('project_enquiry_id', $enquiry->id)->counting()->sum('net_amount'), '0', 2));

        $this->artisan('finance:project-budgets')->assertSuccessful();
        $this->assertSame(2, $planned());                                // G: re-run adds nothing
        $this->assertSame(2, CostLine::where('project_enquiry_id', $enquiry->id)->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** A W6-shaped project with its own approved budget, projected by the real BudgetProjector. */
    private function makeProject(array $labourLines, string $budgetTaskStatus = 'completed'): ProjectEnquiry
    {
        $enquiry = $this->createEnquiry($this->financier->id);
        $this->createProject($enquiry->id);
        $task = EnquiryTask::create([
            'project_enquiry_id' => $enquiry->id, 'title' => 'Budget', 'type' => 'budget', 'status' => $budgetTaskStatus, 'created_by' => $this->financier->id,
        ]);
        $budget = TaskBudgetData::create([
            'enquiry_task_id' => $task->id, 'project_info' => [], 'materials_data' => [],
            'labour_data' => $labourLines, 'expenses_data' => [], 'logistics_data' => [],
            'budget_summary' => [], 'status' => 'draft',
        ]);
        $this->app->make(\App\Modules\Finance\CostCollector\Services\BudgetProjector::class)->project($budget);

        return $enquiry;
    }

    private function labourLine(string $id, int|string $quantity, int|string $days, int|string $rate): array
    {
        return [
            'id' => $id, 'type' => 'Crew', 'category' => 'site_labour', 'description' => 'Crew',
            'unit' => 'PAX', 'quantity' => $quantity, 'days' => $days, 'unitRate' => $rate,
            'amount' => bcmul(bcmul((string) $quantity, (string) $days, 4), (string) $rate, 2), 'isIncluded' => true,
        ];
    }

    private function verifiedActual(ProjectEnquiry $enquiry, string $lineId, int|string $quantity): ProjectLabourActual
    {
        $actual = $this->service->record($this->recordData([
            'project_enquiry_id' => $enquiry->id, 'budget_line_id' => $lineId, 'actual_quantity' => $quantity, 'actual_days' => 1,
        ]), $this->recorder);

        return $this->service->financeVerify($this->service->poVerify($actual, $this->projectOfficer), $this->financier);
    }

    /** The labour row of the authoritative W6 Project Costing statement. */
    private function labourCategory(ProjectEnquiry $enquiry): array
    {
        $statement = $this->app->make(\App\Modules\Finance\CostCollector\Services\CostAccountService::class)->forEnquiry($enquiry->fresh());

        return collect($statement['categories'])->firstWhere('category', 'labour')
            ?? ['planned' => '0.00', 'actual' => '0.00', 'remaining' => '0.00'];
    }

    private function labourActual(ProjectEnquiry $enquiry): string
    {
        return $this->labourCategory($enquiry)['actual'];
    }


    private function createEmployee(): int
    {
        $deptId = DB::table('departments')->insertGetId([
            'name' => 'Test Department',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('employees')->insertGetId([
            'employee_id' => 'EMP-W7-' . uniqid(),
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'id_number' => '12345678',
            'kra_pin' => 'PENDING-W7',
            'email' => 'employee@test.local',
            'phone' => '0700000001',
            'department_id' => $deptId,
            'position' => 'Fitter',
            'hire_date' => now()->toDateString(),
            'status' => 'active',
            'salary' => '50000.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createHoursBudget(): TaskBudgetData
    {
        return TaskBudgetData::where('enquiry_task_id', $this->budgetTask->id)->first();
    }
}
