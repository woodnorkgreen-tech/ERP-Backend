<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\ProjectElement;
use App\Models\ProjectEnquiry;
use App\Models\TaskMaterialsData;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\logisticsTask\Models\TransportItem;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers the "push selected materials elements to the Logistics loading
 * sheet" action added 2026-07-21, triggered from the Materials task's own
 * element list rather than the existing bulk "import all" pulled from the
 * Logistics side.
 */
class MaterialsPushToLogisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_pushes_only_the_selected_elements(): void
    {
        $enquiry = $this->enquiry(['selected_workflow_tasks' => ['materials', 'logistics']]);
        $materialsTask = $this->task($enquiry, 'materials');
        $this->task($enquiry, 'logistics');
        $data = TaskMaterialsData::create(['enquiry_task_id' => $materialsTask->id, 'project_info' => []]);

        $keep = $this->element($data, 'Backdrop Panel');
        $skip = $this->element($data, 'Arch Frame');

        $response = $this->pushAs($materialsTask, $this->user(), [$keep->id]);

        $response->assertOk();
        $this->assertDatabaseHas('transport_items', ['name' => 'Backdrop Panel']);
        $this->assertDatabaseMissing('transport_items', ['name' => 'Arch Frame']);
    }

    public function test_auto_creates_the_logistics_task_data_row_if_missing(): void
    {
        $enquiry = $this->enquiry(['selected_workflow_tasks' => ['materials', 'logistics']]);
        $materialsTask = $this->task($enquiry, 'materials');
        $this->task($enquiry, 'logistics');
        $data = TaskMaterialsData::create(['enquiry_task_id' => $materialsTask->id, 'project_info' => []]);
        $element = $this->element($data, 'Backdrop Panel');

        $this->pushAs($materialsTask, $this->user(), [$element->id])->assertOk();

        $this->assertDatabaseHas('logistics_tasks', ['task_id' => EnquiryTask::where('type', 'logistics')->first()->id]);
    }

    public function test_pushing_the_same_element_twice_updates_rather_than_duplicates(): void
    {
        $enquiry = $this->enquiry(['selected_workflow_tasks' => ['materials', 'logistics']]);
        $materialsTask = $this->task($enquiry, 'materials');
        $this->task($enquiry, 'logistics');
        $data = TaskMaterialsData::create(['enquiry_task_id' => $materialsTask->id, 'project_info' => []]);
        $element = $this->element($data, 'Backdrop Panel');
        $user = $this->user();

        $this->pushAs($materialsTask, $user, [$element->id])->assertOk();
        $this->pushAs($materialsTask, $user, [$element->id])->assertOk();

        $this->assertSame(1, TransportItem::where('name', 'Backdrop Panel')->count());
    }

    public function test_excluded_elements_are_not_pushed_even_if_selected(): void
    {
        $enquiry = $this->enquiry(['selected_workflow_tasks' => ['materials', 'logistics']]);
        $materialsTask = $this->task($enquiry, 'materials');
        $this->task($enquiry, 'logistics');
        $data = TaskMaterialsData::create(['enquiry_task_id' => $materialsTask->id, 'project_info' => []]);
        $excluded = $this->element($data, 'Unused Prop', ['is_included' => false]);

        $response = $this->pushAs($materialsTask, $this->user(), [$excluded->id]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('transport_items', ['name' => 'Unused Prop']);
    }

    public function test_returns_a_clear_error_when_workflow_has_no_logistics_task(): void
    {
        $enquiry = $this->enquiry(['selected_workflow_tasks' => ['materials']]);
        $materialsTask = $this->task($enquiry, 'materials');
        $data = TaskMaterialsData::create(['enquiry_task_id' => $materialsTask->id, 'project_info' => []]);
        $element = $this->element($data, 'Backdrop Panel');

        $response = $this->pushAs($materialsTask, $this->user(), [$element->id]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', "This project's workflow does not include a Logistics task.");
    }

    public function test_requires_at_least_one_element_id(): void
    {
        $enquiry = $this->enquiry(['selected_workflow_tasks' => ['materials', 'logistics']]);
        $materialsTask = $this->task($enquiry, 'materials');

        Sanctum::actingAs($this->user());
        $response = $this->postJson("/api/projects/tasks/{$materialsTask->id}/materials/push-to-logistics", []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['element_ids']]);
    }

    private function pushAs(EnquiryTask $task, User $actor, array $elementIds)
    {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/projects/tasks/{$task->id}/materials/push-to-logistics", [
            'element_ids' => $elementIds,
        ]);
    }

    private function element(TaskMaterialsData $data, string $name, array $overrides = []): ProjectElement
    {
        return ProjectElement::create(array_merge([
            'task_materials_data_id' => $data->id,
            'element_type' => 'prop',
            'name' => $name,
            'category' => 'production',
            'is_included' => true,
            'sort_order' => 0,
        ], $overrides));
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
            \Spatie\Permission\Models\Role::findOrCreate($role, 'web');
            $user->assignRole($role);
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
            'title' => 'Push To Logistics Test Project',
            'description' => 'Test project',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_IN_PROGRESS,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-TEST-' . uniqid(),
            'created_by' => $creator,
            'workflow_preset_type' => 'external_project',
        ], $overrides));
    }

    private function task(ProjectEnquiry $enquiry, string $type, array $overrides = []): EnquiryTask
    {
        return EnquiryTask::create(array_merge([
            'project_enquiry_id' => $enquiry->id,
            'title' => ucfirst(str_replace('_', ' ', $type)),
            'type' => $type,
            'status' => 'in_progress',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'task_order' => 1,
            'created_by' => $enquiry->created_by,
        ], $overrides));
    }
}
