<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\ElementMaterial;
use App\Models\MaterialVersion;
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
 * Covers the reworked "reason for edit" gate: plain saves are always free,
 * and a reason is only required when the Project Officer re-approves
 * materials that changed since the base (first-approval) snapshot.
 */
class MaterialsApprovalReasonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_saving_materials_never_requires_a_reason_even_after_base_approval(): void
    {
        $task = $this->materialsTask();
        [$element, $material] = $this->seedElement($task);
        $this->approveAsProjectOfficer($task)->assertOk(); // creates the base snapshot

        Sanctum::actingAs($this->user());

        $payload = $this->buildSavePayload($element->id, $material->id, quantity: 12);
        $this->postJson("/api/projects/tasks/{$task->id}/materials", $payload)
            ->assertOk();
    }

    public function test_po_reapproval_is_blocked_without_a_reason_when_materials_changed_since_base(): void
    {
        $task = $this->materialsTask();
        [, $material] = $this->seedElement($task);
        $this->approveAsProjectOfficer($task)->assertOk(); // base snapshot captured here

        $material->update(['quantity' => 99]); // change since base, bypassing the save endpoint

        $response = $this->approveAsProjectOfficer($task);

        $response->assertStatus(422);
        $this->assertArrayHasKey('editReason', $response->json('errors'));
    }

    public function test_po_reapproval_succeeds_with_a_reason_and_creates_a_tracked_revision(): void
    {
        $task = $this->materialsTask();
        [, $material] = $this->seedElement($task);
        $this->approveAsProjectOfficer($task)->assertOk();

        $material->update(['quantity' => 99]);

        $response = $this->approveAsProjectOfficer($task, editReason: 'Client requested more units.');
        $response->assertOk();

        $this->assertSame(
            'Client requested more units.',
            $response->json('approval_status.project_officer.edit_reason')
        );

        $this->assertSame(
            2,
            MaterialVersion::where('task_materials_data_id', $task->materialsData->id ?? TaskMaterialsData::where('enquiry_task_id', $task->id)->first()->id)->count(),
            'Expected exactly the base version plus one tracked revision.'
        );

        $latest = MaterialVersion::where('task_materials_data_id', TaskMaterialsData::where('enquiry_task_id', $task->id)->first()->id)
            ->orderByDesc('version_number')
            ->first();
        $this->assertFalse($latest->is_base);
        $this->assertSame('Client requested more units.', $latest->reason);
    }

    public function test_production_reapproval_is_never_gated_by_a_reason(): void
    {
        $task = $this->materialsTask();
        [, $material] = $this->seedElement($task);
        $this->approveAsProjectOfficer($task)->assertOk();

        $material->update(['quantity' => 99]);

        $this->approveAsProduction($task)->assertOk();
    }

    private function buildSavePayload(int $elementId, int $materialId, float $quantity): array
    {
        return [
            'projectInfo' => ['projectId' => 'TEST-001'],
            'projectElements' => [[
                'id' => (string) $elementId,
                'elementType' => 'Stage',
                'name' => 'Main Stage',
                'category' => 'production',
                'materials' => [[
                    'id' => (string) $materialId,
                    'description' => 'Timber Sheet',
                    'unitOfMeasurement' => 'Pcs',
                    'quantity' => $quantity,
                ]],
            ]],
        ];
    }

    private function approveAsProjectOfficer(EnquiryTask $task, ?string $editReason = null)
    {
        $po = $this->user('Project Officer');
        Sanctum::actingAs($po);

        return $this->postJson(
            "/api/projects/tasks/{$task->id}/materials/approve/project_officer",
            array_filter(['comments' => '', 'editReason' => $editReason])
        );
    }

    private function approveAsProduction(EnquiryTask $task)
    {
        $prod = $this->user('Production');
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
            'title' => 'Materials Reason Gate Test Project',
            'description' => 'Materials approval reason gate test',
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
