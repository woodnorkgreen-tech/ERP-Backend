<?php

namespace Tests\Feature\Printing;

use App\Models\User;
use App\Modules\Printing\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualPrintJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Role::findOrCreate('Printing', 'web'));
        Sanctum::actingAs($user);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Rush site banner',
            'requested_by_name' => 'Site supervisor',
            'request_source' => 'site_request',
            'due_date' => '2026-09-18',
            'priority' => 'urgent',
            'bypass_reason' => 'Replacement was requested directly from site.',
            'running_length_m' => 12.5,
            'artwork_quantity' => 1,
        ], $overrides);
    }

    public function test_printing_can_create_a_standalone_manual_job(): void
    {
        $response = $this->postJson('/api/printing/jobs', $this->payload())->assertCreated();

        $response->assertJsonPath('data.origin', 'manual')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.priority', 'urgent')
            ->assertJsonPath('data.project_id', null);
        $this->assertStringStartsWith('PRN-MAN-', $response->json('data.job_number'));
        $job = PrintJob::findOrFail($response->json('data.id'));
        $this->assertDatabaseHas('print_job_events', [
            'print_job_id' => $job->id,
            'event_type' => 'manual_job_created',
        ]);
    }

    public function test_project_details_are_derived_instead_of_accepted_from_the_form(): void
    {
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Acme Limited', 'email' => uniqid().'@test.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Acme launch', 'contact_person' => 'Jane',
            'enquiry_number' => 'ENQ-MAN-1', 'job_number' => 'WNG-MAN-1',
            'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $projectId = DB::table('projects')->insertGetId([
            'enquiry_id' => $enquiryId, 'project_id' => 'PRJ-MAN-1', 'status' => 'in_progress',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/printing/jobs', $this->payload([
            'project_id' => $projectId,
            'project_name' => 'Fake project',
            'client_name' => 'Fake client',
        ]))->assertCreated()
            ->assertJsonPath('data.job_number', 'WNG-MAN-1')
            ->assertJsonPath('data.project_name', 'Acme launch')
            ->assertJsonPath('data.client_name', 'Acme Limited')
            ->assertJsonPath('data.project_enquiry_id', $enquiryId);
    }

    public function test_manual_traceability_fields_are_required(): void
    {
        $this->postJson('/api/printing/jobs', ['title' => 'Untraceable rush job'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_date', 'priority', 'request_source', 'requested_by_name', 'bypass_reason']);
    }
}
