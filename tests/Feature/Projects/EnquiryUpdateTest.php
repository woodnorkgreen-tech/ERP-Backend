<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Characterizes current EnquiryController::update() behaviour before it is
 * extracted into an Action, so the extraction can be verified as behaviour-
 * preserving. This endpoint had zero prior test coverage.
 */
class EnquiryUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_plain_field_update_persists_and_resyncs_workflow(): void
    {
        $enquiry = $this->enquiry();
        Sanctum::actingAs($this->user());

        $response = $this->putJson("/api/enquiries/{$enquiry->id}", [
            'title' => 'Updated Title',
            'venue' => 'New Venue',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.title', 'Updated Title');
        $this->assertDatabaseHas('project_enquiries', [
            'id' => $enquiry->id,
            'title' => 'Updated Title',
            'venue' => 'New Venue',
        ]);
    }

    public function test_status_update_syncs_linked_project_record(): void
    {
        $enquiry = $this->enquiry();
        $project = Project::create([
            'enquiry_id' => $enquiry->id,
            'project_id' => 'PRJ-TEST-' . uniqid(),
            'status' => 'planning',
        ]);
        Sanctum::actingAs($this->user());

        $response = $this->putJson("/api/enquiries/{$enquiry->id}", [
            'status' => EnquiryConstants::STATUS_IN_PROGRESS,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_invalid_project_officer_assignment_is_rejected(): void
    {
        $enquiry = $this->enquiry();
        $notAPo = $this->user(); // no Project Officer / Project Manager role
        Sanctum::actingAs($this->user());

        $response = $this->putJson("/api/enquiries/{$enquiry->id}", [
            'project_officer_id' => $notAPo->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.project_officer_id.0', 'Selected user is not a valid project officer');
        $this->assertDatabaseMissing('project_enquiries', [
            'id' => $enquiry->id,
            'project_officer_id' => $notAPo->id,
        ]);
    }

    public function test_marking_complete_is_blocked_when_operational_items_remain(): void
    {
        // 'production' is an operational task type; 'handover'/'report' are closure-stage
        // (PROJECT_CLOSURE_REQUISITES) and are excluded from this gate by design.
        $enquiry = $this->enquiry(['selected_workflow_tasks' => ['production']]);
        $this->task($enquiry, 'production', ['status' => 'in_progress']);
        Sanctum::actingAs($this->user());

        $response = $this->putJson("/api/enquiries/{$enquiry->id}", [
            'status' => EnquiryConstants::STATUS_COMPLETED,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('can_complete', false);
        $this->assertDatabaseHas('project_enquiries', [
            'id' => $enquiry->id,
            'status' => $enquiry->status,
        ]);
    }

    public function test_closing_is_blocked_without_handover_and_report(): void
    {
        $enquiry = $this->enquiry([
            'status' => EnquiryConstants::STATUS_COMPLETED,
            'selected_workflow_tasks' => ['handover', 'report'],
        ]);
        Sanctum::actingAs($this->user());

        $response = $this->putJson("/api/enquiries/{$enquiry->id}", [
            'status' => EnquiryConstants::STATUS_CLOSED,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('can_close', false);
    }

    public function test_validation_failure_returns_422_with_errors(): void
    {
        $enquiry = $this->enquiry();
        Sanctum::actingAs($this->user());

        $response = $this->putJson("/api/enquiries/{$enquiry->id}", [
            'client_id' => 999999, // does not exist
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors']);
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
            'description' => 'Update-endpoint test project',
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
            'task_order' => 1,
            'created_by' => $enquiry->created_by,
        ], $overrides));
    }
}
