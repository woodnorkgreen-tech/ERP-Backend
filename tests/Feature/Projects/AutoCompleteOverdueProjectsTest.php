<?php

namespace Tests\Feature\Projects;

use App\Models\GovernanceAuditLog;
use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Notifications\Models\AppNotification;
use App\Modules\Projects\Actions\AutoCompleteOverdueProjectAction;
use App\Modules\Projects\Models\ProjectHold;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoCompleteOverdueProjectsTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Africa/Nairobi']);
        $this->travelTo(Carbon::parse('2026-10-19 00:00:00', 'Africa/Nairobi'));
        $this->creator = User::factory()->create();
        $this->clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Deadline test client', 'email' => Str::uuid().'@test.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function project(?string $due = '2026-10-15', string $status = 'in_progress', ?int $officerId = null): ProjectEnquiry
    {
        $enquiry = ProjectEnquiry::create([
            'date_received' => '2026-10-01', 'client_id' => $this->clientId,
            'title' => 'Overdue project', 'contact_person' => 'Client',
            'status' => $status, 'expected_delivery_date' => $due, 'project_officer_id' => $officerId,
            'enquiry_number' => 'ENQ-'.Str::uuid(), 'created_by' => $this->creator->id,
        ]);
        Project::create(['enquiry_id' => $enquiry->id, 'project_id' => 'JOB-'.Str::random(8), 'status' => 'in_progress']);

        return $enquiry;
    }

    public function test_completes_after_three_full_overdue_days_and_only_once(): void
    {
        $enquiry = $this->project();
        $action = app(AutoCompleteOverdueProjectAction::class);
        $this->travelTo(Carbon::parse('2026-10-18 23:59:59', 'Africa/Nairobi'));
        $this->assertFalse($action->execute($enquiry->id));
        $this->travelTo(Carbon::parse('2026-10-19 00:00:00', 'Africa/Nairobi'));
        $this->assertTrue($action->execute($enquiry->id));
        $this->assertSame('completed', $enquiry->fresh()->status);
        $this->assertSame('completed', $enquiry->project()->first()->status);
        $this->assertFalse($action->execute($enquiry->id));
        $audit = GovernanceAuditLog::where('project_enquiry_id', $enquiry->id)->where('gate_type', 'project_completed')->sole();
        $this->assertNull($audit->user_id);
        $this->assertSame('automatic_overdue', $audit->context['completion_method']);
    }

    public function test_skips_other_statuses_missing_dates_recent_dates_and_holds(): void
    {
        $action = app(AutoCompleteOverdueProjectAction::class);
        foreach (['enquiry_logged', 'awaiting_deposit', 'completed', 'closed', 'cancelled'] as $status) {
            $enquiry = $this->project('2026-09-01', $status);
            $this->assertFalse($action->execute($enquiry->id));
            $this->assertSame($status, $enquiry->fresh()->status);
        }
        foreach ([null, '2026-10-19', '2026-10-25', '2026-10-16'] as $due) {
            $this->assertFalse($action->execute($this->project($due)->id));
        }
        $held = $this->project();
        ProjectHold::create(['project_enquiry_id' => $held->id, 'reason' => 'Waiting for client', 'placed_by' => $this->creator->id, 'placed_at' => now()]);
        $this->assertFalse($action->execute($held->id));
        $missing = $this->project();
        $missing->project()->delete();
        $this->assertFalse($action->execute($missing->id));
    }

    public function test_command_catches_up_old_projects_without_completing_tasks(): void
    {
        $enquiry = $this->project('2026-09-01');
        $taskId = DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $enquiry->id, 'type' => 'materials', 'title' => 'Materials',
            'status' => 'pending', 'created_by' => $this->creator->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->artisan('projects:auto-complete-overdue', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('in_progress', $enquiry->fresh()->status);
        $this->artisan('projects:auto-complete-overdue')->assertSuccessful();
        $this->assertSame('completed', $enquiry->fresh()->status);
        $this->assertDatabaseHas('enquiry_tasks', ['id' => $taskId, 'status' => 'pending']);
        $this->artisan('projects:auto-complete-overdue')->assertSuccessful();
        $this->assertSame(1, GovernanceAuditLog::where('project_enquiry_id', $enquiry->id)->where('gate_type', 'project_completed')->count());
    }

    public function test_completes_planning_and_quote_approved_projects_in_the_active_group(): void
    {
        foreach (['planning', 'quote_approved'] as $status) {
            $enquiry = $this->project('2026-07-15', $status);
            $this->assertTrue(app(AutoCompleteOverdueProjectAction::class)->execute($enquiry->id));
            $this->assertSame('completed', $enquiry->fresh()->status);
            $this->assertSame('completed', $enquiry->project()->first()->status);
        }
    }

    public function test_the_project_officer_is_told_once_how_overdue_the_job_was(): void
    {
        $officer = User::factory()->create();
        $enquiry = $this->project('2026-10-15', 'planning', $officer->id);

        $this->artisan('projects:auto-complete-overdue')->assertSuccessful();
        $this->artisan('projects:auto-complete-overdue')->assertSuccessful();

        $notice = AppNotification::where('user_id', $officer->id)->where('type', 'project_auto_completed')->sole();
        $this->assertSame($enquiry->id, $notice->data['enquiry_id']);
        $this->assertSame(4, $notice->data['days_overdue']);
        $this->assertSame("/projects/enquiries/{$enquiry->id}", $notice->data['url']);
        $this->assertSame(1, AppNotification::where('type', 'project_auto_completed')->count());
    }

    public function test_a_job_without_a_project_officer_still_completes_and_notifies_nobody(): void
    {
        $enquiry = $this->project();

        $this->assertTrue(app(AutoCompleteOverdueProjectAction::class)->execute($enquiry->id));
        $this->assertSame('completed', $enquiry->fresh()->status);
        $this->assertSame(0, AppNotification::where('type', 'project_auto_completed')->count());
    }

    public function test_a_job_that_is_not_completed_sends_no_notice(): void
    {
        $officer = User::factory()->create();
        $held = $this->project('2026-09-01', 'in_progress', $officer->id);
        ProjectHold::create(['project_enquiry_id' => $held->id, 'reason' => 'Waiting for client', 'placed_by' => $this->creator->id, 'placed_at' => now()]);
        $recent = $this->project('2026-10-17', 'in_progress', $officer->id);

        $this->artisan('projects:auto-complete-overdue')->assertSuccessful();

        $this->assertSame('in_progress', $held->fresh()->status);
        $this->assertSame('in_progress', $recent->fresh()->status);
        $this->assertSame(0, AppNotification::where('user_id', $officer->id)->count());
    }

    public function test_rechecks_a_changed_deadline_before_completing(): void
    {
        $enquiry = $this->project();
        $action = app(AutoCompleteOverdueProjectAction::class);
        $this->assertTrue($action->candidates()->whereKey($enquiry->id)->exists());
        $enquiry->update(['expected_delivery_date' => '2026-11-01']);
        $this->assertFalse($action->execute($enquiry->id));
        $this->assertSame('in_progress', $enquiry->fresh()->status);
    }
}
