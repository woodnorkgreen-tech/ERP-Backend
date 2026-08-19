<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Notifications\Models\AppNotification;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Permission::findOrCreate(Permissions::SUPPORT_MANAGE, 'web');
        Role::findOrCreate('Employee', 'web');
        Role::findOrCreate('Admin', 'web')->givePermissionTo(Permissions::SUPPORT_MANAGE);
        Role::findOrCreate('ICT Support', 'web')->givePermissionTo(Permissions::SUPPORT_MANAGE);
    }

    public function test_user_can_submit_valid_ticket_with_private_attachment_and_confirmation(): void
    {
        $reporter = $this->user('Employee');
        Sanctum::actingAs($reporter);

        $response = $this->postJson('/api/support/tickets', [
            'subject' => 'Cannot export the project report',
            'description' => 'The export button returns an error after I select the monthly report.',
            'type' => 'bug',
            'category' => 'erp',
            'priority' => 'high',
            'attachments' => [UploadedFile::fake()->image('error.png')],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.reporter.id', $reporter->id)
            ->assertJsonCount(1, 'data.attachments');

        $ticket = SupportTicket::firstOrFail();
        $this->assertMatchesRegularExpression('/^ICT-\d{4}-\d{6}$/', $ticket->ticket_number);
        Storage::disk('local')->assertExists($ticket->attachments()->firstOrFail()->path);
        $this->assertTrue(AppNotification::where('user_id', $reporter->id)->where('type', 'support_ticket_received')->exists());
    }

    public function test_ticket_validation_rejects_incomplete_and_unsafe_uploads(): void
    {
        Sanctum::actingAs($this->user('Employee'));
        $this->postJson('/api/support/tickets', [
            'subject' => 'Bad',
            'description' => 'Too short',
            'type' => 'invalid',
            'category' => 'erp',
            'attachments' => [UploadedFile::fake()->create('script.php', 10, 'application/x-php')],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['subject', 'description', 'type', 'attachments.0']);
    }

    public function test_users_only_see_their_own_tickets_while_support_can_manage_all(): void
    {
        $owner = $this->user('Employee');
        $other = $this->user('Employee');
        $admin = $this->user('Admin');
        $ticket = $this->ticket($owner);

        Sanctum::actingAs($other);
        $this->getJson("/api/support/tickets/{$ticket->id}")->assertForbidden();
        $this->getJson('/api/support/tickets')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($admin);
        $this->getJson('/api/support/tickets?search=Printer')->assertOk()
            ->assertJsonPath('data.0.id', $ticket->id)
            ->assertJsonPath('data.0.can_manage', true)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.open', 1)
            ->assertJsonPath('summary.active', 0)
            ->assertJsonPath('summary.done', 0);

        $this->getJson('/api/support/tickets?type=bug')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.total', 0);
    }

    public function test_assignment_resolution_and_user_reply_follow_the_controlled_lifecycle(): void
    {
        $owner = $this->user('Employee');
        $admin = $this->user('Admin');
        $ticket = $this->ticket($owner);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/support/tickets/{$ticket->id}", ['assigned_to' => $admin->id])
            ->assertOk()->assertJsonPath('data.status', 'assigned');
        $this->patchJson("/api/support/tickets/{$ticket->id}", ['status' => 'in_progress'])->assertOk();
        $this->patchJson("/api/support/tickets/{$ticket->id}", [
            'status' => 'resolved',
            'resolution' => 'Printer driver was reinstalled and a test page printed.',
        ])->assertOk()->assertJsonPath('data.status', 'resolved');

        Sanctum::actingAs($owner);
        $this->postJson("/api/support/tickets/{$ticket->id}/replies", ['message' => 'The issue returned after restarting.'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertNull($ticket->fresh()->resolved_at);
    }

    public function test_ticket_has_sla_targets_and_requester_can_confirm_resolution(): void
    {
        $owner = $this->user('Employee');
        $admin = $this->user('Admin');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/support/tickets', [
            'subject' => 'Production approval is blocked',
            'description' => 'The approval action remains disabled after all required information is entered.',
            'type' => 'bug', 'category' => 'erp', 'priority' => 'high',
        ])->assertCreated()->assertJsonPath('data.is_overdue', false);

        $ticket = SupportTicket::findOrFail($response->json('data.id'));
        $this->assertNotNull($ticket->response_due_at);
        $this->assertNotNull($ticket->resolution_due_at);

        Sanctum::actingAs($admin);
        $this->postJson("/api/support/tickets/{$ticket->id}/replies", [
            'message' => 'The approval rule has been corrected and verified.',
            'action' => 'resolved',
        ])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertNotNull($ticket->fresh()->first_response_at);

        Sanctum::actingAs($owner);
        $this->postJson("/api/support/tickets/{$ticket->id}/confirm-resolution")
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertDatabaseHas('support_ticket_activities', [
            'support_ticket_id' => $ticket->id,
            'actor_id' => $owner->id,
            'action' => 'resolution_confirmed',
        ]);
    }

    public function test_attachment_download_is_scoped_to_ticket_viewers(): void
    {
        $owner = $this->user('Employee');
        Sanctum::actingAs($owner);
        $this->postJson('/api/support/tickets', [
            'subject' => 'Dashboard screenshot is attached',
            'description' => 'The dashboard cards overlap when I open the ERP using my phone.',
            'type' => 'bug', 'category' => 'erp',
            'attachments' => [UploadedFile::fake()->image('mobile.png')],
        ])->assertCreated();
        $ticket = SupportTicket::firstOrFail();
        $attachment = $ticket->attachments()->firstOrFail();

        $this->get("/api/support/tickets/{$ticket->id}/attachments/{$attachment->id}")->assertOk();

        Sanctum::actingAs($this->user('Employee'));
        $this->getJson("/api/support/tickets/{$ticket->id}/attachments/{$attachment->id}")->assertForbidden();
    }

    public function test_ticket_cannot_be_assigned_to_an_unauthorized_user(): void
    {
        $owner = $this->user('Employee');
        $admin = $this->user('Admin');
        $ticket = $this->ticket($owner);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/support/tickets/{$ticket->id}", ['assigned_to' => $owner->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');
    }

    public function test_reply_on_unassigned_ticket_notifies_support_queue(): void
    {
        $owner = $this->user('Employee');
        $admin = $this->user('Admin');
        $ticket = $this->ticket($owner);
        Sanctum::actingAs($owner);

        $this->postJson("/api/support/tickets/{$ticket->id}/replies", ['message' => 'This is still blocking my work.'])
            ->assertOk();

        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('type', 'support_ticket_reply')->exists());
    }

    public function test_support_reply_controls_audience_status_and_linked_attachments(): void
    {
        $owner = $this->user('Employee');
        $admin = $this->user('Admin');
        $ticket = $this->ticket($owner);
        Sanctum::actingAs($admin);

        $internalResponse = $this->postJson("/api/support/tickets/{$ticket->id}/replies", [
            'message' => 'Device serial number confirmed for ICT records.',
            'is_internal' => true,
            'attachments' => [UploadedFile::fake()->create('internal-diagnostics.txt', 10, 'text/plain')],
        ])->assertOk()->assertJsonPath('data.messages.0.is_internal', true);
        $internalAttachmentId = $internalResponse->json('data.messages.0.attachments.0.id');

        $response = $this->postJson("/api/support/tickets/{$ticket->id}/replies", [
            'message' => 'Please restart the printer and confirm whether the test page prints.',
            'action' => 'waiting_on_user',
            'attachments' => [UploadedFile::fake()->create('restart-guide.pdf', 120, 'application/pdf')],
        ])->assertOk()
            ->assertJsonPath('data.status', 'waiting_on_user')
            ->assertJsonPath('data.messages.1.attachments.0.name', 'restart-guide.pdf');

        $publicMessageId = $response->json('data.messages.1.id');
        $this->assertDatabaseHas('support_ticket_attachments', ['message_id' => $publicMessageId]);

        Sanctum::actingAs($owner);
        $this->getJson("/api/support/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.messages.0.is_internal', false);
        $this->getJson("/api/support/tickets/{$ticket->id}/attachments/{$internalAttachmentId}")
            ->assertForbidden();

        Sanctum::actingAs($admin);
        $ticket->update(['status' => 'closed']);
        $this->postJson("/api/support/tickets/{$ticket->id}/replies", ['message' => 'This must not be added.'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Reopen this ticket before adding a reply.');
    }

    public function test_ict_queue_scopes_and_operational_metrics_are_accurate(): void
    {
        $owner = $this->user('Employee');
        $agent = $this->user('ICT Support');

        $assigned = $this->ticket($owner);
        $assigned->update(['assigned_to' => $agent->id, 'status' => 'in_progress']);

        $urgent = $this->ticket($owner);
        $urgent->update(['priority' => 'urgent']);

        $closed = $this->ticket($owner);
        $closed->update(['status' => 'closed']);

        Sanctum::actingAs($agent);
        $this->getJson('/api/support/tickets?scope=unassigned')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $urgent->id)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('metrics.unassigned', 1)
            ->assertJsonPath('metrics.urgent', 1)
            ->assertJsonPath('metrics.assigned_to_me', 1);

        $this->getJson('/api/support/tickets?scope=assigned_to_me')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id);

        $autoClaim = $this->ticket($owner);
        $this->postJson("/api/support/tickets/{$autoClaim->id}/replies", ['message' => 'I am checking this request now.'])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $agent->id)
            ->assertJsonPath('data.status', 'in_progress');

        $otherAgent = $this->user('ICT Support');
        $this->patchJson("/api/support/tickets/{$urgent->id}", ['assigned_to' => $otherAgent->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');
        $this->patchJson("/api/support/tickets/{$urgent->id}", ['assigned_to' => $agent->id])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $agent->id);
    }

    public function test_desk_metrics_report_service_levels_within_the_viewers_scope(): void
    {
        $owner = $this->user('Employee');
        $other = $this->user('Employee');
        $admin = $this->user('Admin');

        $awaiting = $this->ticket($owner);
        $awaiting->forceFill(['created_at' => now()->subMinutes(60)])->save();

        $hit = $this->ticket($owner);
        $hit->update([
            'status' => 'resolved',
            'resolved_at' => now()->subHour(),
            'resolution_due_at' => now(),
        ]);

        $missed = $this->ticket($other);
        $missed->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_due_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/support/tickets/metrics')
            ->assertOk()
            ->assertJsonPath('data.total.value', 3)
            ->assertJsonPath('data.unassigned', 1)
            ->assertJsonPath('data.awaiting.count', 1)
            ->assertJsonPath('data.resolved_today.count', 2)
            ->assertJsonPath('data.resolved_today.sla_hit_pct', 50)
            ->assertJsonPath('data.status_totals.open', 1)
            ->assertJsonPath('data.status_totals.resolved', 2);

        $this->assertGreaterThanOrEqual(59, $this->getJson('/api/support/tickets/metrics')->json('data.awaiting.avg_wait_minutes'));

        // A requester's figures describe their own tickets only.
        Sanctum::actingAs($other);
        $this->getJson('/api/support/tickets/metrics')
            ->assertOk()
            ->assertJsonPath('data.total.value', 1)
            ->assertJsonPath('data.resolved_today.sla_hit_pct', 0);
    }

    public function test_a_reply_cannot_reach_a_status_the_management_panel_would_reject(): void
    {
        $owner = $this->user('Employee');
        $admin = $this->user('Admin');
        $ticket = $this->ticket($owner);
        $ticket->update(['status' => 'closed']);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/support/tickets/{$ticket->id}", ['status' => 'waiting_on_user'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        // Reopening is the only legal move out of closed, by either route.
        $this->patchJson("/api/support/tickets/{$ticket->id}", ['status' => 'in_progress'])->assertOk();
        $this->postJson("/api/support/tickets/{$ticket->id}/replies", [
            'message' => 'Please confirm whether the printer is now reachable.',
            'action' => 'waiting_on_user',
        ])->assertOk()->assertJsonPath('data.status', 'waiting_on_user');
    }

    public function test_a_save_that_changes_nothing_does_not_notify_the_requester(): void
    {
        $owner = $this->user('Employee');
        $admin = $this->user('Admin');
        $ticket = $this->ticket($owner);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/support/tickets/{$ticket->id}", ['priority' => $ticket->priority])->assertOk();

        $this->assertFalse(AppNotification::where('user_id', $owner->id)->where('type', 'support_ticket_updated')->exists());
        $this->assertDatabaseMissing('support_ticket_activities', [
            'support_ticket_id' => $ticket->id,
            'action' => 'updated',
        ]);

        $this->patchJson("/api/support/tickets/{$ticket->id}", ['priority' => 'urgent'])->assertOk();
        $this->assertTrue(AppNotification::where('user_id', $owner->id)->where('type', 'support_ticket_updated')->exists());
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        return $user;
    }

    private function ticket(User $owner): SupportTicket
    {
        $ticket = SupportTicket::create([
            'reporter_id' => $owner->id,
            'subject' => 'Printer is unavailable',
            'description' => 'The shared office printer cannot be selected from the ERP workstation.',
            'type' => 'support', 'category' => 'device', 'priority' => 'normal',
            'status' => 'open', 'last_activity_at' => now(),
        ]);
        $ticket->update(['ticket_number' => sprintf('ICT-%s-%06d', now()->format('Y'), $ticket->id)]);
        return $ticket;
    }
}
