<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\TaskBudgetData;
use App\Models\TaskMaterialsData;
use App\Models\TaskProcurementData;
use App\Models\TaskQuoteData;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\ProcurementStores\Models\Supplier;
use App\Modules\Projects\Actions\CompleteProjectAction;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\Projects\Services\ProjectWorkflowStateService;
use App\Services\Governance\ProjectGovernanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use App\Constants\Permissions;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use Spatie\Permission\Models\Permission;

class ProjectWorkflowContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Approving a purchase order posts a commitment, and a cost cannot be
        // recorded into a month that does not exist. Production seeds a generous
        // calendar; without it here the workflow this suite walks fails inside a
        // listener, several layers from the endpoint under test.
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
    }

    public function test_external_financial_task_is_blocked_without_required_deposit(): void
    {
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_AWAITING_DEPOSIT,
            'workflow_preset_type' => 'external_project',
            'client_approved_quote' => 1000,
        ]);
        $task = $this->task($enquiry, 'procurement');

        // The deposit gate moved off individual tasks and onto completing the
        // project: work may start on an under-funded job, it may not be closed
        // out as delivered. Evaluating the task therefore authorizes.
        $this->assertTrue(
            app(ProjectGovernanceService::class)->evaluateTask($task)->isAuthorized(),
            'Task-level evaluation should no longer apply the finance gate.',
        );

        // The gate itself still refuses, where CompleteProjectAction consults it.
        $result = app(ProjectGovernanceService::class)->checkGate($enquiry, 'financial', [
            'task_id' => $task->id,
            'task_type' => $task->type,
        ]);

        $this->assertFalse($result->isAuthorized());
        $this->assertStringContainsString('Financial Gate Locked', $result->getMessage());
        $this->assertSame($task->id, $result->context['task_id']);
        $this->assertSame('procurement', $result->context['task_type']);
        $this->assertSame(0.0, $result->context['current_percentage']);
    }

    public function test_internal_and_sponsorship_projects_bypass_finance_gate(): void
    {
        foreach (['internal_job', 'sponsorship'] as $preset) {
            $enquiry = $this->enquiry([
                'status' => EnquiryConstants::STATUS_AWAITING_DEPOSIT,
                'workflow_preset_type' => $preset,
                'client_approved_quote' => 1000,
            ]);
            $result = app(ProjectGovernanceService::class)->checkGate($enquiry, 'financial');

            $this->assertTrue($result->isAuthorized(), "Expected {$preset} to bypass finance gate.");
            // Named, not merely allowed: a project that passes because nobody
            // owes anything must be distinguishable from one that passed because
            // the deposit landed.
            $this->assertSame('Internal/Sponsorship Bypass', $result->context['exemption']);
        }
    }

    public function test_workflow_dependencies_follow_quote_approval_to_materials_to_internal_budget(): void
    {
        $dependencies = config('enquiry_workflow.task_dependencies');
        $fullEventTasks = config('enquiry_workflow.task_presets.full_event.tasks');

        $this->assertSame(['design'], $dependencies['quote']);
        $this->assertSame(['quote'], $dependencies['quote_approval']);
        $this->assertSame(['quote_approval'], $dependencies['materials']);
        $this->assertSame(['materials'], $dependencies['budget']);
        $this->assertLessThan(array_search('materials', $fullEventTasks, true), array_search('quote_approval', $fullEventTasks, true));
        $this->assertLessThan(array_search('budget', $fullEventTasks, true), array_search('materials', $fullEventTasks, true));
        $this->assertLessThan(array_search('procurement', $fullEventTasks, true), array_search('budget', $fullEventTasks, true));
    }

    public function test_workflow_state_blocks_materials_and_budget_until_approved_quote_scope_exists(): void
    {
        $manager = $this->user('Project Manager');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_PLANNING,
            'workflow_preset_type' => 'external_project',
            'client_approved_quote' => 1000,
            'project_officer_id' => $manager->id,
            'created_by' => $manager->id,
            'selected_workflow_tasks' => ['site-survey', 'design', 'quote', 'quote_approval', 'materials', 'budget', 'procurement'],
        ]);

        $this->task($enquiry, 'site-survey', ['title' => 'Site Survey', 'status' => 'completed', 'task_order' => 1]);
        $this->task($enquiry, 'design', ['title' => 'Design', 'status' => 'completed', 'task_order' => 2]);
        $this->task($enquiry, 'quote', ['title' => 'Client Quote', 'task_order' => 3]);
        $this->task($enquiry, 'quote_approval', ['title' => 'Client Quote Approval', 'task_order' => 4]);
        $this->task($enquiry, 'materials', ['title' => 'Materials List', 'task_order' => 5]);
        $this->task($enquiry, 'budget', ['title' => 'Internal Budget', 'task_order' => 6]);
        $this->task($enquiry, 'procurement', ['title' => 'Procurement', 'task_order' => 7]);

        $state = app(ProjectWorkflowStateService::class)->forEnquiry($enquiry->fresh());
        $tasks = collect($state['tasks'])->keyBy('type');

        $this->assertFalse($tasks['quote']['is_blocked']);
        $this->assertTrue($tasks['quote_approval']['is_blocked']);
        $this->assertSame(['Client Quote'], $tasks['quote_approval']['blocked_by']);
        $this->assertTrue($tasks['materials']['is_blocked']);
        $this->assertSame(['Client Quote Approval'], $tasks['materials']['blocked_by']);
        $this->assertTrue($tasks['budget']['is_blocked']);
        $this->assertSame(['Materials List'], $tasks['budget']['blocked_by']);
        $this->assertTrue($tasks['procurement']['is_blocked']);
        $this->assertSame(['Client Quote Approval', 'Materials List', 'Internal Budget'], $tasks['procurement']['blocked_by']);
    }

    public function test_project_completion_syncs_enquiry_and_project_records(): void
    {
        $user = $this->user();
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_IN_PROGRESS,
            'selected_workflow_tasks' => ['report'],
            'created_by' => $user->id,
        ]);
        $this->task($enquiry, 'report', ['status' => 'completed', 'created_by' => $user->id]);

        $project = Project::create([
            'enquiry_id' => $enquiry->id,
            'project_id' => 'WNG-TEST-' . uniqid(),
            'status' => 'in_progress',
        ]);

        app(CompleteProjectAction::class)->execute($enquiry, $user->id, 'Done');

        $this->assertSame(EnquiryConstants::STATUS_COMPLETED, $enquiry->fresh()->status);
        $this->assertSame(EnquiryConstants::STATUS_COMPLETED, $project->fresh()->status);
    }

    public function test_closed_project_returns_full_progress_in_enquiry_list_resource(): void
    {
        $manager = $this->user('Project Manager');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_CLOSED,
            'created_by' => $manager->id,
            'project_officer_id' => $manager->id,
        ]);

        Sanctum::actingAs($manager);

        $this->getJson('/api/projects/enquiries?view=closed')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $enquiry->id)
            ->assertJsonPath('data.data.0.status', EnquiryConstants::STATUS_CLOSED)
            ->assertJsonPath('data.data.0.progress_percentage', 100);
    }

    public function test_completed_project_closure_requires_handover_and_report_then_moves_to_closed(): void
    {
        $manager = $this->user('Project Manager');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_COMPLETED,
            'created_by' => $manager->id,
            'project_officer_id' => $manager->id,
            'selected_workflow_tasks' => ['handover', 'report'],
        ]);
        $handover = $this->task($enquiry, 'handover', ['title' => 'Client Handover', 'status' => 'completed']);
        $report = $this->task($enquiry, 'report', ['title' => 'Archival Report', 'status' => 'pending']);
        $project = Project::create([
            'enquiry_id' => $enquiry->id,
            'project_id' => 'WNG-CLOSE-' . uniqid(),
            'status' => 'completed',
        ]);

        Sanctum::actingAs($manager);

        $this->getJson("/api/projects/enquiries/{$enquiry->id}/closure-readiness")
            ->assertOk()
            ->assertJsonPath('data.can_close', false)
            ->assertJsonPath('data.blocking_closure_tasks.0.id', $report->id)
            ->assertJsonPath('data.blocking_closure_tasks.0.status', 'pending');

        $this->postJson("/api/projects/enquiries/{$enquiry->id}/close")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot close project. Incomplete closure tasks: Archival Report (report).']);

        $report->update(['status' => 'completed']);

        $this->getJson("/api/projects/enquiries/{$enquiry->id}/closure-readiness")
            ->assertOk()
            ->assertJsonPath('data.can_close', true);

        $this->postJson("/api/projects/enquiries/{$enquiry->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', EnquiryConstants::STATUS_CLOSED);

        $this->assertSame(EnquiryConstants::STATUS_CLOSED, $enquiry->fresh()->status);
        $this->assertSame(EnquiryConstants::STATUS_CLOSED, $project->fresh()->status);
    }

    public function test_reassign_endpoint_accepts_legacy_payload_and_syncs_assignment_columns(): void
    {
        $manager = $this->user('Project Manager');
        $oldAssignee = $this->user();
        $newAssignee = $this->user();
        $enquiry = $this->enquiry(['created_by' => $manager->id]);
        $task = $this->task($enquiry, 'design', [
            'assigned_user_id' => $oldAssignee->id,
            'assigned_to' => $oldAssignee->id,
            'assigned_by' => $manager->id,
            'assigned_at' => now(),
        ]);

        Sanctum::actingAs($manager);

        $response = $this->putJson("/api/projects/enquiry-tasks/{$task->id}/reassign", [
            'assigned_user_id' => $newAssignee->id,
            'reason' => 'Balancing workload',
        ]);

        $response->assertOk();
        $task->refresh();

        $this->assertSame($newAssignee->id, $task->assigned_user_id);
        $this->assertSame($newAssignee->id, $task->assigned_to);
        $this->assertDatabaseHas('enquiry_task_user', [
            'enquiry_task_id' => $task->id,
            'user_id' => $newAssignee->id,
        ]);
    }

    public function test_workflow_state_endpoint_returns_finance_gate_and_next_action(): void
    {
        $manager = $this->user('Project Manager');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_AWAITING_DEPOSIT,
            'workflow_preset_type' => 'external_project',
            'client_approved_quote' => 1000,
            'project_officer_id' => $manager->id,
            'created_by' => $manager->id,
            'selected_workflow_tasks' => ['procurement'],
        ]);
        $this->task($enquiry, 'procurement', ['created_by' => $manager->id]);

        Sanctum::actingAs($manager);

        $response = $this->getJson("/api/projects/enquiries/{$enquiry->id}/workflow-state");

        $response->assertOk()
            ->assertJsonPath('data.finance_gate.authorized', false)
            ->assertJsonPath('data.next_action.type', 'resolve_finance_gate')
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.blocked', 1)
            ->assertJsonPath('data.tasks.0.gate.type', 'financial');
    }

    public function test_receivables_view_exposes_canonical_finance_summary(): void
    {
        $accounts = $this->user('Accounts');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_AWAITING_DEPOSIT,
            'quote_approved' => true,
            'job_number' => 'WNG-07-2026-001',
            'client_approved_quote' => 1000,
            'created_by' => $accounts->id,
        ]);

        $enquiry->payments()->create([
            'amount' => 650,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'transaction_reference' => 'BANK-650',
            'recorded_by' => $accounts->id,
        ]);

        Sanctum::actingAs($accounts);

        $response = $this->getJson('/api/projects/enquiries?view=receivables&per_page=10');

        $response->assertOk()
            ->assertJsonPath('data.data.0.id', $enquiry->id)
            ->assertJsonPath('data.data.0.payment_progress_percentage', 65)
            ->assertJsonPath('data.data.0.payment_total_quote', 1000)
            ->assertJsonPath('data.data.0.payment_total_paid', 650)
            ->assertJsonPath('data.data.0.payment_remaining', 350)
            ->assertJsonPath('data.data.0.payment_threshold_amount', 700)
            ->assertJsonPath('data.data.0.payment_amount_required_for_threshold', 50)
            ->assertJsonPath('data.data.0.finance_summary.quote_basis', 'client_approved_quote');
    }

    public function test_finance_release_persists_release_flags_for_project_workflow(): void
    {
        $accounts = $this->user('Accounts');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_AWAITING_DEPOSIT,
            'workflow_preset_type' => 'external_project',
            'quote_approved' => true,
            'job_number' => 'WNG-07-2026-002',
            'client_approved_quote' => 1000,
            'created_by' => $accounts->id,
            'project_officer_id' => $accounts->id,
            'selected_workflow_tasks' => ['procurement'],
        ]);
        $this->task($enquiry, 'procurement', ['created_by' => $accounts->id]);

        $enquiry->payments()->create([
            'amount' => 700,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'transaction_reference' => 'BANK-700',
            'recorded_by' => $accounts->id,
        ]);

        Sanctum::actingAs($accounts);

        $this->postJson("/api/projects/enquiries/{$enquiry->id}/release")
            ->assertOk()
            ->assertJsonPath('data.finance_released', true);

        $this->assertTrue((bool) $enquiry->fresh()->finance_released);
        $this->assertNotNull($enquiry->fresh()->finance_released_at);

        $this->getJson("/api/projects/enquiries/{$enquiry->id}/workflow-state")
            ->assertOk()
            ->assertJsonPath('data.finance_gate.is_released', true)
            ->assertJsonPath('data.finance_gate.progress.finance_released', true);
    }

    public function test_threshold_payment_automatically_releases_project(): void
    {
        $accounts = $this->user('Accounts');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_AWAITING_DEPOSIT,
            'workflow_preset_type' => 'external_project',
            'quote_approved' => true,
            'job_number' => 'WNG-07-2026-003',
            'client_approved_quote' => 1000,
            'created_by' => $accounts->id,
            'project_officer_id' => $accounts->id,
            'selected_workflow_tasks' => ['procurement'],
        ]);
        $this->task($enquiry, 'procurement', ['created_by' => $accounts->id]);

        Sanctum::actingAs($accounts);

        $this->postJson("/api/projects/enquiries/{$enquiry->id}/payments", [
            'amount' => 700,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'transaction_reference' => 'AUTO-RELEASE-700',
        ])->assertOk()
            ->assertJsonPath('auto_released', true)
            ->assertJsonPath('progress.finance_released', true);

        $this->assertTrue((bool) $enquiry->fresh()->finance_released);
    }

    public function test_materials_import_uses_approved_quote_snapshot_instead_of_live_quote_data(): void
    {
        $manager = $this->user('Project Manager');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_PLANNING,
            'workflow_preset_type' => 'external_project',
            'project_officer_id' => $manager->id,
            'created_by' => $manager->id,
            'selected_workflow_tasks' => ['quote', 'quote_approval', 'materials'],
        ]);

        $quoteTask = $this->task($enquiry, 'quote', ['status' => 'completed', 'task_order' => 1]);
        $approvalTask = $this->task($enquiry, 'quote_approval', ['status' => 'completed', 'task_order' => 2]);
        $materialsTask = $this->task($enquiry, 'materials', ['status' => 'pending', 'task_order' => 3]);

        $quoteData = TaskQuoteData::create([
            'enquiry_task_id' => $quoteTask->id,
            'project_info' => [],
            'materials' => [
                [
                    'id' => 'live-stage',
                    'name' => 'Live Draft Stage',
                    'materials' => [
                        ['id' => 'live-line', 'description' => 'LIVE DRAFT MATERIAL', 'unitOfMeasurement' => 'pcs', 'quantity' => 99],
                    ],
                ],
            ],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
            'margins' => [],
            'totals' => [],
            'status' => 'approved',
            'approval_status' => 'approved',
        ]);

        DB::table('quote_approvals')->insert([
            'task_id' => $approvalTask->id,
            'enquiry_id' => $enquiry->id,
            'approval_status' => 'approved',
            'approved_by' => $manager->name,
            'approval_date' => now()->toDateString(),
            'comments' => 'Approved by client',
            'quote_amount' => 60000,
            'quote_data' => json_encode([
                'id' => $quoteData->id,
                'materials' => [
                    [
                        'id' => 'approved-stage',
                        'templateId' => 'production',
                        'name' => 'Approved Stage',
                        'description' => 'Client approved staging',
                        'materials' => [
                            [
                                'id' => 'approved-line',
                                'description' => 'Approved Truss',
                                'unitOfMeasurement' => 'm',
                                'quantity' => 12,
                                'unitPrice' => 5000,
                                'isVisible' => true,
                            ],
                        ],
                        'finalTotal' => 60000,
                    ],
                ],
                'totals' => ['grandTotal' => 60000],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($manager);

        $response = $this->postJson("/api/projects/tasks/{$materialsTask->id}/materials/import-approved-quote", [
            'selectedElementIds' => ['approved-stage'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.projectElements.0.name', 'Approved Stage')
            ->assertJsonPath('data.projectElements.0.materials.0.description', 'Approved Truss')
            ->assertJsonPath('data.projectElements.0.materials.0.unitCost', null)
            ->assertJsonPath('data.projectInfo.quoteImportedFrom.snapshotSource', 'quote_approvals')
            ->assertJsonPath('data.projectInfo.quoteImportedFrom.approvalTaskId', $approvalTask->id);

        $this->assertDatabaseHas('project_elements', ['name' => 'Approved Stage']);
        $this->assertDatabaseHas('element_materials', ['description' => 'Approved Truss']);
        $this->assertDatabaseMissing('element_materials', ['description' => 'LIVE DRAFT MATERIAL']);
    }

    public function test_materials_import_requires_an_approved_quote_snapshot(): void
    {
        $manager = $this->user('Project Manager');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_PLANNING,
            'workflow_preset_type' => 'external_project',
            'project_officer_id' => $manager->id,
            'created_by' => $manager->id,
            'selected_workflow_tasks' => ['quote', 'quote_approval', 'materials'],
        ]);

        $this->task($enquiry, 'quote', ['status' => 'completed', 'task_order' => 1]);
        $this->task($enquiry, 'quote_approval', ['status' => 'pending', 'task_order' => 2]);
        $materialsTask = $this->task($enquiry, 'materials', ['status' => 'pending', 'task_order' => 3]);

        Sanctum::actingAs($manager);

        $response = $this->getJson("/api/projects/tasks/{$materialsTask->id}/materials/approved-quote-preview");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'No approved quote snapshot found. Complete the Quote Approval task before generating the materials list.');
    }

    public function test_budget_import_uses_approved_material_identity_and_preserves_internal_rates(): void
    {
        $manager = $this->user('Project Manager');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_PLANNING,
            'workflow_preset_type' => 'external_project',
            'project_officer_id' => $manager->id,
            'created_by' => $manager->id,
            'selected_workflow_tasks' => ['materials', 'budget'],
        ]);

        $materialsTask = $this->task($enquiry, 'materials', ['status' => 'completed', 'task_order' => 1]);
        $budgetTask = $this->task($enquiry, 'budget', ['status' => 'pending', 'task_order' => 2]);
        $elementPersistentId = (string) Str::uuid();
        $materialPersistentId = (string) Str::uuid();

        $materialsData = TaskMaterialsData::create([
            'enquiry_task_id' => $materialsTask->id,
            'project_info' => [
                'approval_status' => [
                    'project_officer' => ['approved' => true],
                    'production' => ['approved' => true],
                    'all_approved' => true,
                ],
                'quoteImportedFrom' => [
                    'snapshotSource' => 'quote_approvals',
                    'approvalTaskId' => 999,
                ],
            ],
        ]);

        $element = $materialsData->elements()->create([
            'persistent_id' => $elementPersistentId,
            'element_type' => 'production',
            'name' => 'Approved Stage',
            'category' => 'production',
            'dimensions' => [],
            'is_included' => true,
            'sort_order' => 1,
        ]);

        $element->materials()->create([
            'persistent_id' => $materialPersistentId,
            'description' => 'Approved Truss Renamed',
            'unit_of_measurement' => 'm',
            'quantity' => 7,
            'unit_cost' => null,
            'is_included' => true,
            'is_additional' => false,
            'sort_order' => 1,
        ]);

        TaskBudgetData::create([
            'enquiry_task_id' => $budgetTask->id,
            'project_info' => ['projectId' => $enquiry->enquiry_number],
            'materials_data' => [
                [
                    'id' => $elementPersistentId,
                    'persistent_id' => $elementPersistentId,
                    'name' => 'Approved Stage',
                    'category' => 'production',
                    'materials' => [
                        [
                            'id' => $materialPersistentId,
                            'persistent_id' => $materialPersistentId,
                            'description' => 'Old Truss Label',
                            'unitOfMeasurement' => 'm',
                            'quantity' => 5,
                            'unitPrice' => 321,
                            'totalPrice' => 1605,
                            'isIncluded' => true,
                        ],
                    ],
                ],
            ],
            'labour_data' => [],
            'expenses_data' => [],
            'logistics_data' => [],
            'budget_summary' => ['materialsTotal' => 1605, 'labourTotal' => 0, 'expensesTotal' => 0, 'logisticsTotal' => 0, 'grandTotal' => 1605],
            'status' => 'draft',
            'materials_imported_at' => now()->subHour(),
            'materials_imported_from_task' => $materialsTask->id,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->postJson("/api/projects/tasks/{$budgetTask->id}/budget/import-materials");

        $response->assertOk()
            ->assertJsonPath('data.materials.0.id', $elementPersistentId)
            ->assertJsonPath('data.materials.0.materials.0.id', $materialPersistentId)
            ->assertJsonPath('data.materials.0.materials.0.description', 'Approved Truss Renamed')
            ->assertJsonPath('data.materials.0.materials.0.quantity', '7.00')
            ->assertJsonPath('data.materials.0.materials.0.unitPrice', 321)
            // Quantity always comes from the materials list and the rate is the
            // budget's to keep, so the total is recomputed from both. The
            // `_priceStatus` / `_quantityChanged` markers went with the reconciler
            // that needed them: the two lists can no longer disagree about
            // quantity, so there is no divergence left to flag.
            ->assertJsonPath('data.materials.0.materials.0.totalPrice', 2247)
            // The stamp procurement's readiness gate reads, returned by the
            // import that wrote it. `quote_imported_from` went when the quote
            // snapshot stopped feeding this path: the budget's materials come
            // from the materials list, and nothing writes that key any more.
            ->assertJsonPath('data.materialsImportInfo.importMetadata.source', 'approved_materials_list')
            ->assertJsonPath('data.materialsImportInfo.importMetadata.materials_task_id', $materialsTask->id);
    }

    public function test_procurement_import_requires_budget_source_to_be_approved_materials_list(): void
    {
        $manager = $this->user('Project Manager');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_PLANNING,
            'workflow_preset_type' => 'external_project',
            'project_officer_id' => $manager->id,
            'created_by' => $manager->id,
            'selected_workflow_tasks' => ['budget', 'procurement'],
        ]);

        $budgetTask = $this->task($enquiry, 'budget', ['status' => 'completed', 'task_order' => 1]);
        $procurementTask = $this->task($enquiry, 'procurement', ['status' => 'pending', 'task_order' => 2]);

        TaskBudgetData::create([
            'enquiry_task_id' => $budgetTask->id,
            'project_info' => ['projectId' => $enquiry->enquiry_number],
            'materials_data' => [
                [
                    'id' => 'manual-element',
                    'name' => 'Manual Stage',
                    'materials' => [
                        [
                            'id' => 'manual-line',
                            'description' => 'Manual Truss',
                            'unitOfMeasurement' => 'm',
                            'quantity' => 5,
                            'unitPrice' => 100,
                            'totalPrice' => 500,
                            'isIncluded' => true,
                        ],
                    ],
                    'isIncluded' => true,
                ],
            ],
            'labour_data' => [],
            'expenses_data' => [],
            'logistics_data' => [],
            'budget_summary' => ['materialsTotal' => 500, 'labourTotal' => 0, 'expensesTotal' => 0, 'logisticsTotal' => 0, 'grandTotal' => 500],
            'status' => 'approved',
            'materials_imported_at' => now(),
            'materials_import_metadata' => ['source' => 'manual_budget'],
        ]);

        Sanctum::actingAs($manager);

        $response = $this->postJson("/api/projects/tasks/{$procurementTask->id}/procurement/import-budget");

        // The refusal names the origin it found, which is the thing the reader
        // has to change; the earlier wording only said the source was wrong.
        $response->assertStatus(409);
        $this->assertStringContainsString(
            "this budget was built from 'manual_budget'",
            $response->json('message'),
        );

        $this->assertDatabaseMissing('task_procurement_data', [
            'enquiry_task_id' => $procurementTask->id,
        ]);
    }

    public function test_procurement_sync_preserves_operational_fields_by_budget_material_identity(): void
    {
        $manager = $this->user('Project Manager');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_PLANNING,
            'workflow_preset_type' => 'external_project',
            'project_officer_id' => $manager->id,
            'created_by' => $manager->id,
            'selected_workflow_tasks' => ['materials', 'budget', 'procurement'],
        ]);

        $materialsTask = $this->task($enquiry, 'materials', ['status' => 'completed', 'task_order' => 1]);
        $budgetTask = $this->task($enquiry, 'budget', ['status' => 'completed', 'task_order' => 2]);
        $procurementTask = $this->task($enquiry, 'procurement', ['status' => 'pending', 'task_order' => 3]);
        $elementPersistentId = (string) Str::uuid();
        $materialPersistentId = (string) Str::uuid();

        $budgetData = TaskBudgetData::create([
            'enquiry_task_id' => $budgetTask->id,
            'project_info' => ['projectId' => $enquiry->enquiry_number],
            'materials_data' => [
                [
                    'id' => 'element-v1',
                    'persistent_id' => $elementPersistentId,
                    'name' => 'Approved Stage',
                    'category' => 'production',
                    'materials' => [
                        [
                            'id' => 'material-v1',
                            'persistent_id' => $materialPersistentId,
                            'description' => 'Approved Truss',
                            'unitOfMeasurement' => 'm',
                            'quantity' => 5,
                            'unitPrice' => 100,
                            'totalPrice' => 500,
                            'isIncluded' => true,
                        ],
                    ],
                    'isIncluded' => true,
                ],
            ],
            'labour_data' => [],
            'expenses_data' => [],
            'logistics_data' => [],
            'budget_summary' => ['materialsTotal' => 500, 'labourTotal' => 0, 'expensesTotal' => 0, 'logisticsTotal' => 0, 'grandTotal' => 500],
            'status' => 'approved',
            'materials_imported_at' => now(),
            'materials_imported_from_task' => $materialsTask->id,
            'materials_import_metadata' => [
                'source' => 'approved_materials_list',
                'materials_task_id' => $materialsTask->id,
            ],
        ]);

        Sanctum::actingAs($manager);

        $importResponse = $this->postJson("/api/projects/tasks/{$procurementTask->id}/procurement/import-budget");

        $importResponse->assertOk()
            ->assertJsonPath('data.procurementItems.0.budgetItemId', 'material-v1')
            ->assertJsonPath('data.procurementItems.0.budgetItemPersistentId', $materialPersistentId)
            ->assertJsonPath('data.budgetSummary.source', 'approved_internal_budget')
            ->assertJsonPath('data.budgetSummary.budgetImportMetadata.source', 'approved_materials_list');

        $procurementData = TaskProcurementData::where('enquiry_task_id', $procurementTask->id)->firstOrFail();
        $items = $procurementData->procurement_items;
        $items[0]['vendorName'] = 'Steel Vendor Ltd';
        $items[0]['stockStatus'] = 'partial_stock';
        $items[0]['stockQuantity'] = 2;
        $items[0]['procurementStatus'] = 'pending';
        $items[0]['purchaseQuantity'] = 3;
        $items[0]['purchaseOrderNumber'] = 'PO-1001';

        $procurementData->update([
            'procurement_items' => $items,
        ]);

        $budgetData->update([
            'materials_data' => [
                [
                    'id' => 'element-v2',
                    'persistent_id' => $elementPersistentId,
                    'name' => 'Approved Stage',
                    'category' => 'production',
                    'materials' => [
                        [
                            'id' => 'material-v2',
                            'persistent_id' => $materialPersistentId,
                            'description' => 'Approved Truss Renamed',
                            'unitOfMeasurement' => 'm',
                            'quantity' => 7,
                            'unitPrice' => 100,
                            'totalPrice' => 700,
                            'isIncluded' => true,
                        ],
                    ],
                    'isIncluded' => true,
                ],
            ],
            'budget_summary' => ['materialsTotal' => 700, 'labourTotal' => 0, 'expensesTotal' => 0, 'logisticsTotal' => 0, 'grandTotal' => 700],
        ]);

        $response = $this->getJson("/api/projects/tasks/{$procurementTask->id}/procurement");

        $response->assertOk()
            ->assertJsonPath('data.procurementItems.0.budgetItemId', 'material-v2')
            ->assertJsonPath('data.procurementItems.0.budgetItemPersistentId', $materialPersistentId)
            ->assertJsonPath('data.procurementItems.0.description', 'Approved Truss Renamed')
            ->assertJsonPath('data.procurementItems.0.quantity', 7)
            ->assertJsonPath('data.procurementItems.0.vendorName', 'Steel Vendor Ltd')
            ->assertJsonPath('data.procurementItems.0.stockStatus', 'partial_stock')
            ->assertJsonPath('data.procurementItems.0.stockQuantity', 2)
            ->assertJsonPath('data.procurementItems.0.purchaseQuantity', 5)
            ->assertJsonPath('data.procurementItems.0.purchaseOrderNumber', 'PO-1001')
            ->assertJsonPath('data.budgetSummary.materialsTotal', 700)
            ->assertJsonPath('data.budgetSummary.source', 'approved_internal_budget');
    }

    public function test_procurement_generated_requisition_persists_project_budget_traceability(): void
    {
        $procurement = $this->user('Procurement');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_PLANNING,
            'workflow_preset_type' => 'external_project',
            'project_officer_id' => $procurement->id,
            'created_by' => $procurement->id,
            'selected_workflow_tasks' => ['budget', 'procurement'],
        ]);

        $budgetTask = $this->task($enquiry, 'budget', ['status' => 'completed', 'task_order' => 1]);
        $procurementTask = $this->task($enquiry, 'procurement', ['status' => 'pending', 'task_order' => 2]);
        $budgetData = TaskBudgetData::create([
            'enquiry_task_id' => $budgetTask->id,
            'project_info' => ['projectId' => $enquiry->enquiry_number],
            'materials_data' => [],
            'labour_data' => [],
            'expenses_data' => [],
            'logistics_data' => [],
            'budget_summary' => ['materialsTotal' => 500, 'labourTotal' => 0, 'expensesTotal' => 0, 'logisticsTotal' => 0, 'grandTotal' => 500],
            'status' => 'approved',
            'materials_imported_at' => now(),
            'materials_import_metadata' => ['source' => 'approved_materials_list'],
        ]);

        Sanctum::actingAs($procurement);

        $response = $this->postJson('/api/procurement-stores/requisitions', [
            'date' => now()->toDateString(),
            'requested_by_type' => 'project',
            'project_id' => $enquiry->id,
            'urgency' => 'normal',
            'job_number' => $enquiry->enquiry_number . ' - ' . $enquiry->title,
            'items' => [
                [
                    'project_enquiry_id' => $enquiry->id,
                    'procurement_task_id' => $procurementTask->id,
                    'budget_data_id' => $budgetData->id,
                    'budget_element_id' => 'element-v2',
                    'budget_element_persistent_id' => 'element-persistent',
                    'budget_item_id' => 'material-v2',
                    'budget_item_persistent_id' => 'material-persistent',
                    'material_id' => null,
                    'expense_code_id' => $this->expenseCode()->id,
                    'custom_description' => 'Approved Truss',
                    'quantity' => 5,
                    'unit_price' => 100,
                    'internal_budget_unit_price' => 100,
                    'purpose' => '[' . $enquiry->enquiry_number . '] Stage - Approved Truss',
                    'reason' => 'Procurement from approved internal budget',
                    'procurement_item_snapshot' => [
                        'requiredQuantity' => 7,
                        'stockQuantity' => 2,
                        'purchaseQuantity' => 5,
                    ],
                ],
            ],
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.project_id', $enquiry->id)
            ->assertJsonPath('data.items.0.project_enquiry_id', $enquiry->id)
            ->assertJsonPath('data.items.0.procurement_task_id', $procurementTask->id)
            ->assertJsonPath('data.items.0.budget_data_id', $budgetData->id)
            ->assertJsonPath('data.items.0.budget_item_persistent_id', 'material-persistent')
            ->assertJsonPath('data.items.0.internal_budget_unit_price', 100);

        $this->assertDatabaseHas('requisition_items', [
            'project_enquiry_id' => $enquiry->id,
            'procurement_task_id' => $procurementTask->id,
            'budget_data_id' => $budgetData->id,
            'budget_item_persistent_id' => 'material-persistent',
            'internal_budget_unit_price' => 100,
        ]);
    }

    public function test_procurement_store_lifecycle_syncs_back_to_project_procurement_task(): void
    {
        $procurement = $this->user('Procurement');
        $accounts = $this->user('Accounts');
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_PLANNING,
            'workflow_preset_type' => 'external_project',
            'project_officer_id' => $procurement->id,
            'created_by' => $procurement->id,
            'selected_workflow_tasks' => ['budget', 'procurement'],
        ]);

        $this->task($enquiry, 'budget', ['status' => 'completed', 'task_order' => 1]);
        $procurementTask = $this->task($enquiry, 'procurement', ['status' => 'pending', 'task_order' => 2]);

        TaskProcurementData::create([
            'enquiry_task_id' => $procurementTask->id,
            'project_info' => ['projectId' => $enquiry->enquiry_number],
            'budget_imported' => true,
            'procurement_items' => [
                [
                    'budgetId' => 'material-v2',
                    'elementName' => 'Stage',
                    'description' => 'Approved Truss',
                    'quantity' => 7,
                    'unitOfMeasurement' => 'm',
                    'budgetUnitPrice' => 100,
                    'budgetTotalPrice' => 700,
                    'category' => 'materials',
                    'vendorName' => '',
                    'availabilityStatus' => 'available',
                    'stockStatus' => 'partial_stock',
                    'stockQuantity' => 2,
                    'procurementStatus' => 'pending',
                    'purchaseQuantity' => 5,
                    'purchaseOrderNumber' => '',
                    'expectedDeliveryDate' => null,
                    'procurementNotes' => '',
                    'lastUpdated' => now()->toISOString(),
                    'budgetElementId' => 'element-v2',
                    'budgetItemId' => 'material-v2',
                    'budgetElementPersistentId' => 'element-persistent',
                    'budgetItemPersistentId' => 'material-persistent',
                    'budgetDataId' => 99,
                ],
            ],
            'budget_summary' => ['materialsTotal' => 700, 'totalItems' => 1],
            'last_import_date' => now(),
        ]);

        Sanctum::actingAs($procurement);

        $requisitionResponse = $this->postJson('/api/procurement-stores/requisitions', [
            'date' => now()->toDateString(),
            'requested_by_type' => 'project',
            'project_id' => $enquiry->id,
            'urgency' => 'normal',
            'job_number' => $enquiry->enquiry_number . ' - ' . $enquiry->title,
            'items' => [
                [
                    'project_enquiry_id' => $enquiry->id,
                    'procurement_task_id' => $procurementTask->id,
                    'budget_data_id' => 99,
                    'budget_element_id' => 'element-v2',
                    'budget_element_persistent_id' => 'element-persistent',
                    'budget_item_id' => 'material-v2',
                    'budget_item_persistent_id' => 'material-persistent',
                    'material_id' => null,
                    'expense_code_id' => $this->expenseCode()->id,
                    'custom_description' => 'Approved Truss',
                    'quantity' => 5,
                    'unit_price' => 100,
                    'internal_budget_unit_price' => 100,
                    'purpose' => '[' . $enquiry->enquiry_number . '] Stage - Approved Truss',
                    'reason' => 'Procurement from approved internal budget',
                    'procurement_item_snapshot' => [
                        'requiredQuantity' => 7,
                        'stockQuantity' => 2,
                        'purchaseQuantity' => 5,
                    ],
                ],
            ],
        ]);

        $requisitionResponse->assertSuccessful();
        $requisitionId = $requisitionResponse->json('data.id');
        $requisitionItemId = $requisitionResponse->json('data.items.0.id');

        $this->getJson("/api/projects/tasks/{$procurementTask->id}/procurement")
            ->assertOk()
            ->assertJsonPath('data.procurementItems.0.procurementStatus', 'pending')
            ->assertJsonPath('data.procurementItems.0.procurementLinks.0.requisitionId', $requisitionId)
            ->assertJsonPath('data.budgetSummary.operationalSync.linkedRequisitionCount', 1);

        $this->postJson("/api/procurement-stores/requisitions/{$requisitionId}/submit")
            ->assertSuccessful();

        Sanctum::actingAs($accounts);

        $this->postJson("/api/procurement-stores/requisitions/{$requisitionId}/approve")
            ->assertSuccessful();

        $supplier = Supplier::create([
            'supplier_name' => 'Sync Supplier Ltd',
            'contact_person' => 'Sam Supplier',
            'phone' => '0700000001',
            'email' => uniqid('supplier_') . '@test.local',
            'address' => 'Industrial Area',
            'payment_terms' => '30 days',
            'status' => 'Active',
            'user_id' => $accounts->id,
        ]);

        $purchaseOrderResponse = $this->postJson('/api/procurement-stores/purchase-orders/store-linked', [
            'requisition_id' => $requisitionId,
            'supplier_id' => $supplier->id,
            'due_date' => now()->addDays(5)->toDateString(),
            'delivery_address' => 'Project site',
            'description' => 'Linked procurement order',
        ]);

        $purchaseOrderResponse->assertSuccessful()
            ->assertJsonPath('data.items.0.requisition_item_id', $requisitionItemId);

        $purchaseOrderId = $purchaseOrderResponse->json('data.id');
        $purchaseOrderNumber = $purchaseOrderResponse->json('data.po_number');
        $purchaseOrderItemId = $purchaseOrderResponse->json('data.items.0.id');

        $this->postJson("/api/procurement-stores/purchase-orders/{$purchaseOrderId}/submit")
            ->assertSuccessful();
        $this->postJson("/api/procurement-stores/purchase-orders/{$purchaseOrderId}/approve")
            ->assertSuccessful();

        $synced = $this->getJson("/api/projects/tasks/{$procurementTask->id}/procurement");

        $synced->assertOk()
            ->assertJsonPath('data.procurementItems.0.procurementStatus', 'ordered')
            ->assertJsonPath('data.procurementItems.0.availabilityStatus', 'ordered')
            ->assertJsonPath('data.procurementItems.0.purchaseQuantity', 5)
            ->assertJsonPath('data.procurementItems.0.purchaseOrderNumber', $purchaseOrderNumber)
            ->assertJsonPath('data.procurementItems.0.vendorName', 'Sync Supplier Ltd')
            ->assertJsonPath('data.procurementItems.0.procurementLinks.0.poStatus', 'approved')
            ->assertJsonPath('data.procurementItems.0.procurementLinks.0.purchaseOrderItemId', $purchaseOrderItemId)
            ->assertJsonPath('data.budgetSummary.operationalSync.linkedPurchaseOrderCount', 1)
            ->assertJsonPath('data.budgetSummary.operationalSync.committedAmount', 500);

        $this->postJson('/api/procurement-stores/goods-receipt-notes', [
            'purchase_order_id' => $purchaseOrderId,
            'store_location' => 'Karen Village Store',
            'quality_check' => 'pass',
            'notes' => 'Received custom project item',
            'items' => [
                [
                    'purchase_order_item_id' => $purchaseOrderItemId,
                    'material_id' => null,
                    'ordered_quantity' => 5,
                    'received_quantity' => 5,
                    'condition' => 'good',
                    'accepted' => true,
                ],
            ],
        ])->assertSuccessful();

        $this->getJson("/api/projects/tasks/{$procurementTask->id}/procurement")
            ->assertOk()
            ->assertJsonPath('data.procurementItems.0.procurementStatus', 'received')
            ->assertJsonPath('data.procurementItems.0.availabilityStatus', 'received')
            ->assertJsonPath('data.procurementItems.0.procurementLinks.0.receiptStatus', 'received')
            ->assertJsonPath('data.procurementItems.0.operationalSync.receivedQuantity', 5)
            ->assertJsonPath('data.budgetSummary.operationalSync.receivedQuantity', 5);
    }

    /**
     * A code to charge a project requisition to.
     *
     * Project requisitions must name an expense code — that is what puts the
     * spend in the cost ledger under something rather than nowhere — so a
     * payload without one is not a requisition the app would accept.
     */
    private function expenseCode(): ExpenseCode
    {
        return ExpenseCode::firstOrCreate(
            ['code' => 'WFC-001'],
            [
                'accounting_class' => 'Direct project cost',
                'expense_family' => 'Direct materials',
                'expense_type' => 'Project material',
                'job_id_rule' => ExpenseCode::JOB_OPTIONAL,
                'cash_flow_class' => 'operating',
                // Receiving against the order accrues a cost, and the journal
                // service refuses to guess a destination for a named code.
                'default_debit_account_id' => \App\Modules\Finance\Models\ChartOfAccount::where('code', '1211')->value('id'),
                'is_active' => true,
            ],
        );
    }

    private function user(?string $role = null): User
    {
        $user = User::create([
            'name' => uniqid('user_'),
            'email' => uniqid('user_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);

        if ($role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        // The finance routes exercised here are gated on `finance.receivables.*`
        // permissions. Assigning the Accounts role does not grant them —
        // RoleAndPermissionSeeder gives Accounts petty-cash rights but no
        // receivables rights at all — so these requests answered 403 and the
        // assertions beneath them never ran. Granting explicitly keeps the test
        // about the workflow contract rather than about role configuration.
        if (in_array($role, ['Accounts', 'Finance'], true)) {
            $user->givePermissionTo(array_map(
                fn (string $name) => Permission::findOrCreate($name, 'web'),
                [
                    Permissions::FINANCE_RECEIVABLES_READ,
                    Permissions::FINANCE_RECEIVABLES_RECORD,
                    Permissions::FINANCE_RECEIVABLES_VERIFY,
                    Permissions::FINANCE_RECEIVABLES_RELEASE,
                    Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
                ],
            ));
        }

        return $user->fresh();
    }

    private function client(): Client
    {
        return Client::create([
            'full_name' => 'Acme Test Client',
            'contact_person' => 'Jane Test',
            'email' => uniqid('client_') . '@test.local',
            'phone' => '0700000000',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'test',
            'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    private function enquiry(array $overrides = []): ProjectEnquiry
    {
        $creator = $overrides['created_by'] ?? $this->user()->id;

        return ProjectEnquiry::create(array_merge([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Test Project',
            'description' => 'Contract test project',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-TEST-' . uniqid(),
            'created_by' => $creator,
            'selected_workflow_tasks' => ['design', 'report'],
            'workflow_preset_type' => 'external_project',
        ], $overrides));
    }

    private function task(ProjectEnquiry $enquiry, string $type, array $overrides = []): EnquiryTask
    {
        return EnquiryTask::create(array_merge([
            'project_enquiry_id' => $enquiry->id,
            'title' => ucfirst(str_replace('_', ' ', $type)),
            'type' => $type,
            'status' => 'pending',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'task_description' => 'Contract test task',
            'task_order' => 1,
            'created_by' => $enquiry->created_by,
        ], $overrides));
    }
}
