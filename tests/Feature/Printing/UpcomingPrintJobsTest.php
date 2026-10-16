<?php

namespace Tests\Feature\Printing;

use App\Models\User;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GrantsMatrixPermissions;
use Tests\TestCase;

/**
 * Upcoming projects for printing: every design-workflow project due in the window,
 * with all its deliverables marked assigned (design has a task) or awaiting design.
 */
class UpcomingPrintJobsTest extends TestCase
{
    use GrantsMatrixPermissions;
    use RefreshDatabase;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Upcoming Client', 'email' => 'upcoming@example.test', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function loginPrinter(): User
    {
        $this->grantMatrixPermissions('Printing');
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Role::findOrCreate('Printing', 'web'));
        Sanctum::actingAs($user);

        return $user;
    }

    private function enquiry(string $title, ?string $date): int
    {
        return DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $this->clientId,
            'title' => $title, 'contact_person' => 'Project officer',
            'enquiry_number' => uniqid('ENQ-UP-'), 'expected_delivery_date' => $date,
            'delivery_date_status' => $date ? 'confirmed' : 'tbc',
            'created_by' => User::factory()->create()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function deliverable(int $enquiryId, string $name, ?string $archivedAt = null): int
    {
        return DB::table('project_deliverables')->insertGetId([
            'enquiry_id' => $enquiryId, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'persistent_id' => uniqid('el-'), 'element_type' => 'graphic', 'classification' => 'graphic',
            'name' => $name, 'category' => 'production', 'is_included' => true,
            'archived_at' => $archivedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function designJob(int $enquiryId, string $title, string $status = 'pending'): DesignJob
    {
        return DesignJob::create([
            'project_enquiry_id' => $enquiryId, 'title' => $title, 'job_number' => 'UP-'.uniqid(),
            'status' => $status, 'created_by' => User::factory()->create()->id,
        ]);
    }

    private function upcoming(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/printing/upcoming-jobs?'.http_build_query($query));
    }

    public function test_project_without_design_tasks_is_visible_with_deliverables_awaiting_design(): void
    {
        $this->loginPrinter();
        $enquiry = $this->enquiry('Exhibition booth', now()->addDays(10)->toDateString());
        $this->deliverable($enquiry, 'Entrance banner');
        $this->designJob($enquiry, 'Exhibition booth');

        $this->upcoming()->assertOk()
            ->assertJsonPath('data.0.project_name', 'Exhibition booth')
            ->assertJsonPath('data.0.deliverables.0.name', 'Entrance banner')
            ->assertJsonPath('data.0.deliverables.0.planning_status', 'awaiting_design')
            ->assertJsonCount(0, 'data.0.deliverables.0.design_items');
    }

    public function test_deliverable_with_a_design_task_is_assigned_and_shows_the_designer(): void
    {
        $printer = $this->loginPrinter();
        $enquiry = $this->enquiry('Trade fair', now()->addDays(10)->toDateString());
        $banner = $this->deliverable($enquiry, 'Banner');
        $this->deliverable($enquiry, 'Brochure');
        $job = $this->designJob($enquiry, 'Trade fair');
        $designer = User::factory()->create(['name' => 'Ada Designer']);
        DesignItem::create([
            'design_job_id' => $job->id, 'stream' => 'graphic', 'title' => 'Banner artwork',
            'status' => 'in_design', 'project_deliverable_id' => $banner, 'assigned_to' => $designer->id,
        ]);

        $this->upcoming()->assertOk()
            ->assertJsonPath('data.0.deliverables.0.name', 'Banner')
            ->assertJsonPath('data.0.deliverables.0.planning_status', 'assigned')
            ->assertJsonPath('data.0.deliverables.0.design_items.0.designer_name', 'Ada Designer')
            ->assertJsonPath('data.0.deliverables.1.name', 'Brochure')
            ->assertJsonPath('data.0.deliverables.1.planning_status', 'awaiting_design');
    }

    public function test_every_design_task_on_a_deliverable_is_listed_with_its_designer(): void
    {
        $this->loginPrinter();
        $enquiry = $this->enquiry('Two tasks', now()->addDays(10)->toDateString());
        $banner = $this->deliverable($enquiry, 'Banner');
        $job = $this->designJob($enquiry, 'Two tasks');
        $designer = User::factory()->create(['name' => 'Kevin Musame']);
        DesignItem::create(['design_job_id' => $job->id, 'stream' => 'graphic', 'title' => 'Banner artwork',
            'status' => 'awaiting_client_approval', 'project_deliverable_id' => $banner, 'assigned_to' => null]);
        DesignItem::create(['design_job_id' => $job->id, 'stream' => 'graphic', 'title' => 'Banner artwork revision',
            'status' => 'pending', 'project_deliverable_id' => $banner, 'assigned_to' => $designer->id]);

        $this->upcoming()->assertOk()
            ->assertJsonPath('data.0.deliverables.0.planning_status', 'assigned')
            ->assertJsonCount(2, 'data.0.deliverables.0.design_items')
            ->assertJsonPath('data.0.deliverables.0.design_items.0.designer_name', null)
            ->assertJsonPath('data.0.deliverables.0.design_items.1.designer_name', 'Kevin Musame');
    }

    public function test_archived_deliverables_and_cancelled_design_jobs_are_excluded(): void
    {
        $this->loginPrinter();
        $enquiry = $this->enquiry('Archived scope', now()->addDays(10)->toDateString());
        $this->deliverable($enquiry, 'Old banner', now()->toDateTimeString());
        $this->designJob($enquiry, 'Archived scope');

        $cancelled = $this->enquiry('Cancelled job', now()->addDays(10)->toDateString());
        $this->deliverable($cancelled, 'Sign');
        $this->designJob($cancelled, 'Cancelled job', 'cancelled');

        $this->upcoming()->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_project_outside_the_upcoming_window_is_not_listed(): void
    {
        $this->loginPrinter();
        $past = $this->enquiry('Already delivered', now()->subDays(10)->toDateString());
        $this->deliverable($past, 'Banner');
        $this->designJob($past, 'Already delivered');

        $this->upcoming()->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_finds_a_project_by_name(): void
    {
        $this->loginPrinter();
        $match = $this->enquiry('Lakeside launch', now()->addDays(5)->toDateString());
        $this->deliverable($match, 'Sign');
        $this->designJob($match, 'Lakeside launch');

        $other = $this->enquiry('Office fit-out', now()->addDays(6)->toDateString());
        $this->deliverable($other, 'Panel');
        $this->designJob($other, 'Office fit-out');

        $this->upcoming(['search' => 'Lakeside'])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.project_name', 'Lakeside launch');
    }

    public function test_requires_printing_access(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->upcoming()->assertForbidden();
    }
}
