<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\ElementMaterial;
use App\Models\ProjectDeliverable;
use App\Models\ProjectElement;
use App\Models\ProjectEnquiry;
use App\Models\TaskMaterialsData;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Projects\Models\EnquiryTask;
use App\Services\ProjectElementRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SharedProjectElementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_scope_and_materials_use_one_record_and_keep_element_and_line_ids_on_save(): void
    {
        $task = $this->materialsTask();
        $enquiry = $task->enquiry;
        $enquiry->update(['project_scope' => [['uuid' => 'stage-shared-id', 'name' => 'Main Stage', 'classification' => 'FABRICATION & STRUCTURES']]]);
        Sanctum::actingAs($this->user('Project Officer'));
        $data = $this->getJson("/api/projects/tasks/{$task->id}/materials")->assertOk()->json('data');
        $this->assertCount(1, $data['projectElements']);
        $resource = (new \App\Modules\Projects\Resources\EnquiryResource($enquiry->fresh()))->resolve();
        $this->assertSame($enquiry->fresh()->elements_revision, $resource['elements_revision']);
        $element = $data['projectElements'][0];
        $this->assertSame('stage-shared-id', $element['scopeId']);
        $this->assertSame(ProjectDeliverable::where('uuid', 'stage-shared-id')->firstOrFail()->id, (int) $element['id']);
        $element['name'] = 'Main Stage Revised';
        $element['requiredQuantity'] = 2;
        $element['materials'] = [['id' => 'new-line', 'description' => 'Plywood', 'unitOfMeasurement' => 'Pcs', 'quantity' => 12, 'unitCost' => 300]];
        $data['projectElements'] = [$element];
        $saved = $this->postJson("/api/projects/tasks/{$task->id}/materials", $data)->assertOk()->json('data');
        $line = $saved['projectElements'][0]['materials'][0];
        $resaved = $this->postJson("/api/projects/tasks/{$task->id}/materials", $saved)->assertOk()->json('data');
        $this->assertSame($element['id'], $resaved['projectElements'][0]['id']);
        $this->assertSame($line['id'], $resaved['projectElements'][0]['materials'][0]['id']);
        $this->assertSame($line['persistent_id'], $resaved['projectElements'][0]['materials'][0]['persistent_id']);
        $this->assertSame('Main Stage Revised', $enquiry->fresh()->project_scope[0]['name']);
        $this->assertSame(2.0, $enquiry->fresh()->project_scope[0]['required_quantity']);
        $this->assertSame(1, ProjectElement::where('enquiry_id', $enquiry->id)->count());
    }

    public function test_scope_edits_update_materials_without_losing_the_bom_and_reset_approvals(): void
    {
        $task = $this->materialsTask();
        $registry = app(ProjectElementRegistry::class);
        $data = $registry->ensureForTask($task->id);
        $registry->saveMaterials($data, [$this->element('custom-stage', 'Stage', 'production', [['description' => 'Timber', 'unitOfMeasurement' => 'Pcs', 'quantity' => 6]])]);
        $element = $data->elements()->firstOrFail();
        $lineId = $element->materials()->firstOrFail()->id;
        $data->update(['project_info' => ['approval_status' => ['project_officer' => ['approved' => true], 'production' => ['approved' => true], 'all_approved' => true]]]);
        $task->enquiry->update(['project_scope' => [['uuid' => $element->uuid, 'name' => 'Stage Renamed', 'classification' => 'FABRICATION & STRUCTURES', 'required_quantity' => 3, 'unit_of_measurement' => 'Sets', 'fulfilment_route' => 'hire']]]);
        $this->assertSame('Stage Renamed', $element->fresh()->name);
        $this->assertSame('hire', $element->fresh()->category);
        $this->assertSame($lineId, $element->fresh()->materials()->firstOrFail()->id);
        $this->assertFalse($data->fresh()->project_info['approval_status']['all_approved']);
    }

    public function test_removing_an_element_archives_the_shared_record_and_material_lines(): void
    {
        $task = $this->materialsTask();
        $data = app(ProjectElementRegistry::class)->ensureForTask($task->id);
        app(ProjectElementRegistry::class)->saveMaterials($data, [$this->element('new-stage', 'Stage', 'production', [['description' => 'Timber', 'unitOfMeasurement' => 'Pcs', 'quantity' => 6]])]);
        $element = $data->elements()->firstOrFail();
        $line = $element->materials()->firstOrFail();
        $task->enquiry->update(['project_scope' => []]);
        $this->assertCount(0, $task->enquiry->fresh()->project_scope);
        $this->assertSame(0, $data->elements()->count());
        $this->assertNotNull(ProjectElement::withTrashed()->findOrFail($element->id)->archived_at);
        $this->assertNotNull(ElementMaterial::withTrashed()->findOrFail($line->id)->archived_at);
        $this->assertSame($element->id, $line->fresh()->element->id);
    }

    public function test_foreign_scope_ids_are_rejected_without_modifying_either_enquiry(): void
    {
        $first = $this->materialsTask();
        $other = $this->materialsTask();
        $other->enquiry->update(['project_scope' => [['uuid' => 'other-stage-id', 'name' => 'Other Stage']]]);
        Sanctum::actingAs($this->user('Project Officer'));
        $payload = $this->element('custom-id', 'Stolen Stage', 'production', []);
        $payload['scopeId'] = 'other-stage-id';
        $this->postJson("/api/projects/tasks/{$first->id}/materials", ['projectInfo' => ['projectId' => 'TEST'], 'projectElements' => [$payload]])->assertStatus(422);
        $this->assertSame('Other Stage', $other->enquiry->fresh()->project_scope[0]['name']);
        $this->assertCount(0, $first->enquiry->fresh()->project_scope);
    }

    public function test_version_restoration_keeps_shared_identity(): void
    {
        $task = $this->materialsTask();
        Sanctum::actingAs($this->user('Project Officer'));
        $this->postJson("/api/projects/tasks/{$task->id}/materials", ['projectInfo' => ['projectId' => 'TEST'], 'projectElements' => [$this->element('new-stage', 'Stage', 'production', [['description' => 'Timber', 'unitOfMeasurement' => 'Pcs', 'quantity' => 6]])]])->assertOk();
        $data = TaskMaterialsData::where('enquiry_task_id', $task->id)->firstOrFail();
        $element = $data->elements()->firstOrFail();
        $line = $element->materials()->firstOrFail();
        $version = $this->postJson("/api/projects/tasks/{$task->id}/materials/versions", ['label' => 'Baseline', 'reason' => 'Test'])->assertSuccessful()->json('data.id');
        $element->update(['name' => 'Renamed', 'required_quantity' => 5]);
        $line->update(['quantity' => 99]);
        $this->postJson("/api/projects/tasks/{$task->id}/materials/versions/{$version}/restore")->assertOk();
        $this->assertSame('Stage', $element->fresh()->name);
        $this->assertSame(6.0, (float) $line->fresh()->quantity);
        $this->assertSame($element->uuid, $task->enquiry->fresh()->project_scope[0]['uuid']);
        $this->assertSame(1, $data->elements()->count());
    }

    public function test_stale_scope_revision_is_rejected(): void
    {
        $task = $this->materialsTask();
        $task->enquiry->update(['project_scope' => [['uuid' => 'first-element', 'name' => 'Stage']]]);
        $oldRevision = $task->enquiry->fresh()->elements_revision;
        $data = app(ProjectElementRegistry::class)->ensureForTask($task->id);
        $element = $data->elements()->firstOrFail();
        app(ProjectElementRegistry::class)->saveMaterials($data, [array_merge($this->element((string) $element->id, 'Stage Updated', 'production', []), ['scopeId' => $element->uuid])]);
        Sanctum::actingAs($this->user('Project Officer'));
        $this->putJson("/api/projects/enquiries/{$task->project_enquiry_id}/deliverables", ['project_scope' => [], 'elementsRevision' => $oldRevision])->assertStatus(409);
        $this->assertSame('Stage Updated', $element->fresh()->name);
    }

    public function test_a_legacy_snapshot_restores_the_mapped_element_without_losing_current_unit_cost(): void
    {
        $task = $this->materialsTask();
        $data = app(ProjectElementRegistry::class)->ensureForTask($task->id);
        app(ProjectElementRegistry::class)->saveMaterials($data, [$this->element('custom-stage', 'Stage', 'production', [['description' => 'Timber', 'unitOfMeasurement' => 'Pcs', 'quantity' => 6, 'unitCost' => 300]])]);
        $element = $data->elements()->firstOrFail();
        $element->forceFill(['legacy_project_element_id' => 991])->save();
        $line = $element->materials()->firstOrFail();
        $version = \App\Models\MaterialVersion::create([
            'task_materials_data_id' => $data->id, 'version_number' => 1, 'is_base' => false,
            'label' => 'Legacy Snapshot', 'created_by' => $task->created_by,
            'data' => ['project_info' => [], 'elements' => [[
                'id' => 991, 'element_type' => 'Stage', 'name' => 'Legacy Stage', 'category' => 'production',
                'materials' => [['id' => $line->id, 'description' => 'Timber', 'unit_of_measurement' => 'Pcs', 'quantity' => 12]],
            ]]],
        ]);
        Sanctum::actingAs($this->user('Project Officer'));
        $this->postJson("/api/projects/tasks/{$task->id}/materials/versions/{$version->id}/restore")->assertOk();
        $this->assertSame('Legacy Stage', $element->fresh()->name);
        $this->assertSame(12.0, (float) $line->fresh()->quantity);
        $this->assertSame(300.0, (float) $line->fresh()->unit_cost);
        $this->assertSame(1, $data->elements()->count());
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
            'enquiry_number' => 'ENQ-TEST-'.uniqid(),
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
            'email' => uniqid('user_').'@test.local',
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
            'email' => uniqid('client_').'@test.local',
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
