<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ProjectEnquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnquiryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create and authenticate a user with enquiry.create permission
        $user = User::factory()->create();
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'enquiry.create']);
        $user->givePermissionTo($permission);
        $this->actingAs($user);
    }

    /** @test */
    public function it_creates_enquiry_successfully()
    {
        // Create a test client first
        $client = \App\Modules\ClientService\Models\Client::factory()->create();

        $enquiryData = [
            'date_received' => '2024-01-15',
            'expected_delivery_date' => '2024-02-15',
            'client_id' => $client->id,
            'title' => 'Website Development Project',
            'description' => 'Complete website development for client',
            'project_scope' => 'Full website development',
            'contact_person' => 'John Doe',
            'status' => 'enquiry_logged',
            'venue' => 'Online',
            'site_survey_skipped' => false,
        ];

        $response = $this->postJson('/api/clientservice/enquiries', $enquiryData);

        $response->assertStatus(201)
                 ->assertJson([
                     'message' => 'Enquiry created successfully',
                     'data' => [
                         'title' => 'Website Development Project',
                         'status' => 'enquiry_logged',
                     ]
                 ]);

        $this->assertDatabaseHas('project_enquiries', [
            'title' => 'Website Development Project',
            'client_id' => $client->id,
            'status' => 'enquiry_logged',
        ]);
    }

    /** @test */
    public function it_fails_validation_with_missing_required_fields()
    {
        $incompleteData = [
            'project_name' => 'Test Enquiry',
            // Missing date_received, client_name, project_deliverables, contact_person
        ];

        $response = $this->postJson('/api/clientservice/enquiries', $incompleteData);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['date_received', 'client_name', 'project_deliverables', 'contact_person']);
    }

    /** @test */
    public function it_fails_validation_with_invalid_expected_delivery_date()
    {
        $invalidData = [
            'date_received' => '2024-01-15',
            'expected_delivery_date' => '2024-01-10', // Before date_received
            'client_name' => 'ABC Corporation',
            'project_name' => 'Test Project',
            'project_deliverables' => 'Test deliverables',
            'contact_person' => 'John Doe',
            'status' => 'enquiry_logged',
        ];

        $response = $this->postJson('/api/clientservice/enquiries', $invalidData);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['expected_delivery_date']);
    }

    /** @test */
    public function it_fails_validation_with_invalid_status()
    {
        $invalidData = [
            'date_received' => '2024-01-15',
            'client_name' => 'ABC Corporation',
            'project_name' => 'Test Project',
            'project_deliverables' => 'Test deliverables',
            'contact_person' => 'John Doe',
            'status' => 'invalid_status',
        ];

        $response = $this->postJson('/api/clientservice/enquiries', $invalidData);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['status']);
    }

    /** @test */
    public function it_fails_validation_with_site_survey_skip_reason_required_when_skipped()
    {
        $invalidData = [
            'date_received' => '2024-01-15',
            'client_name' => 'ABC Corporation',
            'project_name' => 'Test Project',
            'project_deliverables' => 'Test deliverables',
            'contact_person' => 'John Doe',
            'status' => 'enquiry_logged',
            'site_survey_skipped' => true,
            // Missing site_survey_skip_reason
        ];

        $response = $this->postJson('/api/clientservice/enquiries', $invalidData);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['site_survey_skip_reason']);
    }

    /** @test */
    public function it_lists_enquiries()
    {
        // Create some test enquiries
        Enquiry::create([
            'date_received' => '2024-01-15',
            'client_name' => 'ABC Corporation',
            'project_name' => 'Project 1',
            'project_deliverables' => 'Deliverables 1',
            'contact_person' => 'John Doe',
            'status' => 'enquiry_logged',
            'enquiry_number' => 'ENQ-2024-0001',
            'created_by' => auth()->id(),
        ]);

        Enquiry::create([
            'date_received' => '2024-01-16',
            'client_name' => 'XYZ Corp',
            'project_name' => 'Project 2',
            'project_deliverables' => 'Deliverables 2',
            'contact_person' => 'Jane Smith',
            'status' => 'client_registered',
            'enquiry_number' => 'ENQ-2024-0002',
            'created_by' => auth()->id(),
        ]);

        $response = $this->getJson('/api/clientservice/enquiries');

        $response->assertStatus(200)
                 ->assertJson([
                     'message' => 'Enquiries retrieved successfully'
                 ])
                 ->assertJsonCount(2, 'data.data');
    }

    /** @test */
    public function it_shows_single_enquiry()
    {
        $enquiry = ProjectEnquiry::create([
            'date_received' => '2024-01-15',
            'client_name' => 'ABC Corporation',
            'project_name' => 'Test Project',
            'project_deliverables' => 'Test deliverables',
            'contact_person' => 'John Doe',
            'status' => 'enquiry_logged',
            'enquiry_number' => 'ENQ-2024-0001',
            'created_by' => auth()->id(),
        ]);

        $response = $this->getJson("/api/clientservice/enquiries/{$enquiry->id}");

        $response->assertStatus(200)
                 ->assertJson([
                     'message' => 'Enquiry retrieved successfully',
                     'data' => [
                         'id' => $enquiry->id,
                         'project_name' => 'Test Project',
                         'client_name' => 'ABC Corporation',
                     ]
                 ]);
    }

    /** @test */
    public function it_updates_enquiry()
    {
        $enquiry = Enquiry::create([
            'date_received' => '2024-01-15',
            'client_name' => 'ABC Corporation',
            'project_name' => 'Test Project',
            'project_deliverables' => 'Test deliverables',
            'contact_person' => 'John Doe',
            'status' => 'enquiry_logged',
            'enquiry_number' => 'ENQ-2024-0001',
            'created_by' => auth()->id(),
        ]);

        $updateData = [
            'project_name' => 'Updated Project Name',
            'status' => 'design_completed',
        ];

        $response = $this->putJson("/api/clientservice/enquiries/{$enquiry->id}", $updateData);

        $response->assertStatus(200)
                 ->assertJson([
                     'message' => 'Enquiry updated successfully',
                     'data' => [
                         'project_name' => 'Updated Project Name',
                         'status' => 'design_completed',
                     ]
                 ]);

        $this->assertDatabaseHas('enquiries', [
            'id' => $enquiry->id,
            'project_name' => 'Updated Project Name',
            'status' => 'design_completed',
        ]);
    }

    /** @test */
    public function it_deletes_enquiry()
    {
        $enquiry = Enquiry::create([
            'date_received' => '2024-01-15',
            'client_name' => 'ABC Corporation',
            'project_name' => 'Test Project',
            'project_deliverables' => 'Test deliverables',
            'contact_person' => 'John Doe',
            'status' => 'enquiry_logged',
            'enquiry_number' => 'ENQ-2024-0001',
            'created_by' => auth()->id(),
        ]);

        $response = $this->deleteJson("/api/clientservice/enquiries/{$enquiry->id}");

        $response->assertStatus(200)
                 ->assertJson([
                     'message' => 'Enquiry deleted successfully'
                 ]);

        $this->assertDatabaseMissing('enquiries', [
            'id' => $enquiry->id,
        ]);
    }


}
