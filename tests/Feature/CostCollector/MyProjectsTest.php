<?php

namespace Tests\Feature\CostCollector;

use App\Constants\EnquiryConstants;
use App\Models\User;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The project picker's data source.
 *
 * The capture screen previously asked for an enquiry id in a number field — a
 * technician standing at a supplier's counter does not know their job is
 * enquiry 487. This returns the handful they are assigned to.
 */
class MyProjectsTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->technician = User::factory()->create(['is_active' => true]);

        $this->clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid() . '@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function enquiry(string $jobNumber, string $title, string $status = EnquiryConstants::STATUS_IN_PROGRESS): int
    {
        return DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $this->clientId,
            'title' => $title, 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-' . uniqid(), 'job_number' => $jobNumber,
            'status' => $status,
            'created_by' => $this->technician->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assignTask(int $enquiryId, ?User $user): EnquiryTask
    {
        $task = EnquiryTask::create([
            'project_enquiry_id' => $enquiryId,
            'title' => 'Production', 'type' => 'production',
            'created_by' => $this->technician->id,
        ]);

        if ($user) {
            $task->assignedUsers()->attach($user->id);
        }

        return $task;
    }

    public function test_it_returns_only_the_projects_the_caller_is_assigned_to(): void
    {
        $mine = $this->enquiry('WNG-01-2026-001', 'My Activation');
        $this->assignTask($mine, $this->technician);

        $someoneElses = $this->enquiry('WNG-01-2026-002', 'Not Mine');
        $this->assignTask($someoneElses, User::factory()->create());

        $response = $this->actingAs($this->technician, 'sanctum')
            ->getJson('/api/costs/my-projects')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.job_number', 'WNG-01-2026-001');
        $response->assertJsonPath('meta.scope', 'assigned');
    }

    public function test_the_legacy_assignment_column_still_counts(): void
    {
        // Task assignment still writes assigned_user_id in places, so a project
        // assigned the older way must not disappear from someone's picker.
        $enquiryId = $this->enquiry('WNG-01-2026-003', 'Legacy Assigned');
        $task = $this->assignTask($enquiryId, null);
        $task->update(['assigned_user_id' => $this->technician->id]);

        $this->actingAs($this->technician, 'sanctum')
            ->getJson('/api/costs/my-projects')
            ->assertOk()
            ->assertJsonPath('data.0.job_number', 'WNG-01-2026-003');
    }

    public function test_closed_and_cancelled_projects_are_not_offered(): void
    {
        foreach ([EnquiryConstants::STATUS_CLOSED, EnquiryConstants::STATUS_CANCELLED] as $index => $status) {
            $enquiryId = $this->enquiry("WNG-01-2026-10{$index}", 'Finished', $status);
            $this->assignTask($enquiryId, $this->technician);
        }

        $this->actingAs($this->technician, 'sanctum')
            ->getJson('/api/costs/my-projects')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_search_reaches_projects_you_are_not_assigned_to(): void
    {
        // A driver or store keeper routinely reports against a job nobody
        // assigned them to; an assigned-only picker would be a dead end.
        $this->enquiry('WNG-01-2026-050', 'Safaricom Roadshow');

        $response = $this->actingAs($this->technician, 'sanctum')
            ->getJson('/api/costs/my-projects?q=Safaricom')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.job_number', 'WNG-01-2026-050');
        $response->assertJsonPath('meta.scope', 'search');
    }

    public function test_search_also_matches_a_job_number(): void
    {
        $this->enquiry('WNG-07-2026-077', 'Some Project');

        $this->actingAs($this->technician, 'sanctum')
            ->getJson('/api/costs/my-projects?q=07-2026-077')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
