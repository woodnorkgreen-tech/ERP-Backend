<?php

namespace Tests\Feature\Procurement;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\ProcurementStores\Models\Requisition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The requisition register's search hit `projects.project_name` and
 * `projects.project_code`, `employees.employee_number` and
 * `departments.department_name` — none of which exist on those tables. Any
 * search term at all 500'd, which read to a buyer as "the requisition I just
 * raised an order from has vanished" rather than as a broken search box.
 *
 * A project requisition's identity lives on the enquiry the Project belongs
 * to (or, for rows predating a Project record, directly on the enquiry via
 * `projectEnquiry`) — not on the `projects` table itself, which carries no
 * name of its own.
 */
class RequisitionSearchTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/procurement-stores/search/requisitions';

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::create([
            'name' => 'Buyer',
            'email' => uniqid('buyer_').'@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]));
    }

    private function client(): Client
    {
        return Client::create([
            'full_name' => 'Test Client', 'email' => uniqid('client_').'@example.test', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'referral', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
        ]);
    }

    public function test_search_by_requisition_number_does_not_500(): void
    {
        $department = Department::create(['name' => 'Stores']);
        Requisition::create([
            'requisition_number' => 'PR-2026-9001',
            'date' => now()->toDateString(),
            'requested_by_type' => 'office',
            'department_id' => $department->id,
            'urgency' => 'normal',
            'total_amount' => 3000,
            'status' => 'approved',
            'user_id' => auth()->id(),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['searchTerm' => 'PR-2026-9001']);

        $response->assertOk();
        $this->assertSame('PR-2026-9001', $response->json('data.0.requisition_number'));
    }

    public function test_search_by_department_name_does_not_500(): void
    {
        $department = Department::create(['name' => 'Fabrication']);
        Requisition::create([
            'requisition_number' => 'PR-2026-9002',
            'date' => now()->toDateString(),
            'requested_by_type' => 'office',
            'department_id' => $department->id,
            'urgency' => 'normal',
            'total_amount' => 1000,
            'status' => 'approved',
            'user_id' => auth()->id(),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['searchTerm' => 'Fabrication']);

        $response->assertOk();
        $this->assertSame('PR-2026-9002', $response->json('data.0.requisition_number'));
    }

    public function test_search_by_employee_number_does_not_500(): void
    {
        $department = Department::create(['name' => 'Warehouse']);
        $employee = Employee::create([
            'employee_id' => 'EMP-9003',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'department_id' => $department->id,
            'position' => 'Storekeeper',
            'hire_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        Requisition::create([
            'requisition_number' => 'PR-2026-9003',
            'date' => now()->toDateString(),
            'requested_by_type' => 'employee',
            'employee_id' => $employee->id,
            'urgency' => 'normal',
            'total_amount' => 500,
            'status' => 'approved',
            'user_id' => auth()->id(),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['searchTerm' => 'EMP-9003']);

        $response->assertOk();
        $this->assertSame('PR-2026-9003', $response->json('data.0.requisition_number'));
    }

    /** project_id points at a Project row; the name lives on the enquiry it belongs to. */
    public function test_search_by_project_enquiry_title_reaches_through_the_project(): void
    {
        $user = User::first();
        $client = $this->client();
        $enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'client_id' => $client->id,
            'title' => 'Riverside Mall Activation',
            'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-9004',
            'status' => 'quote_approved',
            'created_by' => $user->id,
        ]);
        $project = Project::create([
            'enquiry_id' => $enquiry->id,
            'project_id' => 'WNG-01-2026-9004',
            'status' => 'planning',
        ]);
        Requisition::create([
            'requisition_number' => 'PR-2026-9004',
            'date' => now()->toDateString(),
            'requested_by_type' => 'project',
            'project_id' => $project->id,
            'urgency' => 'normal',
            'total_amount' => 8000,
            'status' => 'approved',
            'user_id' => auth()->id(),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['searchTerm' => 'Riverside Mall']);

        $response->assertOk();
        $this->assertSame('PR-2026-9004', $response->json('data.0.requisition_number'));
    }

    /** project_id points at a Project row; searching the Project's own code also has to work. */
    public function test_search_by_project_code_does_not_500(): void
    {
        $user = User::first();
        $client = $this->client();
        $enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'client_id' => $client->id,
            'title' => 'Warehouse Fit-out',
            'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-9005',
            'status' => 'quote_approved',
            'created_by' => $user->id,
        ]);
        $project = Project::create([
            'enquiry_id' => $enquiry->id,
            'project_id' => 'WNG-01-2026-9005',
            'status' => 'planning',
        ]);
        Requisition::create([
            'requisition_number' => 'PR-2026-9005',
            'date' => now()->toDateString(),
            'requested_by_type' => 'project',
            'project_id' => $project->id,
            'urgency' => 'normal',
            'total_amount' => 8000,
            'status' => 'approved',
            'user_id' => auth()->id(),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['searchTerm' => 'WNG-01-2026-9005']);

        $response->assertOk();
        $this->assertSame('PR-2026-9005', $response->json('data.0.requisition_number'));
    }

    /** Rows predating a Project record store the enquiry id directly in project_id. */
    public function test_search_falls_back_to_project_enquiry_relation_with_no_project_record(): void
    {
        $user = User::first();
        $client = $this->client();
        $enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'client_id' => $client->id,
            'title' => 'Legacy Enquiry Without A Project Row',
            'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-9006',
            'status' => 'quote_approved',
            'created_by' => $user->id,
        ]);
        Requisition::create([
            'requisition_number' => 'PR-2026-9006',
            'date' => now()->toDateString(),
            'requested_by_type' => 'project',
            'project_id' => $enquiry->id,
            'urgency' => 'normal',
            'total_amount' => 8000,
            'status' => 'approved',
            'user_id' => auth()->id(),
        ]);

        $response = $this->postJson(self::ENDPOINT, ['searchTerm' => 'Legacy Enquiry Without']);

        $response->assertOk();
        $this->assertSame('PR-2026-9006', $response->json('data.0.requisition_number'));
    }
}
