<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\ElementMaterial;
use App\Models\ProjectElement;
use App\Models\ProjectEnquiry;
use App\Models\TaskBudgetData;
use App\Models\TaskMaterialsData;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Budget task must only be marked complete via an explicit user action
 * (the "Complete Task" button -> PUT tasks/{id}/status). It must NOT flip to
 * completed as a side effect of MaterialsController::syncMaterialsToBudget(),
 * which writes to TaskBudgetData in the background the moment Materials gets
 * fully approved (both Project Officer and Production).
 */
class BudgetAutoCompleteRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_final_materials_approval_syncs_budget_but_does_not_complete_it(): void
    {
        $creator = $this->user();
        $enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Budget Auto-Complete Regression Project',
            'description' => 'Regression test for silent budget completion',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-TEST-' . uniqid(),
            'created_by' => $creator->id,
            'selected_workflow_tasks' => ['materials', 'budget'],
            'workflow_preset_type' => 'external_project',
        ]);

        $materialsTask = EnquiryTask::create([
            'project_enquiry_id' => $enquiry->id,
            'title' => 'Materials',
            'type' => 'materials',
            'status' => 'in_progress',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'task_description' => 'Materials task',
            'task_order' => 1,
            'created_by' => $creator->id,
        ]);

        $budgetTask = EnquiryTask::create([
            'project_enquiry_id' => $enquiry->id,
            'title' => 'Budget',
            'type' => 'budget',
            'status' => 'in_progress',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'task_description' => 'Budget task',
            'task_order' => 2,
            'created_by' => $creator->id,
        ]);

        $materialsData = TaskMaterialsData::create([
            'enquiry_task_id' => $materialsTask->id,
            'project_info' => [],
        ]);

        $element = ProjectElement::create([
            'task_materials_data_id' => $materialsData->id,
            'element_type' => 'Stage',
            'name' => 'Main Stage',
            'category' => 'production',
            'is_included' => true,
            'sort_order' => 1,
        ]);

        ElementMaterial::create([
            'project_element_id' => $element->id,
            'description' => 'Timber Sheet',
            'unit_of_measurement' => 'Pcs',
            'quantity' => 10,
            'unit_cost' => 50, // Falls back to this when the budget has no preserved price — needed so the sync produces a positive grandTotal.
            'is_included' => true,
            'is_additional' => false,
            'sort_order' => 1,
        ]);

        // Budget task already has priced data (e.g. from a manual pricing pass) —
        // this is what makes grandTotal > 0 the instant the background sync writes,
        // which is exactly the condition that used to trip the auto-complete hook.
        TaskBudgetData::create([
            'enquiry_task_id' => $budgetTask->id,
            'project_info' => ['projectId' => $enquiry->enquiry_number],
            'materials_data' => [],
            'labour_data' => [],
            'expenses_data' => [],
            'logistics_data' => [],
            'budget_summary' => ['materialsTotal' => 0, 'labourTotal' => 0, 'expensesTotal' => 0, 'logisticsTotal' => 0, 'grandTotal' => 0],
            'status' => 'draft',
        ]);

        // PO approves first — not yet the final approval, sync shouldn't fire either way.
        $this->approveAsProjectOfficer($materialsTask, $this->user('Project Officer'))->assertOk();

        // Production approves second — this is the final approval that triggers
        // MaterialsController::syncMaterialsToBudget(), writing priced materials
        // into TaskBudgetData with a positive grandTotal.
        $this->approveAsProduction($materialsTask, $this->user('Production'))->assertOk();

        $budgetTask->refresh();
        $budgetData = TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->first();

        $this->assertGreaterThan(0, (float) ($budgetData->budget_summary['grandTotal'] ?? 0), 'Sanity check: the sync should have priced the budget.');
        $this->assertNotSame('completed', $budgetTask->status, 'Budget task must not auto-complete from the materials-approval sync.');
    }

    private function approveAsProjectOfficer(EnquiryTask $task, User $po)
    {
        Sanctum::actingAs($po);

        return $this->postJson("/api/projects/tasks/{$task->id}/materials/approve/project_officer", ['comments' => '']);
    }

    private function approveAsProduction(EnquiryTask $task, User $prod)
    {
        Sanctum::actingAs($prod);

        return $this->postJson("/api/projects/tasks/{$task->id}/materials/approve/production", ['comments' => '']);
    }

    private function user(?string $role = null): User
    {
        $user = User::create([
            'name' => uniqid('user_'),
            'email' => uniqid('user_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ])->fresh();

        if ($role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        return $user;
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
}
