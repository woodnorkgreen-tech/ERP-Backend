<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\ElementMaterial;
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
 * Covers "push selected material particulars to the Logistics loading
 * sheet" — one transport item per material line-item (not per element),
 * each carrying its own real quantity and unit. Triggered from the
 * Materials task's own element list, distinct from the existing bulk
 * "import all elements" pulled from the Logistics side.
 */
class MaterialsPushToLogisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_pushes_only_the_selected_materials_with_real_quantity_and_unit(): void
    {
        [$materialsTask, $element] = $this->materialsSetup();
        $keep = $this->material($element, 'Plywood 4x8', ['quantity' => 3, 'unit_of_measurement' => 'sheets']);
        $skip = $this->material($element, 'Wood Screws', ['quantity' => 50, 'unit_of_measurement' => 'pcs']);

        $response = $this->pushAs($materialsTask, $this->user(), [$keep->id]);

        $response->assertOk();
        $this->assertDatabaseHas('transport_items', [
            'name' => 'Plywood 4x8',
            'quantity' => 3,
            'unit' => 'sheets',
        ]);
        $this->assertDatabaseMissing('transport_items', ['name' => 'Wood Screws']);
    }

    public function test_fractional_quantity_rounds_up_not_down(): void
    {
        [$materialsTask, $element] = $this->materialsSetup();
        $material = $this->material($element, 'Fabric', ['quantity' => 2.1, 'unit_of_measurement' => 'metres']);

        $this->pushAs($materialsTask, $this->user(), [$material->id])->assertOk();

        $this->assertDatabaseHas('transport_items', ['name' => 'Fabric', 'quantity' => 3]);
    }

    public function test_auto_creates_the_logistics_task_data_row_if_missing(): void
    {
        [$materialsTask, $element] = $this->materialsSetup();
        $material = $this->material($element, 'Plywood 4x8');

        $this->pushAs($materialsTask, $this->user(), [$material->id])->assertOk();

        $this->assertDatabaseHas('logistics_tasks', [
            'task_id' => EnquiryTask::where('type', 'logistics')->first()->id,
        ]);
    }

    public function test_pushing_the_same_material_twice_updates_rather_than_duplicates(): void
    {
        [$materialsTask, $element] = $this->materialsSetup();
        $material = $this->material($element, 'Plywood 4x8', ['quantity' => 3]);
        $user = $this->user();

        $this->pushAs($materialsTask, $user, [$material->id])->assertOk();
        $this->pushAs($materialsTask, $user, [$material->id])->assertOk();

        $this->assertSame(1, TransportItem::where('name', 'Plywood 4x8')->count());
    }

    public function test_excluded_materials_are_not_pushed_even_if_selected(): void
    {
        [$materialsTask, $element] = $this->materialsSetup();
        $excluded = $this->material($element, 'Spare Bolts', ['is_included' => false]);

        $response = $this->pushAs($materialsTask, $this->user(), [$excluded->id]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('transport_items', ['name' => 'Spare Bolts']);
    }

    public function test_materials_of_an_excluded_element_are_not_pushed_even_if_selected(): void
    {
        [$materialsTask, , $data] = $this->materialsSetup();
        $excludedElement = ProjectElement::create([
            'task_materials_data_id' => $data->id,
            'element_type' => 'prop',
            'name' => 'Unused Prop',
            'category' => 'production',
            'is_included' => false,
            'sort_order' => 1,
        ]);
        $material = $this->material($excludedElement, 'Trim');

        $response = $this->pushAs($materialsTask, $this->user(), [$material->id]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('transport_items', ['name' => 'Trim']);
    }

    public function test_returns_a_clear_error_when_workflow_has_no_logistics_task(): void
    {
        $enquiry = $this->enquiry(['selected_workflow_tasks' => ['materials']]);
        $materialsTask = $this->task($enquiry, 'materials');
        $data = TaskMaterialsData::create(['enquiry_task_id' => $materialsTask->id, 'project_info' => []]);
        $element = $this->element($data, 'Backdrop Panel');
        $material = $this->material($element, 'Plywood 4x8');

        $response = $this->pushAs($materialsTask, $this->user(), [$material->id]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', "This project's workflow does not include a Logistics task.");
    }

    public function test_requires_at_least_one_material_id(): void
    {
        $enquiry = $this->enquiry(['selected_workflow_tasks' => ['materials', 'logistics']]);
        $materialsTask = $this->task($enquiry, 'materials');

        Sanctum::actingAs($this->user());
        $response = $this->postJson("/api/projects/tasks/{$materialsTask->id}/materials/push-to-logistics", []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['material_ids']]);
    }

    /** @return array{0: EnquiryTask, 1: ProjectElement, 2: TaskMaterialsData} */
    private function materialsSetup(): array
    {
        $enquiry = $this->enquiry(['selected_workflow_tasks' => ['materials', 'logistics']]);
        $materialsTask = $this->task($enquiry, 'materials');
        $this->task($enquiry, 'logistics');
        $data = TaskMaterialsData::create(['enquiry_task_id' => $materialsTask->id, 'project_info' => []]);
        $element = $this->element($data, 'Backdrop Panel');

        return [$materialsTask, $element, $data];
    }

    private function pushAs(EnquiryTask $task, User $actor, array $materialIds)
    {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/projects/tasks/{$task->id}/materials/push-to-logistics", [
            'material_ids' => $materialIds,
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

    private function material(ProjectElement $element, string $description, array $overrides = []): ElementMaterial
    {
        return ElementMaterial::create(array_merge([
            'project_element_id' => $element->id,
            'description' => $description,
            'unit_of_measurement' => 'pcs',
            'quantity' => 1,
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
