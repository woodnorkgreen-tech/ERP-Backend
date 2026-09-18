<?php

namespace Tests\Feature\Printing;

use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignJob;
use App\Modules\HR\Models\Department;
use App\Modules\Printing\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UpcomingPrintJobsTest extends TestCase
{
    use RefreshDatabase;

    private function loginPrinter(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Role::findOrCreate('Printing', 'web'));
        Sanctum::actingAs($user);

        return $user;
    }

    private function item(array $attributes = [], ?DesignJob $job = null): DesignItem
    {
        $job ??= DesignJob::create(['title' => 'Launch project', 'job_number' => 'PRINT-001', 'due_date' => '2026-10-01']);

        return DesignItem::create(array_merge([
            'design_job_id' => $job->id,
            'stream' => 'graphic',
            'title' => 'Launch sticker',
            'status' => 'in_design',
        ], $attributes));
    }

    public function test_all_unfinished_graphics_are_visible_without_creating_print_jobs(): void
    {
        $this->loginPrinter();
        $expected = [];
        foreach (['pending', 'in_design', 'awaiting_client_approval', 'client_changes_requested', 'done'] as $status) {
            $expected[] = $this->item(['status' => $status])->id;
        }
        foreach (['print_ready', 'handed_off', 'cancelled'] as $status) {
            $this->item(['status' => $status]);
        }
        $this->item(['stream' => 'structural']);
        $this->item()->delete();
        $cancelled = DesignJob::create(['title' => 'Cancelled project', 'status' => 'cancelled']);
        $this->item([], $cancelled);
        $deleted = DesignJob::create(['title' => 'Deleted project']);
        $this->item([], $deleted);
        $deleted->delete();
        $before = PrintJob::count();

        $response = $this->getJson('/api/printing/upcoming-jobs')->assertOk()->assertJsonPath('total', 5);
        $this->assertEqualsCanonicalizing($expected, array_column($response->json('data'), 'design_item_id'));
        $this->assertSame($before, PrintJob::count());
    }

    public function test_live_design_status_controls_visibility_including_redesigns(): void
    {
        $this->loginPrinter();
        $original = $this->item(['status' => 'print_ready']);
        $redesign = $this->item(['redesign_of_item_id' => $original->id]);
        $this->getJson('/api/printing/upcoming-jobs')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.is_redesign', true);

        $redesign->update(['status' => 'print_ready']);
        $this->getJson('/api/printing/upcoming-jobs')->assertOk()->assertJsonPath('total', 0);

        $redesign->update(['status' => 'client_changes_requested']);
        $this->getJson('/api/printing/upcoming-jobs')->assertOk()
            ->assertJsonPath('data.0.design_item_id', $redesign->id);
    }

    public function test_search_status_and_pagination_work_together(): void
    {
        $this->loginPrinter();
        $this->item(['title' => 'Banner A']);
        $last = $this->item(['title' => 'Banner B']);
        $this->item(['title' => 'Banner cancelled', 'status' => 'cancelled']);
        $this->item(['title' => 'Banner pending', 'status' => 'pending']);
        $this->item(['title' => 'Sticker']);

        $this->getJson('/api/printing/upcoming-jobs?search=Banner&status=in_design&per_page=1')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last_page', 2)
            ->assertJsonPath('data.0.design_item_id', $last->id);
        $this->getJson('/api/printing/upcoming-jobs?search=PRINT-001')->assertOk()->assertJsonPath('total', 4);
        $this->getJson('/api/printing/upcoming-jobs?search=Launch%20project')->assertOk()->assertJsonPath('total', 4);
        $this->getJson('/api/printing/upcoming-jobs?status=print_ready')->assertUnprocessable();
        $this->getJson('/api/printing/upcoming-jobs?per_page=10000')->assertUnprocessable();
    }

    public function test_returns_planning_fields_and_preserves_missing_dimensions(): void
    {
        $designer = $this->loginPrinter();
        $client = Client::create([
            'full_name' => 'Upcoming Client', 'email' => 'upcoming@example.test', 'phone' => '0700000002',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'referral', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
        ]);
        $job = DesignJob::create(['title' => 'Manual design job', 'client_id' => $client->id, 'due_date' => '2026-10-01']);
        $item = $this->item(['assigned_to' => $designer->id, 'width_m' => 1.25, 'quantity' => 3], $job);

        $this->getJson('/api/printing/upcoming-jobs?search=Upcoming%20Client')->assertOk()
            ->assertJsonPath('data.0.design_item_id', $item->id)
            ->assertJsonPath('data.0.client_name', 'Upcoming Client')
            ->assertJsonPath('data.0.designer_name', $designer->name)
            ->assertJsonPath('data.0.width_m', 1.25)
            ->assertJsonPath('data.0.length_m', null)
            ->assertJsonPath('data.0.material_name', null)
            ->assertJsonPath('data.0.quantity', 3)
            ->assertJsonPath('data.0.due_date', '2026-10-01');
    }

    public function test_requires_printing_access(): void
    {
        $this->getJson('/api/printing/upcoming-jobs')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));
        $this->getJson('/api/printing/upcoming-jobs')->assertForbidden();
    }

    public function test_printing_department_members_can_view_without_a_design_role(): void
    {
        $department = Department::firstOrCreate(['name' => 'Printing']);
        Sanctum::actingAs(User::factory()->create(['is_active' => true, 'department_id' => $department->id]));
        $this->getJson('/api/printing/upcoming-jobs')->assertOk();
    }
}
