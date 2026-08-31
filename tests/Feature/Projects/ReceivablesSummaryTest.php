<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The receivables headline, computed once on the server.
 *
 * The billing screen used to derive these six figures in the browser, which
 * meant it had to hold every receivables project to show them: it read
 * `last_page` and fired every remaining page in parallel. The totals were only
 * ever right because the client happened to have loaded everything, so anything
 * that capped or failed a page silently understated the book.
 *
 * What these assert is the property that makes the endpoint worth having: the
 * summary must describe the same population the list shows, and must not move
 * when the list is paginated.
 */
class ReceivablesSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(Permissions::FINANCE_RECEIVABLES_READ, 'web');
        $this->reader = User::factory()->create(['is_active' => true]);
        $this->reader->givePermissionTo(Permissions::FINANCE_RECEIVABLES_READ);
    }

    private function client(): Client
    {
        // The factory owns the required-column set; restating it here just means
        // chasing NOT NULL errors every time the schema grows a column.
        return Client::firstWhere('email', 'billing@test.local')
            ?? Client::factory()->create(['email' => 'billing@test.local']);
    }

    private function enquiry(string $status, array $overrides = []): ProjectEnquiry
    {
        return ProjectEnquiry::create(array_merge([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Billing Project',
            'description' => 'Receivables summary test project',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => $status,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-' . uniqid(),
            'created_by' => $this->reader->id,
            'selected_workflow_tasks' => ['design'],
            'workflow_preset_type' => 'external_project',
        ], $overrides));
    }

    public function test_the_summary_requires_the_receivables_permission(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/projects/receivables/summary')
            ->assertForbidden();
    }

    public function test_the_summary_counts_the_whole_book_not_one_page(): void
    {
        // More projects than a page holds. The headline must not depend on how
        // many the client fetched.
        foreach (range(1, 60) as $i) {
            $this->enquiry(EnquiryConstants::STATUS_IN_PROGRESS);
        }

        $summary = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/projects/receivables/summary')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'stats' => ['awaiting_release', 'in_production', 'settled',
                                'total_outstanding', 'total_project_value', 'total_paid'],
                    'tabs' => ['action', 'partial', 'mobilized', 'settled', 'all'],
                ],
            ]);

        $this->assertSame(60, $summary->json('data.tabs.all'));

        // Pin the paginator shape. The client read `last_page` off the payload
        // root, where it does not exist, so its paging branch was dead and the
        // screen silently showed only the first page.
        $this->assertNotNull(
            $this->actingAs($this->reader, 'sanctum')
                ->getJson('/api/projects/enquiries?view=receivables&per_page=50&page=1')
                ->json('data.meta.last_page'),
            'Paginator metadata must live under data.meta for the client to page.',
        );

        // And the list is genuinely paginated beneath it — the two must describe
        // the same book at different granularity, never different books.
        $list = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/projects/enquiries?view=receivables&per_page=50&page=1')
            ->assertOk();

        $this->assertSame(60, $list->json('data.meta.total'));
        $this->assertCount(50, $list->json('data.data'));
    }

    public function test_the_summary_shares_the_list_population(): void
    {
        // Receivables covers these five statuses and nothing else. An enquiry
        // outside them must be absent from both the list and the headline —
        // which is why the summary runs the list's own ViewTypeFilter rather
        // than restating the status set.
        $this->enquiry(EnquiryConstants::STATUS_IN_PROGRESS);
        $this->enquiry('planning');
        $this->enquiry(EnquiryConstants::STATUS_ENQUIRY_LOGGED);   // not receivable

        $summary = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/projects/receivables/summary')->assertOk();
        $list = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/projects/enquiries?view=receivables&per_page=50')->assertOk();

        $this->assertSame(2, $summary->json('data.tabs.all'));
        $this->assertSame($list->json('data.meta.total'), $summary->json('data.tabs.all'));
    }

    public function test_internal_jobs_are_excluded_from_both(): void
    {
        $this->enquiry(EnquiryConstants::STATUS_IN_PROGRESS);
        $this->enquiry(EnquiryConstants::STATUS_IN_PROGRESS, ['workflow_preset_type' => 'internal_job']);

        $summary = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/projects/receivables/summary')->assertOk();
        $list = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/projects/enquiries?view=receivables&per_page=50')->assertOk();

        $this->assertSame($list->json('data.meta.total'), $summary->json('data.tabs.all'));
        $this->assertSame(1, $summary->json('data.tabs.all'));
    }
}
