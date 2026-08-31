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
 * A quote import creates one shell element per quote line, each with an empty
 * BOM. Specifying those BOMs is incremental work, so a save that carries a
 * mix of specified and still-empty elements has to succeed — otherwise the
 * first element a user fills in cannot be persisted until all of them are.
 *
 * Completeness belongs to approval, not to save, and only for *included
 * production* elements: hire and outsourced elements never get a raw-material
 * BOM at all.
 */
class MaterialsPartialBomSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_a_mixed_list_saves_when_only_the_first_element_has_a_bom(): void
    {
        $task = $this->materialsTask();
        Sanctum::actingAs($this->user('Project Officer'));

        // Element 0 is specified; elements 1..20 are untouched quote shells —
        // exactly the payload the save endpoint used to reject wholesale.
        $elements = [$this->element('0', 'Main Stage', 'production', [[
            'description' => 'Timber Sheet',
            'unitOfMeasurement' => 'Pcs',
            'quantity' => 12,
        ]])];

        for ($i = 1; $i <= 20; $i++) {
            $elements[] = $this->element((string) $i, "Quote Line {$i}", 'production', []);
        }

        $this->postJson("/api/projects/tasks/{$task->id}/materials", [
            'projectInfo' => ['projectId' => 'TEST-001'],
            'projectElements' => $elements,
        ])->assertOk();

        $materialsData = TaskMaterialsData::where('enquiry_task_id', $task->id)->firstOrFail();
        $this->assertSame(21, ProjectElement::where('task_materials_data_id', $materialsData->id)->count());
        $this->assertSame(
            1,
            ElementMaterial::whereIn(
                'project_element_id',
                ProjectElement::where('task_materials_data_id', $materialsData->id)->pluck('id')
            )->count(),
            'Only the one specified element should have carried a material line.'
        );
    }

    public function test_an_absent_materials_key_is_treated_as_an_empty_bom(): void
    {
        $task = $this->materialsTask();
        Sanctum::actingAs($this->user('Project Officer'));

        $element = $this->element('0', 'Main Stage', 'production', []);
        unset($element['materials']);

        $this->postJson("/api/projects/tasks/{$task->id}/materials", [
            'projectInfo' => ['projectId' => 'TEST-001'],
            'projectElements' => [$element],
        ])->assertOk();

        $materialsData = TaskMaterialsData::where('enquiry_task_id', $task->id)->firstOrFail();
        $this->assertSame(1, ProjectElement::where('task_materials_data_id', $materialsData->id)->count());
    }

    public function test_approval_still_blocks_an_included_production_element_with_no_bom(): void
    {
        $task = $this->materialsTask();
        $po = $this->user('Project Officer');
        Sanctum::actingAs($po);

        $this->postJson("/api/projects/tasks/{$task->id}/materials", [
            'projectInfo' => ['projectId' => 'TEST-001'],
            'projectElements' => [$this->element('0', 'Unspecified Stage', 'production', [])],
        ])->assertOk();

        $this->postJson("/api/projects/tasks/{$task->id}/materials/approve/project_officer", ['comments' => ''])
            ->assertStatus(422)
            ->assertJsonPath('code', 'MATERIALS_BOM_INCOMPLETE');
    }

    public function test_a_hire_element_needs_no_bom_to_save_or_approve(): void
    {
        $task = $this->materialsTask();
        Sanctum::actingAs($this->user('Project Officer'));

        $this->postJson("/api/projects/tasks/{$task->id}/materials", [
            'projectInfo' => ['projectId' => 'TEST-001'],
            'projectElements' => [$this->element('0', 'Rented Truss', 'hire', [])],
        ])->assertOk();

        $this->postJson("/api/projects/tasks/{$task->id}/materials/approve/project_officer", ['comments' => ''])
            ->assertOk();
    }

    public function test_deleting_an_element_removes_its_materials_rather_than_orphaning_them(): void
    {
        $task = $this->materialsTask();
        Sanctum::actingAs($this->user('Project Officer'));

        $materialsData = TaskMaterialsData::create(['enquiry_task_id' => $task->id, 'project_info' => []]);
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
            'quantity' => 5,
            'is_included' => true,
            'sort_order' => 1,
        ]);

        $this->deleteJson("/api/projects/tasks/{$task->id}/materials/elements/{$element->id}")
            ->assertOk();

        $this->assertSame(0, ProjectElement::where('id', $element->id)->count());
        $this->assertSame(
            0,
            ElementMaterial::where('project_element_id', $element->id)->count(),
            'Material rows must not be left behind — the live schema has no cascade to do it for us.'
        );
    }

    public function test_deleting_an_unsaved_element_answers_404_instead_of_a_type_error(): void
    {
        $task = $this->materialsTask();
        Sanctum::actingAs($this->user('Project Officer'));

        TaskMaterialsData::create(['enquiry_task_id' => $task->id, 'project_info' => []]);

        // A client-generated id from the add-element modal, never persisted.
        $this->deleteJson("/api/projects/tasks/{$task->id}/materials/elements/custom-1724412345678")
            ->assertStatus(404)
            ->assertJsonPath('code', 'ELEMENT_NEVER_SAVED');
    }

    private function element(string $id, string $name, string $category, array $materials): array
    {
        return [
            'id' => $id,
            'elementType' => $name,
            'name' => $name,
            'category' => $category,
            'isIncluded' => true,
            'materials' => $materials,
        ];
    }

    private function materialsTask(): EnquiryTask
    {
        $creator = $this->user();

        $enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Partial BOM Save Test Project',
            'description' => 'Partial BOM save test',
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
