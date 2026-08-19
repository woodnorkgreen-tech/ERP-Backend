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
 * A budget that was already signed off must still follow the approved materials
 * list. When both departments approve materials after the budget was completed,
 * the budget is reopened (so the change is visible and audited) AND re-synced —
 * it must not sit on stale totals behind a "completed" badge.
 */
class BudgetResyncOnMaterialsReapprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_completed_budget_is_reopened_and_resynced_on_final_materials_approval(): void
    {
        $creator = $this->user();
        $enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Budget Resync Project',
            'description' => 'Budget must follow re-approved materials',
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

        // The budget was already signed off before materials were re-approved.
        $budgetTask = EnquiryTask::create([
            'project_enquiry_id' => $enquiry->id,
            'title' => 'Budget',
            'type' => 'budget',
            'status' => 'completed',
            'completed_at' => now(),
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
            'unit_cost' => 50, // 10 x 50 = 500 once synced
            'is_included' => true,
            'is_additional' => false,
            'sort_order' => 1,
        ]);

        // Stale figures from before the materials list changed.
        TaskBudgetData::create([
            'enquiry_task_id' => $budgetTask->id,
            'project_info' => ['projectId' => $enquiry->enquiry_number],
            'materials_data' => [],
            'labour_data' => [],
            'expenses_data' => [],
            'logistics_data' => [],
            'budget_summary' => ['materialsTotal' => 0, 'labourTotal' => 100, 'expensesTotal' => 0, 'logisticsTotal' => 0, 'grandTotal' => 100],
            'status' => 'draft',
        ]);

        $this->approveAsProjectOfficer($materialsTask, $this->user('Project Officer'))->assertOk();

        $response = $this->approveAsProduction($materialsTask, $this->user('Production'))->assertOk();

        // The approval response now reports what actually happened to the budget,
        // rather than only logging it server-side.
        $response->assertJsonPath('budget_sync.synced', true)
            ->assertJsonPath('budget_sync.budgetReopened', true);

        $budgetTask->refresh();
        $this->assertSame('in_progress', $budgetTask->status, 'Completed budget must be reopened when materials are re-approved.');
        $this->assertNull($budgetTask->completed_at);

        $budgetData = TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->first();

        $this->assertSame(500.0, (float) $budgetData->budget_summary['materialsTotal'], 'Budget must be re-synced to the approved materials, not left stale.');
        $this->assertSame(600.0, (float) $budgetData->budget_summary['grandTotal'], 'Grand total must fold in the new materials total and keep other lines.');
        $this->assertNotNull($budgetData->materials_imported_at, 'A real sync must stamp materials_imported_at.');
        $this->assertSame($materialsTask->id, $budgetData->materials_imported_from_task);
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
