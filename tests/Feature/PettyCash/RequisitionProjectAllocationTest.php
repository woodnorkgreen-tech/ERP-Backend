<?php

namespace Tests\Feature\PettyCash;

use App\Constants\EnquiryConstants;
use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the requisition form offers in "Project allocation".
 *
 * The field charges a cash requisition to a job, and the two things it can be
 * charged to are not equivalent. A Project row is only ever created by
 * ActivateProjectAction, at quote approval — so a project is committed, funded
 * work. An enquiry is a job that may never happen.
 *
 * The endpoint returned both lists unordered and the client merged them by
 * comparing `projects.id` with `project_enquiries.id`, two unrelated sequences.
 * On live data that put zero projects in the first twenty options: enquiry ids
 * had simply grown faster. Nobody scrolls 271 enquiries to reach the job they
 * are actually spending on, so the wrong allocation was the easy one.
 */
class RequisitionProjectAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        return Client::create([
            'full_name' => 'Test Client', 'email' => 'client@example.test', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'referral', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
        ]);
    }

    private function enquiry(string $status, string $number, User $user, Client $client): ProjectEnquiry
    {
        return ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'client_id' => $client->id,
            'title' => "Job {$number}",
            'contact_person' => 'Contact',
            'enquiry_number' => $number,
            'status' => $status,
            'created_by' => $user->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(User $user): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/finance/petty-cash/requisitions/form-data')
            ->assertOk()->json();
    }

    /**
     * The ordering rule, stated on data shaped like production: an enquiry whose
     * id is higher than every project's, which is exactly the case the old
     * cross-sequence sort floated to the top.
     */
    public function test_approved_projects_come_before_enquiries(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $client = $this->client();

        $activated = $this->enquiry(EnquiryConstants::STATUS_PLANNING, 'ENQ-001', $user, $client);
        Project::create([
            'enquiry_id' => $activated->id,
            'project_id' => 'WNG-01-2026-001',
            'status' => 'planning',
        ]);

        // Created last, so it holds the highest id in either table.
        $this->enquiry(EnquiryConstants::STATUS_QUOTE_PREPARED, 'ENQ-002', $user, $client);

        $data = $this->formData($user);

        $this->assertCount(1, $data['projects']);
        $this->assertCount(1, $data['enquiries']);

        // The client concatenates the two arrays in this order, so the payload's
        // own shape is what puts approved work first.
        $merged = array_merge($data['projects'], $data['enquiries']);
        $this->assertSame('project', $merged[0]['type']);
        $this->assertSame('WNG-01-2026-001', $merged[0]['project_id']);
    }

    public function test_each_list_is_newest_first(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $client = $this->client();

        foreach (['ENQ-001', 'ENQ-002', 'ENQ-003'] as $number) {
            $this->enquiry(EnquiryConstants::STATUS_QUOTE_PREPARED, $number, $user, $client);
        }

        $ids = array_column($this->formData($user)['enquiries'], 'id');
        $sorted = $ids;
        rsort($sorted);

        $this->assertSame($sorted, $ids, 'The most recent job is the one somebody is spending on.');
    }

    /**
     * Cancelling a job is the clearest statement there is that no more money
     * should go to it. 130 cancelled enquiries were on offer before this.
     */
    public function test_a_cancelled_job_cannot_be_spent_against(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $client = $this->client();

        $this->enquiry(EnquiryConstants::STATUS_CANCELLED, 'ENQ-DEAD', $user, $client);
        $this->enquiry(EnquiryConstants::STATUS_COMPLETED, 'ENQ-DONE', $user, $client);
        $this->enquiry(EnquiryConstants::STATUS_CLOSED, 'ENQ-SHUT', $user, $client);
        $this->enquiry(EnquiryConstants::STATUS_QUOTE_PREPARED, 'ENQ-LIVE', $user, $client);

        $numbers = array_column($this->formData($user)['enquiries'], 'enquiry_number');

        $this->assertSame(['ENQ-LIVE'], $numbers);
    }

    /**
     * The window between "the client said yes" and "somebody pressed activate".
     * The enquiry was excluded by status and had no project row yet, so the job
     * existed in neither list — approved, and unselectable.
     */
    public function test_an_approved_quote_is_selectable_before_it_becomes_a_project(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $client = $this->client();

        $this->enquiry(EnquiryConstants::STATUS_QUOTE_APPROVED, 'ENQ-APPROVED', $user, $client);

        $numbers = array_column($this->formData($user)['enquiries'], 'enquiry_number');

        $this->assertContains('ENQ-APPROVED', $numbers);
    }

    /** Once activated, the project is the answer and the enquiry must not double-list. */
    public function test_an_activated_enquiry_is_offered_only_as_its_project(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $client = $this->client();

        $enquiry = $this->enquiry(EnquiryConstants::STATUS_PLANNING, 'ENQ-ACTIVE', $user, $client);
        Project::create([
            'enquiry_id' => $enquiry->id,
            'project_id' => 'WNG-01-2026-009',
            'status' => 'planning',
        ]);

        $data = $this->formData($user);

        $this->assertSame([], array_column($data['enquiries'], 'enquiry_number'));
        $this->assertSame(['WNG-01-2026-009'], array_column($data['projects'], 'project_id'));
    }
}
