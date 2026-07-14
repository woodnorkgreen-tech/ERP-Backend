<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\ElementMaterial;
use App\Models\ProjectElement;
use App\Models\ProjectEnquiry;
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
 * A Materials task must not be marked complete unless BOTH Project Officer
 * and Production have approved. Regression coverage for the loophole where
 * Project Officer (in ROLES_ADMIN) could self-bypass this gate entirely.
 */
class MaterialsCompletionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_materials_task_cannot_be_completed_with_no_approvals(): void
    {
        $task = $this->materialsTask();
        $this->seedElement($task);

        $response = $this->completeAs($task, $this->user('Project Manager'));

        $response->assertStatus(422);
        $this->assertDatabaseHas('enquiry_tasks', ['id' => $task->id, 'status' => 'in_progress']);
    }

    public function test_project_officer_cannot_force_complete_without_production_approval(): void
    {
        $task = $this->materialsTask();
        $this->seedElement($task);

        $po = $this->user('Project Officer');
        $this->approveAsProjectOfficer($task, $po)->assertOk();

        // Same Project Officer then tries to mark the task complete — must
        // still be blocked, since Production has not approved.
        $response = $this->completeAs($task, $po);

        $response->assertStatus(422);
        $this->assertDatabaseHas('enquiry_tasks', ['id' => $task->id, 'status' => 'in_progress']);
    }

    public function test_production_cannot_force_complete_without_project_officer_approval(): void
    {
        $task = $this->materialsTask();
        $this->seedElement($task);

        $prod = $this->user('Production');
        $this->approveAsProduction($task, $prod)->assertOk();

        $response = $this->completeAs($task, $prod);

        $response->assertStatus(422);
        $this->assertDatabaseHas('enquiry_tasks', ['id' => $task->id, 'status' => 'in_progress']);
    }

    public function test_materials_task_completes_once_both_departments_approve(): void
    {
        $task = $this->materialsTask();
        $this->seedElement($task);

        $this->approveAsProjectOfficer($task, $this->user('Project Officer'))->assertOk();
        $this->approveAsProduction($task, $this->user('Production'))->assertOk();

        $response = $this->completeAs($task, $this->user('Project Manager'));

        $response->assertOk();
        $this->assertDatabaseHas('enquiry_tasks', ['id' => $task->id, 'status' => 'completed']);
    }

    public function test_super_admin_can_still_force_complete_without_approvals(): void
    {
        $task = $this->materialsTask();
        $this->seedElement($task);

        $response = $this->completeAs($task, $this->user('Super Admin'));

        $response->assertOk();
        $this->assertDatabaseHas('enquiry_tasks', ['id' => $task->id, 'status' => 'completed']);
    }

    private function completeAs(EnquiryTask $task, User $actor)
    {
        Sanctum::actingAs($actor);

        return $this->putJson("/api/projects/enquiry-tasks/{$task->id}", ['status' => 'completed']);
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

    private function seedElement(EnquiryTask $task): array
    {
        $materialsData = TaskMaterialsData::create([
            'enquiry_task_id' => $task->id,
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

        $material = ElementMaterial::create([
            'project_element_id' => $element->id,
            'description' => 'Timber Sheet',
            'unit_of_measurement' => 'Pcs',
            'quantity' => 10,
            'is_included' => true,
            'sort_order' => 1,
        ]);

        return [$element, $material];
    }

    private function materialsTask(): EnquiryTask
    {
        $creator = $this->user();

        $enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Materials Completion Gate Test Project',
            'description' => 'Materials completion gate test',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-TEST-' . uniqid(),
            'created_by' => $creator->id,
            'selected_workflow_tasks' => ['materials'],
            'workflow_preset_type' => 'external_project',
        ]);

        return EnquiryTask::create([
            'project_enquiry_id' => $enquiry->id,
            'title' => 'Materials',
            'type' => 'materials',
            'status' => 'in_progress',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'task_description' => 'Materials task',
            'task_order' => 1,
            'created_by' => $creator->id,
        ]);
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
