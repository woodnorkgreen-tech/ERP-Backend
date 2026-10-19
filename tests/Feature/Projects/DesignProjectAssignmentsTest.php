<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectElement;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Design\Models\DesignHandoff;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignUpdate;
use App\Modules\Design\Services\DesignProjectSyncService;
use App\Modules\Printing\Resources\UpcomingProjectResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DesignProjectAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_project_sync_creates_one_task_per_element_and_stream_without_duplication(): void
    {
        $enquiry = $this->enquiry();
        $element = ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Stage', 'classification' => 'FABRICATION & STRUCTURES']);
        $sync = app(DesignProjectSyncService::class);

        $job = $sync->syncFromEnquiry($enquiry);
        $sync->syncFromEnquiry($enquiry->fresh());

        $items = DesignItem::where('design_job_id', $job->id)->orderBy('stream')->get();
        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing(['graphic', 'structural'], $items->pluck('stream')->all());
        $this->assertTrue($items->every(fn (DesignItem $item) => $item->project_deliverable_id === $element->id && $item->assignment_source === 'project_default'));
    }

    public function test_main_designer_change_moves_only_unfinished_default_tasks_and_not_required_can_be_restored(): void
    {
        $enquiry = $this->enquiry();
        ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Stage', 'classification' => 'FABRICATION & STRUCTURES']);
        $job = app(DesignProjectSyncService::class)->syncFromEnquiry($enquiry);
        $graphic = $job->items()->where('stream', 'graphic')->firstOrFail();
        $structural = $job->items()->where('stream', 'structural')->firstOrFail();
        $admin = $this->user('Admin');
        $first = $this->user('Designer');
        $second = $this->user('Designer');
        Sanctum::actingAs($admin);

        $this->putJson("/api/design/jobs/{$job->id}/main-designers", [
            'graphic_main_designer_id' => $first->id,
            'structural_main_designer_id' => $second->id,
        ])->assertOk();
        $this->assertSame($first->id, $graphic->fresh()->assigned_to);
        $this->assertSame($second->id, $structural->fresh()->assigned_to);

        Sanctum::actingAs($first);
        $this->postJson("/api/design/items/{$structural->id}/mark-not-required", ['reason' => 'No 3D work needed'])->assertOk();
        $this->assertSame('not_required', $structural->fresh()->status);
        Sanctum::actingAs($admin);
        $this->putJson("/api/design/jobs/{$job->id}/main-designers", [
            'graphic_main_designer_id' => $second->id,
            'structural_main_designer_id' => $first->id,
        ])->assertOk();
        $this->assertSame($second->id, $graphic->fresh()->assigned_to);
        $this->assertSame($second->id, $structural->fresh()->assigned_to);

        $this->postJson("/api/design/items/{$structural->id}/restore-required")->assertOk();
        $this->assertSame($first->id, $structural->fresh()->assigned_to);
        $this->assertSame('pending', $structural->fresh()->status);
    }

    public function test_individual_exception_survives_main_change_and_started_exclusion_requires_a_reason(): void
    {
        $enquiry = $this->enquiry();
        ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Banner', 'classification' => 'BRANDING']);
        $job = app(DesignProjectSyncService::class)->syncFromEnquiry($enquiry);
        $graphic = $job->items()->where('stream', 'graphic')->firstOrFail();
        $admin = $this->user('Admin');
        $first = $this->user('Designer');
        $second = $this->user('Designer');
        $third = $this->user('Designer');
        $outsider = $this->user('Project Officer');
        Sanctum::actingAs($admin);
        $this->putJson("/api/design/jobs/{$job->id}/main-designers", [
            'graphic_main_designer_id' => $first->id, 'structural_main_designer_id' => null,
        ])->assertOk();

        Sanctum::actingAs($first);
        $this->postJson("/api/design/items/{$graphic->id}/work/begin")->assertOk();
        Sanctum::actingAs($admin);
        $this->putJson("/api/design/graphic/items/{$graphic->id}", ['title' => 'Banner', 'assigned_to' => $third->id])->assertOk();
        $this->assertNotNull($graphic->workSessions()->firstOrFail()->ended_at);
        $this->assertSame('switched_task', $graphic->workSessions()->firstOrFail()->pause_reason_code);
        $this->putJson("/api/design/jobs/{$job->id}/main-designers", [
            'graphic_main_designer_id' => $second->id, 'structural_main_designer_id' => null,
        ])->assertOk();
        $this->assertSame($third->id, $graphic->fresh()->assigned_to);
        $this->assertSame('individual', $graphic->fresh()->assignment_source);

        Sanctum::actingAs($outsider);
        $this->postJson("/api/design/items/{$graphic->id}/mark-not-required", ['reason' => 'No artwork'])->assertForbidden();
        Sanctum::actingAs($third);
        $this->postJson("/api/design/items/{$graphic->id}/mark-not-required", [])->assertStatus(422);
        $this->postJson("/api/design/items/{$graphic->id}/mark-not-required", ['reason' => 'Artwork supplied'])->assertOk();
        $this->assertNotNull($graphic->workSessions()->firstOrFail()->ended_at);
        $this->postJson("/api/design/items/{$graphic->id}/restore-required")->assertOk();
        $this->assertSame($third->id, $graphic->fresh()->assigned_to);
        $this->postJson("/api/design/items/{$graphic->id}/use-project-default")->assertOk();
        $this->assertSame($second->id, $graphic->fresh()->assigned_to);
        $redesign = $this->postJson("/api/design/items/{$graphic->id}/redesign", ['reason' => 'Client changed artwork'])->assertCreated()->json('data');
        $this->assertNull($redesign['generated_scope_key']);
    }

    public function test_same_designer_can_hold_both_roles_and_clearing_a_role_leaves_completed_work_alone(): void
    {
        $enquiry = $this->enquiry();
        $stage = ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Stage', 'classification' => 'FABRICATION & STRUCTURES']);
        $banner = ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Banner', 'classification' => 'BRANDING']);
        $job = app(DesignProjectSyncService::class)->syncFromEnquiry($enquiry);
        $admin = $this->user('Admin');
        $designer = $this->user('Designer');
        Sanctum::actingAs($admin);

        $this->putJson("/api/design/jobs/{$job->id}/main-designers", [
            'graphic_main_designer_id' => $designer->id, 'structural_main_designer_id' => $designer->id,
        ])->assertOk()->assertJsonPath('assignment_counts.graphic', 2)->assertJsonPath('assignment_counts.structural', 2);
        $this->assertSame(4, $job->items()->where('assigned_to', $designer->id)->count());

        $done = $job->items()->where('stream', 'graphic')->where('project_deliverable_id', $banner->id)->firstOrFail();
        $done->update(['status' => 'done']);
        $open = $job->items()->where('stream', 'graphic')->where('project_deliverable_id', $stage->id)->firstOrFail();

        $this->putJson("/api/design/jobs/{$job->id}/main-designers", [
            'graphic_main_designer_id' => null, 'structural_main_designer_id' => $designer->id,
        ])->assertOk()->assertJsonPath('assignment_counts.graphic', 1)->assertJsonPath('assignment_counts.structural', 0);

        $this->assertNull($open->fresh()->assigned_to);
        $this->assertSame($designer->id, $done->fresh()->assigned_to);
        $this->assertSame(2, $job->items()->where('stream', 'structural')->where('assigned_to', $designer->id)->count());

        // New elements in a stream with no main designer stay unassigned.
        ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Flag', 'classification' => 'BRANDING']);
        app(DesignProjectSyncService::class)->syncFromEnquiry($enquiry->fresh());
        $this->assertNull($job->items()->where('stream', 'graphic')->where('title', 'Flag')->firstOrFail()->assigned_to);
        $this->assertSame($designer->id, $job->items()->where('stream', 'structural')->where('title', 'Flag')->firstOrFail()->assigned_to);
    }

    public function test_main_designer_change_moves_running_work_and_closes_the_former_designers_session(): void
    {
        $enquiry = $this->enquiry();
        ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Backdrop', 'classification' => 'BRANDING']);
        $job = app(DesignProjectSyncService::class)->syncFromEnquiry($enquiry);
        $graphic = $job->items()->where('stream', 'graphic')->firstOrFail();
        $admin = $this->user('Admin');
        $first = $this->user('Designer');
        $second = $this->user('Designer');
        Sanctum::actingAs($admin);
        $this->putJson("/api/design/jobs/{$job->id}/main-designers", [
            'graphic_main_designer_id' => $first->id, 'structural_main_designer_id' => null,
        ])->assertOk();

        Sanctum::actingAs($first);
        $this->postJson("/api/design/items/{$graphic->id}/work/begin")->assertOk();
        $this->assertSame('in_design', $graphic->fresh()->status);

        Sanctum::actingAs($admin);
        $this->putJson("/api/design/jobs/{$job->id}/main-designers", [
            'graphic_main_designer_id' => $second->id, 'structural_main_designer_id' => null,
        ])->assertOk()->assertJsonPath('assignment_counts.graphic', 1);

        $graphic->refresh();
        $this->assertSame($second->id, $graphic->assigned_to);
        $this->assertSame('in_design', $graphic->status);
        $session = $graphic->workSessions()->firstOrFail();
        $this->assertNotNull($session->ended_at);
        $this->assertSame($first->id, $session->user_id);
        $this->assertTrue(DesignUpdate::where('design_item_id', $graphic->id)->where('note', 'like', 'Project graphic designer changed%')->exists());
    }

    public function test_intentional_unassignment_is_kept_when_the_main_designer_changes(): void
    {
        $enquiry = $this->enquiry();
        ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Poster', 'classification' => 'BRANDING']);
        $job = app(DesignProjectSyncService::class)->syncFromEnquiry($enquiry);
        $graphic = $job->items()->where('stream', 'graphic')->firstOrFail();
        $admin = $this->user('Admin');
        $first = $this->user('Designer');
        $second = $this->user('Designer');
        Sanctum::actingAs($admin);
        $this->putJson("/api/design/jobs/{$job->id}/main-designers", [
            'graphic_main_designer_id' => $first->id, 'structural_main_designer_id' => null,
        ])->assertOk();

        $this->putJson("/api/design/graphic/items/{$graphic->id}", ['title' => 'Poster', 'assigned_to' => null])->assertOk();
        $this->assertSame('individual', $graphic->fresh()->assignment_source);

        $this->putJson("/api/design/jobs/{$job->id}/main-designers", [
            'graphic_main_designer_id' => $second->id, 'structural_main_designer_id' => null,
        ])->assertOk()->assertJsonPath('assignment_counts.graphic', 0);
        $this->assertNull($graphic->fresh()->assigned_to);

        app(DesignProjectSyncService::class)->syncFromEnquiry($enquiry->fresh());
        $this->assertNull($graphic->fresh()->assigned_to);
    }

    public function test_backfill_adopts_one_existing_task_reports_ambiguity_and_never_adopts_a_redesign(): void
    {
        $enquiry = $this->enquiry();
        $sync = app(DesignProjectSyncService::class);
        $job = $sync->syncFromEnquiry($enquiry);
        $single = ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Counter', 'classification' => 'FABRICATION & STRUCTURES']);
        $doubled = ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Tent', 'classification' => 'FABRICATION & STRUCTURES']);
        $redesigned = ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Arch', 'classification' => 'FABRICATION & STRUCTURES']);
        $manual = fn (ProjectElement $element, array $extra = []) => DesignItem::create(array_merge([
            'design_job_id' => $job->id, 'project_deliverable_id' => $element->id, 'stream' => 'graphic',
            'title' => $element->name, 'status' => 'pending',
        ], $extra));
        $existing = $manual($single, ['status' => 'in_design']);
        $manual($doubled);
        $manual($doubled);
        $original = $manual($redesigned, ['status' => 'done']);
        $redesign = $manual($redesigned, ['redesign_of_item_id' => $original->id, 'redesign_source' => 'design']);

        $preview = $sync->syncItemsForJob($job, dryRun: true);
        $this->assertSame(2, $preview['adopted']);
        $this->assertSame(1, $preview['ambiguous']);
        $this->assertSame(3, $preview['created']);
        $this->assertSame(0, DesignItem::where('design_job_id', $job->id)->whereNotNull('generated_scope_key')->count());

        $result = $sync->syncItemsForJob($job);
        $this->assertSame(2, $result['adopted']);
        $this->assertSame(3, $result['created']);
        $this->assertSame(["{$job->id}:{$doubled->id}:graphic"], $result['ambiguous_keys']);

        $this->assertSame("{$job->id}:{$single->id}:graphic", $existing->fresh()->generated_scope_key);
        $this->assertSame('in_design', $existing->fresh()->status);
        $this->assertSame(2, DesignItem::where('project_deliverable_id', $doubled->id)->where('stream', 'graphic')->count());
        $this->assertSame(0, DesignItem::where('project_deliverable_id', $doubled->id)->where('stream', 'graphic')->whereNotNull('generated_scope_key')->count());
        $this->assertSame("{$job->id}:{$redesigned->id}:graphic", $original->fresh()->generated_scope_key);
        $this->assertNull($redesign->fresh()->generated_scope_key);
        $this->assertSame(2, DesignItem::where('project_deliverable_id', $redesigned->id)->where('stream', 'graphic')->count());

        // A second run is a no-op apart from the still-ambiguous pair.
        $again = $sync->syncItemsForJob($job);
        $this->assertSame(0, $again['created'] + $again['adopted']);
    }

    public function test_archived_element_flags_its_tasks_for_review_and_restoring_it_clears_the_flag(): void
    {
        $enquiry = $this->enquiry();
        $element = ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Booth', 'classification' => 'FABRICATION & STRUCTURES']);
        $sync = app(DesignProjectSyncService::class);
        $job = $sync->syncFromEnquiry($enquiry);

        $element->delete();
        $sync->syncFromEnquiry($enquiry->fresh());
        $items = $job->items()->get();
        $this->assertCount(2, $items);
        $this->assertTrue($items->every(fn (DesignItem $item) => $item->source_element_archived_at !== null));
        $this->assertSame(2, DesignUpdate::whereIn('design_item_id', $items->pluck('id'))->where('note', 'like', 'Source project element was removed%')->count());

        $element->restore();
        $sync->syncFromEnquiry($enquiry->fresh());
        $this->assertTrue($job->items()->get()->every(fn (DesignItem $item) => $item->source_element_archived_at === null));
        $this->assertSame(2, $job->items()->count());
    }

    public function test_open_handoffs_block_not_required_and_excluded_tasks_cannot_start_or_be_deleted(): void
    {
        $enquiry = $this->enquiry();
        ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Sticker', 'classification' => 'BRANDING']);
        $job = app(DesignProjectSyncService::class)->syncFromEnquiry($enquiry);
        $graphic = $job->items()->where('stream', 'graphic')->firstOrFail();
        $structural = $job->items()->where('stream', 'structural')->firstOrFail();
        Sanctum::actingAs($this->user('Admin'));

        $handoff = DesignHandoff::create(['design_item_id' => $graphic->id, 'target_module' => 'printing', 'status' => 'pending']);
        $this->postJson("/api/design/items/{$graphic->id}/mark-not-required", ['reason' => 'Supplied'])->assertStatus(422);
        $handoff->update(['status' => 'accepted']);
        $this->postJson("/api/design/items/{$graphic->id}/mark-not-required", ['reason' => 'Supplied'])->assertStatus(422);
        $handoff->update(['status' => 'rejected']);
        $this->postJson("/api/design/items/{$graphic->id}/mark-not-required", ['reason' => 'Supplied'])->assertOk();

        $this->postJson("/api/design/items/{$structural->id}/mark-not-required")->assertOk();
        $this->postJson("/api/design/items/{$structural->id}/mark-not-required")->assertOk();
        $this->assertSame(1, DesignUpdate::where('design_item_id', $structural->id)->where('note', 'like', 'Marked Not required%')->count());
        $this->postJson("/api/design/items/{$structural->id}/work/begin")->assertStatus(422);
        $this->postJson("/api/design/structural/items/{$structural->id}/mark-production-ready")->assertStatus(422);
        $this->deleteJson("/api/design/structural/items/{$structural->id}")->assertStatus(422);
        $this->assertSame('not_required', $structural->fresh()->status);
    }

    public function test_not_required_tasks_leave_lists_and_counts_and_printing_upcoming_reflects_them(): void
    {
        $enquiry = $this->enquiry();
        $wall = ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Wall', 'classification' => 'BRANDING']);
        $frame = ProjectElement::create(['enquiry_id' => $enquiry->id, 'name' => 'Frame', 'classification' => 'FABRICATION & STRUCTURES']);
        $job = app(DesignProjectSyncService::class)->syncFromEnquiry($enquiry);
        $frameGraphic = $job->items()->where('stream', 'graphic')->where('project_deliverable_id', $frame->id)->firstOrFail();
        Sanctum::actingAs($this->user('Admin'));
        $this->postJson("/api/design/items/{$frameGraphic->id}/mark-not-required")->assertOk();

        $list = $this->getJson('/api/design/graphic/items')->assertOk();
        $this->assertNotContains($frameGraphic->id, collect($list->json('data'))->pluck('id')->all());
        $list->assertJsonPath('counts.all', 1)->assertJsonPath('counts.not_required', 1);
        $excluded = $this->getJson('/api/design/graphic/items?status=not_required')->assertOk();
        $this->assertSame([$frameGraphic->id], collect($excluded->json('data'))->pluck('id')->all());

        $upcoming = (new UpcomingProjectResource($job->fresh(['enquiry.deliverables', 'items.assignedUser'])))->toArray(Request::create('/'));
        $planning = collect($upcoming['deliverables'])->pluck('planning_status', 'deliverable_id');
        $this->assertSame('assigned', $planning[$wall->id]);
        $this->assertSame('not_required', $planning[$frame->id]);
        $this->assertTrue(collect($upcoming['deliverables'])->flatMap(fn ($deliverable) => $deliverable['design_items'])->every(fn ($task) => $task['stream'] === 'graphic'));
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => uniqid('designer_'), 'email' => uniqid('designer_').'@test.local', 'password' => bcrypt('secret'), 'is_active' => true])->fresh();
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
        return $user;
    }

    private function enquiry(): ProjectEnquiry
    {
        $creator = $this->user('Admin');
        $client = Client::create([
            'full_name' => 'Design Test Client', 'contact_person' => 'Jane Test', 'email' => uniqid('client_').'@test.local',
            'phone' => '0700000000', 'address' => '123 Test Street', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'status' => 'active', 'is_active' => true,
        ]);
        return ProjectEnquiry::create([
            'date_received' => now()->toDateString(), 'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $client->id, 'title' => 'Design Assignment Test', 'description' => 'Design assignment test',
            'priority' => 'medium', 'status' => 'enquiry_logged', 'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-DESIGN-'.uniqid(), 'created_by' => $creator->id,
            'selected_workflow_tasks' => ['design'],
        ]);
    }
}
