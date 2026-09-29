<?php

namespace Tests\Feature\Printing;

use App\Models\User;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignJob;
use App\Modules\Printing\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectSetupScheduleTest extends TestCase
{
    use RefreshDatabase;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 24)->startOfDay());
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
        Sanctum::actingAs($user);
        $this->clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Setup Schedule Client', 'email' => 'setup@example.test', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function enquiry(?string $date): int
    {
        return DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $this->clientId,
            'title' => 'Setup project', 'contact_person' => 'Project officer',
            'enquiry_number' => uniqid('ENQ-SETUP-'), 'expected_delivery_date' => $date,
            'delivery_date_status' => $date ? 'confirmed' : 'tbc',
            'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function design(?string $date, array $attributes = []): DesignJob
    {
        return DesignJob::create(array_merge([
            'project_enquiry_id' => $this->enquiry($date), 'title' => 'Design for setup',
            'status' => 'pending', 'due_date' => '2026-01-01',
        ], $attributes));
    }

    private function printJob(?string $date, array $attributes = []): PrintJob
    {
        return PrintJob::create(array_merge([
            'project_enquiry_id' => $this->enquiry($date), 'title' => 'Printing for setup',
            'status' => 'queued', 'due_date' => '2026-01-01',
        ], $attributes));
    }

    public function test_design_sorts_before_pagination_and_filters_using_the_current_project_date(): void
    {
        $near = $this->design('2026-09-25', ['due_date' => '2026-12-01']);
        $far = $this->design('2026-10-20');
        $tbc = $this->design(null);
        $done = $this->design('2026-09-01', ['status' => 'handed_off']);

        $this->getJson('/api/design/jobs?per_page=1')->assertOk()
            ->assertJsonPath('total', 4)->assertJsonPath('data.0.id', $near->id)
            ->assertJsonPath('data.0.project_setup_date', '2026-09-25')
            ->assertJsonPath('data.0.due_date', '2026-12-01');
        $this->getJson('/api/design/jobs?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $far->id);
        $this->getJson('/api/design/jobs?per_page=1&page=3')->assertOk()
            ->assertJsonPath('data.0.id', $tbc->id)->assertJsonPath('data.0.project_setup_date', null);
        $this->getJson('/api/design/jobs?per_page=1&page=4')->assertOk()->assertJsonPath('data.0.id', $done->id);
        $this->getJson('/api/design/jobs?due_within_days=7')->assertOk()
            ->assertJsonPath('total', 3)->assertJsonPath('counts.all', 3)->assertJsonPath('counts.pending', 2);

        DB::table('project_enquiries')->where('id', $near->project_enquiry_id)->update(['expected_delivery_date' => '2026-11-01']);
        $this->getJson('/api/design/jobs?due_within_days=7')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('counts.pending', 1);
    }

    public function test_printing_uses_live_project_dates_ahead_of_status_and_creation_order(): void
    {
        $near = $this->printJob('2026-09-25', ['status' => 'printing', 'due_date' => '2026-12-01']);
        $far = $this->printJob('2026-10-20');
        $tbc = $this->printJob(null);
        $completed = $this->printJob('2026-09-01', ['status' => 'completed']);

        foreach ([$near, $far, $tbc, $completed] as $index => $job) {
            $this->getJson('/api/printing/jobs?per_page=1&page='.($index + 1))->assertOk()
                ->assertJsonPath('total', 4)->assertJsonPath('data.0.id', $job->id);
        }

        DB::table('project_enquiries')->where('id', $near->project_enquiry_id)->update(['expected_delivery_date' => null]);
        $this->getJson('/api/printing/jobs?per_page=1')->assertOk()->assertJsonPath('data.0.id', $far->id);
        $this->getJson('/api/printing/jobs/'.$near->id)->assertOk()
            ->assertJsonPath('data.project_setup_date', null)->assertJsonPath('data.due_date', '2026-12-01');

        DB::table('project_enquiries')->where('id', $tbc->project_enquiry_id)->update(['expected_delivery_date' => '2026-09-24']);
        $this->getJson('/api/printing/jobs?per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $tbc->id)->assertJsonPath('data.0.project_setup_date', '2026-09-24');
    }

    public function test_upcoming_printing_sorts_by_parent_setup_and_keeps_tbc_last(): void
    {
        $items = [];
        foreach (['2026-09-25', '2026-10-20', null] as $date) {
            $job = $this->design($date);
            $items[] = DesignItem::create(['design_job_id' => $job->id, 'stream' => 'graphic', 'title' => 'Setup artwork', 'status' => 'in_design']);
        }
        foreach ($items as $index => $item) {
            $this->getJson('/api/printing/upcoming-jobs?per_page=1&page='.($index + 1))->assertOk()
                ->assertJsonPath('total', 3)->assertJsonPath('data.0.design_item_id', $item->id);
        }
        $this->getJson('/api/printing/upcoming-jobs?per_page=1&page=3')->assertOk()->assertJsonPath('data.0.project_setup_date', null);

        DB::table('project_enquiries')->where('id', $items[2]->job->project_enquiry_id)->update(['expected_delivery_date' => '2026-09-24']);
        $this->getJson('/api/printing/upcoming-jobs?per_page=1')->assertOk()
            ->assertJsonPath('data.0.design_item_id', $items[2]->id)->assertJsonPath('data.0.project_setup_date', '2026-09-24');
    }

    public function test_project_only_links_resolve_the_same_date_in_all_tables(): void
    {
        $projectId = DB::table('projects')->insertGetId([
            'enquiry_id' => $this->enquiry('2026-09-28'), 'project_id' => 'PRJ-SETUP', 'status' => 'in_progress',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $design = DesignJob::create(['project_id' => $projectId, 'title' => 'Project-only design']);
        $printing = PrintJob::create(['project_id' => $projectId, 'title' => 'Project-only print']);
        DesignItem::create(['design_job_id' => $design->id, 'stream' => 'graphic', 'title' => 'Project-only artwork']);

        foreach (['/api/design/jobs', '/api/printing/jobs', '/api/printing/upcoming-jobs'] as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('data.0.project_setup_date', '2026-09-28');
        }
        $this->getJson('/api/design/jobs/'.$design->id)->assertOk()->assertJsonPath('data.project_setup_date', '2026-09-28');
        $this->getJson('/api/printing/jobs/'.$printing->id)->assertOk()->assertJsonPath('data.project_setup_date', '2026-09-28');
    }

    public function test_design_overdue_filter_uses_parent_dates_and_counts_before_pagination(): void
    {
        $older = $this->design('2026-09-20');
        $recent = $this->design('2026-09-23', ['due_date' => '2026-12-01']);
        $this->design('2026-09-24');
        $this->design('2026-09-25');
        $this->design(null);
        $this->design('2026-09-01', ['status' => 'handed_off']);
        $this->design('2026-09-01', ['status' => 'cancelled']);

        $this->getJson('/api/design/jobs?overdue_only=1&per_page=1')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('counts.all', 2)
            ->assertJsonPath('counts.pending', 2)->assertJsonPath('counts.cancelled', 0)
            ->assertJsonPath('data.0.id', $older->id);
        $this->getJson('/api/design/jobs?overdue_only=1&per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.0.id', $recent->id)->assertJsonPath('data.0.project_setup_date', '2026-09-23');
        DB::table('project_enquiries')->where('id', $recent->project_enquiry_id)->update(['expected_delivery_date' => null]);
        $this->getJson('/api/design/jobs?overdue_only=1')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/design/jobs?overdue_only=invalid')->assertUnprocessable();
    }

    public function test_graphic_and_structural_overdue_filters_exclude_finished_and_unconfirmed_work(): void
    {
        $designer = User::factory()->create(['name' => 'Amina Designer']);
        $overdue = $this->design('2026-09-23', ['due_date' => '2026-12-01']);
        $today = $this->design('2026-09-24');
        $tbc = $this->design(null);
        $closed = $this->design('2026-09-01', ['status' => 'handed_off']);

        foreach (['graphic', 'structural'] as $stream) {
            $active = [];
            foreach (['pending', 'in_design', 'done', 'cancelled', 'print_ready', 'production_ready', 'handed_off'] as $status) {
                $item = DesignItem::create([
                    'design_job_id' => $overdue->id, 'stream' => $stream, 'title' => $stream.' banner',
                    'status' => $status, 'assigned_to' => $designer->id,
                ]);
                if (in_array($status, ['pending', 'in_design'], true)) $active[] = $item;
            }
            foreach ([$today, $tbc, $closed] as $job) {
                DesignItem::create(['design_job_id' => $job->id, 'stream' => $stream, 'title' => 'Other banner', 'status' => 'pending']);
            }

            $url = '/api/design/'.$stream.'/items';
            $this->getJson($url.'?overdue_only=1&per_page=1')->assertOk()
                ->assertJsonPath('total', 2)->assertJsonPath('last_page', 2)
                ->assertJsonPath('counts.all', 2)->assertJsonPath('counts.pending', 1)
                ->assertJsonPath('data.0.designer', ['id' => $designer->id, 'name' => 'Amina Designer'])
                ->assertJsonPath('data.0.job.project_setup_date', '2026-09-23');
            $this->getJson($url.'?overdue_only=1&per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $active[0]->id);
            $this->getJson($url.'?overdue_only=1&status=in_design&search=Amina')->assertOk()
                ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $active[1]->id)
                ->assertJsonPath('counts.all', 2)->assertJsonPath('counts.pending', 1);
            $this->getJson($url.'?overdue_only=1&status=done')->assertOk()->assertJsonPath('total', 0);
            $this->getJson($url.'?overdue_only=invalid')->assertUnprocessable();
            $this->getJson($url.'?design_job_id='.$tbc->id)->assertOk()->assertJsonPath('data.0.designer', null)
                ->assertJsonPath('data.0.job.project_setup_date', null);
        }
    }

    public function test_jobs_show_distinct_assigned_designers_and_details_show_each_assignment(): void
    {
        $amina = User::factory()->create(['name' => 'Amina Designer']);
        $brian = User::factory()->create(['name' => 'Brian Designer']);
        $removed = User::factory()->create(['name' => 'Removed Designer']);
        $job = $this->design('2026-09-23');
        foreach ([$amina, $amina, $brian] as $designer) {
            DesignItem::create(['design_job_id' => $job->id, 'title' => 'Artwork', 'stream' => 'graphic', 'assigned_to' => $designer->id]);
        }
        DesignItem::create(['design_job_id' => $job->id, 'title' => 'Deleted artwork', 'stream' => 'structural', 'assigned_to' => $removed->id])->delete();

        $this->getJson('/api/design/jobs')->assertOk()->assertJsonPath('data.0.designers', [
            ['id' => $amina->id, 'name' => 'Amina Designer'],
            ['id' => $brian->id, 'name' => 'Brian Designer'],
        ]);
        $this->getJson('/api/design/jobs/'.$job->id)->assertOk()
            ->assertJsonPath('data.items.0.designer', ['id' => $amina->id, 'name' => 'Amina Designer']);
    }

    public function test_standalone_due_dates_do_not_become_parent_project_setup_dates(): void
    {
        DesignJob::create(['title' => 'Standalone design', 'due_date' => '2026-09-25']);
        PrintJob::create(['title' => 'Standalone print', 'due_date' => '2026-09-25']);
        foreach (['/api/design/jobs', '/api/printing/jobs'] as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('data.0.project_setup_date', null)
                ->assertJsonPath('data.0.due_date', '2026-09-25');
        }
    }
}
