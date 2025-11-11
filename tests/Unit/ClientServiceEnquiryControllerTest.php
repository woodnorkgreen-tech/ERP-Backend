<?php

namespace Tests\Unit;

use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\Projects\Models\TaskDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ClientServiceEnquiryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create task definitions for testing
        TaskDefinition::create([
            'name' => 'Conduct Site Survey',
            'description' => 'Conduct initial site survey',
            'order' => 1,
            'conditions' => null,
            'dependencies' => null,
        ]);

        TaskDefinition::create([
            'name' => 'Design Assets and Material Specification',
            'description' => 'Create design assets and specify materials',
            'order' => 2,
            'conditions' => null,
            'dependencies' => null,
        ]);
    }

    /** @test */
    public function it_creates_enquiry_with_valid_data()
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($user);

        $enquiryData = [
            'date_received' => '2025-09-30',
            'expected_delivery_date' => '2025-10-15',
            'client_id' => $client->id,
            'title' => 'Test Enquiry',
            'description' => 'Test enquiry description',
            'project_scope' => 'Full project scope',
            'priority' => 'high',
            'contact_person' => 'John Doe',
            'status' => 'enquiry_logged',
            'venue' => 'Test Venue',
        ];

        $response = $this->postJson('/api/clientservice/enquiries', $enquiryData);

        $response->assertStatus(201)
                 ->assertJson([
                     'message' => 'Enquiry created successfully',
                     'data' => [
                         'title' => 'Test Enquiry',
                         'description' => 'Test enquiry description',
                         'client_id' => $client->id,
                         'contact_person' => 'John Doe',
                         'status' => 'enquiry_logged',
                     ]
                 ]);

        // Verify enquiry was created in database
        $this->assertDatabaseHas('project_enquiries', [
            'title' => 'Test Enquiry',
            'client_id' => $client->id,
            'created_by' => $user->id,
        ]);

        // Verify enquiry number was generated
        $enquiry = ProjectEnquiry::where('title', 'Test Enquiry')->first();
        $this->assertNotNull($enquiry->enquiry_number);
        $this->assertStringStartsWith('WNG-' . date('m') . '-2025-', $enquiry->enquiry_number);
    }

    /** @test */
    public function it_creates_tasks_when_enquiry_is_created()
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($user);

        $enquiryData = [
            'date_received' => '2025-09-30',
            'client_id' => $client->id,
            'title' => 'Test Enquiry with Tasks',
            'description' => 'Test enquiry description',
            'contact_person' => 'John Doe',
            'status' => 'enquiry_logged',
        ];

        $this->postJson('/api/clientservice/enquiries', $enquiryData);

        $enquiry = ProjectEnquiry::where('title', 'Test Enquiry with Tasks')->first();

        // Verify tasks were created
        $this->assertDatabaseHas('enquiry_tasks', [
            'project_enquiry_id' => $enquiry->id,
            'task_name' => 'Conduct Site Survey',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('enquiry_tasks', [
            'project_enquiry_id' => $enquiry->id,
            'task_name' => 'Design Assets and Material Specification',
            'status' => 'pending',
        ]);

        // Verify correct number of tasks created
        $taskCount = EnquiryTask::where('project_enquiry_id', $enquiry->id)->count();
        $this->assertEquals(2, $taskCount);
    }

    /** @test */
    public function it_validates_required_fields()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $invalidData = [
            // Missing required fields
        ];

        $response = $this->postJson('/api/clientservice/enquiries', $invalidData);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['date_received', 'client_id', 'title', 'description', 'contact_person', 'status']);
    }

    /** @test */
    public function it_handles_enquiry_title_alias()
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($user);

        $enquiryData = [
            'date_received' => '2025-09-30',
            'client_id' => $client->id,
            'enquiry_title' => 'Test Enquiry with Alias', // Using enquiry_title instead of title
            'description' => 'Test enquiry description',
            'contact_person' => 'John Doe',
            'status' => 'enquiry_logged',
        ];

        $response = $this->postJson('/api/clientservice/enquiries', $enquiryData);

        $response->assertStatus(201);

        // Verify title was set correctly
        $this->assertDatabaseHas('project_enquiries', [
            'title' => 'Test Enquiry with Alias',
        ]);
    }

    /** @test */
    public function it_generates_unique_enquiry_numbers()
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($user);

        // Create first enquiry
        $this->postJson('/api/clientservice/enquiries', [
            'date_received' => '2025-09-30',
            'client_id' => $client->id,
            'title' => 'First Enquiry',
            'description' => 'Test description',
            'contact_person' => 'John Doe',
            'status' => 'enquiry_logged',
        ]);

        // Create second enquiry
        $this->postJson('/api/clientservice/enquiries', [
            'date_received' => '2025-09-30',
            'client_id' => $client->id,
            'title' => 'Second Enquiry',
            'description' => 'Test description',
            'contact_person' => 'Jane Doe',
            'status' => 'enquiry_logged',
        ]);

        $enquiries = ProjectEnquiry::orderBy('created_at')->get();

        $this->assertEquals(2, $enquiries->count());
        $this->assertNotEquals($enquiries[0]->enquiry_number, $enquiries[1]->enquiry_number);
    }
}
