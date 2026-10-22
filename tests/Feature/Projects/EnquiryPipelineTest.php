<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\EnquiryPayment;
use App\Models\GovernanceAuditLog;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Internal vs External pipeline: a job created from "New Internal Job" is
 * internal, and a job can be moved between the pipelines as a governed action.
 */
class EnquiryPipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Super Admin', 'web');
        Permission::findOrCreate(Permissions::PROJECT_LIFECYCLE_GOVERN, 'web');
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('Super Admin');
    }

    private function client(): Client
    {
        return Client::firstWhere('email', 'pipeline@test.local')
            ?? Client::factory()->create(['email' => 'pipeline@test.local']);
    }

    private function enquiry(array $overrides = []): ProjectEnquiry
    {
        return ProjectEnquiry::create(array_merge([
            'date_received' => now()->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Pipeline job',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => 'enquiry_logged',
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-'.uniqid(),
            'created_by' => $this->admin->id,
            'workflow_preset_type' => 'full_event',
        ], $overrides));
    }

    public function test_a_job_created_from_new_internal_job_is_internal(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/projects/enquiries', [
            'date_received' => now()->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Workshop shelving',
            'priority' => 'low',
            'contact_person' => 'Ops',
            'status' => 'enquiry_logged',
            'workflow_preset_type' => '',
            'selected_workflow_tasks' => [],
            'is_non_profit' => true,
        ])->assertCreated();

        $enquiry = ProjectEnquiry::findOrFail($response->json('data.id'));
        $this->assertSame(ProjectEnquiry::DEFAULT_INTERNAL_PRESET, $enquiry->workflow_preset_type);
        $this->assertTrue($response->json('data.is_internal'));

        // And it is listed on the Internal pipeline, not the External one.
        $internal = $this->actingAs($this->admin, 'sanctum')->getJson('/api/projects/enquiries?is_non_profit=1')->json('data.data');
        $external = $this->actingAs($this->admin, 'sanctum')->getJson('/api/projects/enquiries?is_non_profit=0')->json('data.data');
        $this->assertContains($enquiry->id, array_column($internal, 'id'));
        $this->assertNotContains($enquiry->id, array_column($external, 'id'));
    }

    public function test_a_job_created_from_new_request_stays_external(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/projects/enquiries', [
            'date_received' => now()->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Client stand',
            'priority' => 'low',
            'contact_person' => 'Client',
            'status' => 'enquiry_logged',
            'is_non_profit' => false,
        ])->assertCreated();

        $this->assertFalse($response->json('data.is_internal'));
    }

    public function test_switching_needs_the_lifecycle_permission(): void
    {
        $enquiry = $this->enquiry();
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/pipeline", ['pipeline' => 'internal', 'reason' => 'Our own workshop job'])
            ->assertForbidden();
        $this->assertSame('full_event', $enquiry->fresh()->workflow_preset_type);
    }

    public function test_a_job_moves_both_ways_with_a_reason_and_returns_to_its_kind(): void
    {
        $enquiry = $this->enquiry();
        $url = "/api/projects/enquiries/{$enquiry->id}/pipeline";

        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['pipeline' => 'internal', 'reason' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['pipeline' => 'internal', 'reason' => 'Logged as a client job by mistake'])
            ->assertOk()->assertJsonPath('data.is_internal', true);
        $this->assertSame('internal_job', $enquiry->fresh()->workflow_preset_type);

        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['pipeline' => 'internal', 'reason' => 'Logged as a client job by mistake'])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['pipeline' => 'external', 'reason' => 'Client is paying after all'])
            ->assertOk()->assertJsonPath('data.is_internal', false);
        // It goes back to the kind of job it was, not to "no preset".
        $this->assertSame('full_event', $enquiry->fresh()->workflow_preset_type);

        $logs = GovernanceAuditLog::where('project_enquiry_id', $enquiry->id)->where('gate_type', 'Pipeline Change')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['from' => 'full_event', 'to' => 'internal_job', 'reason' => 'Logged as a client job by mistake'], $logs[0]->context);
        $this->assertSame($this->admin->id, $logs[1]->user_id);
    }

    public function test_a_job_with_client_receipts_cannot_become_internal(): void
    {
        $enquiry = $this->enquiry();
        EnquiryPayment::create([
            'project_enquiry_id' => $enquiry->id, 'amount' => 50000, 'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer', 'status' => 'pending', 'recorded_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/pipeline", ['pipeline' => 'internal', 'reason' => 'Logged as a client job by mistake'])
            ->assertStatus(422)->assertJsonPath('message', 'This job has client invoices or receipts, so it is billed to a client and must stay external.');
        $this->assertSame('full_event', $enquiry->fresh()->workflow_preset_type);
    }

    public function test_a_general_edit_cannot_move_a_job_between_pipelines(): void
    {
        $enquiry = $this->enquiry();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/projects/enquiries/{$enquiry->id}", ['title' => 'Renamed', 'workflow_preset_type' => 'internal_job'])
            ->assertOk();

        $fresh = $enquiry->fresh();
        $this->assertSame('Renamed', $fresh->title);
        $this->assertSame('full_event', $fresh->workflow_preset_type);
    }
}
