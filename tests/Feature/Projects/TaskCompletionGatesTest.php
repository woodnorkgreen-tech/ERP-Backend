<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\HandoverSurvey;
use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ArchivalTask\Models\ArchivalReport as ArchivalReportModel;
use App\Modules\ClientService\Models\Client;
use App\Modules\logisticsTask\Models\LogisticsChecklist;
use App\Modules\logisticsTask\Models\LogisticsTask;
use App\Modules\logisticsTask\Models\TransportItem;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderTask;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\setdownTask\Models\SetdownTask;
use App\Modules\setdownTask\Models\SetdownTaskIssue;
use App\Modules\setupTask\Models\SetupTask;
use App\Modules\setupTask\Models\SetupTaskIssue;
use App\Modules\Teams\Models\TeamCategory;
use App\Modules\Teams\Models\TeamsMember;
use App\Modules\Teams\Models\TeamsTask;
use App\Modules\Teams\Models\TeamType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for the seven completion gates added 2026-07-21 to close
 * the "frontend-only enforcement" gap found in the Projects module audit:
 * handover, report, setup, setdown, production, logistics, teams. Each of
 * these previously had no server-side check at all — only a Vue-side one,
 * trivially bypassable via a direct status-update call. Every gate here
 * mirrors an already-shipped frontend check exactly (see
 * EnquiryWorkflowService::validateTaskCompletion branches 5-11).
 */
class TaskCompletionGatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_handover_cannot_complete_without_submitted_survey(): void
    {
        $task = $this->task($this->enquiry(), 'handover');
        HandoverSurvey::create([
            'task_id' => $task->id,
            'access_token' => 'tok_' . uniqid(),
            'submitted' => false,
        ]);

        $this->completeAs($task, $this->user())->assertStatus(422);
        $this->assertDatabaseHas('enquiry_tasks', ['id' => $task->id, 'status' => 'in_progress']);
    }

    public function test_handover_completes_once_survey_is_submitted(): void
    {
        $task = $this->task($this->enquiry(), 'handover');
        HandoverSurvey::create([
            'task_id' => $task->id,
            'access_token' => 'tok_' . uniqid(),
            'submitted' => true,
            'submitted_at' => now(),
        ]);

        $this->completeAs($task, $this->user())->assertOk();
        $this->assertDatabaseHas('enquiry_tasks', ['id' => $task->id, 'status' => 'completed']);
    }

    public function test_report_cannot_complete_without_officer_signature(): void
    {
        $task = $this->task($this->enquiry(), 'report');
        ArchivalReportModel::create(['enquiry_task_id' => $task->id]);

        $this->completeAs($task, $this->user())->assertStatus(422);
    }

    public function test_report_completes_once_officer_signature_present(): void
    {
        $task = $this->task($this->enquiry(), 'report');
        ArchivalReportModel::create([
            'enquiry_task_id' => $task->id,
            'project_officer_signature' => 'J. Doe',
            'project_officer_sign_date' => now()->toDateString(),
        ]);

        $this->completeAs($task, $this->user())->assertOk();
    }

    public function test_setup_cannot_complete_with_open_issues(): void
    {
        $enquiry = $this->enquiry();
        $task = $this->task($enquiry, 'setup');
        $creator = $this->user();
        $setupTask = SetupTask::create([
            'task_id' => $task->id,
            'project_id' => $enquiry->id,
            'created_by' => $creator->id,
        ]);
        SetupTaskIssue::create([
            'setup_task_id' => $setupTask->id,
            'title' => 'Missing power outlet',
            'description' => 'Venue lacks power at the main stage',
            'category' => 'venue',
            'priority' => 'high',
            'status' => 'open',
            'reported_by' => $creator->id,
        ]);

        $this->completeAs($task, $creator)->assertStatus(422);
    }

    public function test_setup_completes_once_issues_resolved(): void
    {
        $enquiry = $this->enquiry();
        $task = $this->task($enquiry, 'setup');
        $creator = $this->user();
        $setupTask = SetupTask::create([
            'task_id' => $task->id,
            'project_id' => $enquiry->id,
            'created_by' => $creator->id,
        ]);
        SetupTaskIssue::create([
            'setup_task_id' => $setupTask->id,
            'title' => 'Missing power outlet',
            'description' => 'Venue lacks power at the main stage',
            'category' => 'venue',
            'priority' => 'high',
            'status' => 'resolved',
            'reported_by' => $creator->id,
            'resolved_at' => now(),
        ]);

        $this->completeAs($task, $creator)->assertOk();
    }

    public function test_setdown_cannot_complete_with_open_issues(): void
    {
        $enquiry = $this->enquiry();
        $task = $this->task($enquiry, 'setdown');
        $creator = $this->user();
        $setdownTask = SetdownTask::create([
            'task_id' => $task->id,
            'project_id' => $enquiry->id,
            'created_by' => $creator->id,
        ]);
        SetdownTaskIssue::create([
            'setdown_task_id' => $setdownTask->id,
            'title' => 'Damaged panel',
            'description' => 'One branding panel returned cracked',
            'category' => 'equipment',
            'priority' => 'medium',
            'status' => 'open',
            'reported_by' => $creator->id,
        ]);

        $this->completeAs($task, $creator)->assertStatus(422);
    }

    public function test_setdown_completes_once_issues_resolved(): void
    {
        $enquiry = $this->enquiry();
        $task = $this->task($enquiry, 'setdown');
        $creator = $this->user();
        SetdownTask::create([
            'task_id' => $task->id,
            'project_id' => $enquiry->id,
            'created_by' => $creator->id,
        ]);

        $this->completeAs($task, $creator)->assertOk();
    }

    public function test_production_cannot_complete_with_incomplete_work_order_tasks(): void
    {
        $enquiry = $this->enquiry();
        $task = $this->task($enquiry, 'production');
        $workOrder = WorkOrder::create([
            'work_order_number' => 'WO-TEST-' . uniqid(),
            'enquiry_task_id' => $task->id,
            'title' => 'Branding build',
            'quantity' => 1,
            'status' => 'in_progress',
        ]);
        WorkOrderTask::create([
            'work_order_id' => $workOrder->id,
            'workstation' => 'assembly',
            'title' => 'Cut panels',
            'quantity' => 1,
        ]);

        $this->completeAs($task, $this->user())->assertStatus(422);
    }

    public function test_production_completes_once_all_work_order_tasks_done(): void
    {
        $enquiry = $this->enquiry();
        $task = $this->task($enquiry, 'production');
        $workOrder = WorkOrder::create([
            'work_order_number' => 'WO-TEST-' . uniqid(),
            'enquiry_task_id' => $task->id,
            'title' => 'Branding build',
            'quantity' => 1,
            'status' => 'in_progress',
        ]);
        WorkOrderTask::create([
            'work_order_id' => $workOrder->id,
            'workstation' => 'assembly',
            'title' => 'Cut panels',
            'quantity' => 1,
            'status' => 'completed',
        ]);

        $this->completeAs($task, $this->user())->assertOk();
    }

    public function test_logistics_cannot_complete_with_incomplete_checklist(): void
    {
        $enquiry = $this->enquiry();
        $project = Project::create(['enquiry_id' => $enquiry->id, 'project_id' => 'PRJ-' . uniqid(), 'status' => 'planning']);
        $task = $this->task($enquiry, 'logistics');
        $logisticsTask = LogisticsTask::create([
            'task_id' => $task->id,
            'project_id' => $project->id,
            'logistics_planning' => $this->validLogisticsPlanning(),
        ]);
        TransportItem::create([
            'logistics_task_id' => $logisticsTask->id,
            'name' => 'Tent',
            'quantity' => 1,
            'unit' => 'pcs',
            'category' => 'custom',
        ]);
        LogisticsChecklist::create([
            'logistics_task_id' => $logisticsTask->id,
            'checklist_data' => ['items' => [['id' => 'item_1', 'item_name' => 'Tent', 'status' => 'missing']]],
        ]);

        $this->completeAs($task, $this->user())->assertStatus(422);
    }

    public function test_logistics_completes_once_checklist_all_present(): void
    {
        $enquiry = $this->enquiry();
        $project = Project::create(['enquiry_id' => $enquiry->id, 'project_id' => 'PRJ-' . uniqid(), 'status' => 'planning']);
        $task = $this->task($enquiry, 'logistics');
        $logisticsTask = LogisticsTask::create([
            'task_id' => $task->id,
            'project_id' => $project->id,
            'logistics_planning' => $this->validLogisticsPlanning(),
        ]);
        TransportItem::create([
            'logistics_task_id' => $logisticsTask->id,
            'name' => 'Tent',
            'quantity' => 1,
            'unit' => 'pcs',
            'category' => 'custom',
        ]);
        LogisticsChecklist::create([
            'logistics_task_id' => $logisticsTask->id,
            'checklist_data' => ['items' => [['id' => 'item_1', 'item_name' => 'Tent', 'status' => 'present']]],
        ]);

        $this->completeAs($task, $this->user())->assertOk();
    }

    public function test_teams_cannot_complete_without_enough_members(): void
    {
        $enquiry = $this->enquiry();
        $task = $this->task($enquiry, 'teams');
        $teamsTask = TeamsTask::create([
            'task_id' => $task->id,
            'category_id' => TeamCategory::create(['category_key' => 'setup_' . uniqid(), 'name' => 'Setup Crew', 'display_name' => 'Setup Crew'])->id,
            'team_type_id' => TeamType::create(['type_key' => 'field_' . uniqid(), 'name' => 'Field', 'display_name' => 'Field'])->id,
            'required_members' => 2,
        ]);
        TeamsMember::create([
            'teams_task_id' => $teamsTask->id,
            'member_name' => 'Alex Kioko',
            'is_active' => true,
        ]);

        $this->completeAs($task, $this->user())->assertStatus(422);
    }

    public function test_teams_completes_once_required_members_assigned(): void
    {
        $enquiry = $this->enquiry();
        $task = $this->task($enquiry, 'teams');
        $teamsTask = TeamsTask::create([
            'task_id' => $task->id,
            'category_id' => TeamCategory::create(['category_key' => 'setup_' . uniqid(), 'name' => 'Setup Crew', 'display_name' => 'Setup Crew'])->id,
            'team_type_id' => TeamType::create(['type_key' => 'field_' . uniqid(), 'name' => 'Field', 'display_name' => 'Field'])->id,
            'required_members' => 1,
        ]);
        TeamsMember::create([
            'teams_task_id' => $teamsTask->id,
            'member_name' => 'Alex Kioko',
            'is_active' => true,
        ]);

        $this->completeAs($task, $this->user())->assertOk();
    }

    public function test_admin_can_still_override_any_of_the_new_gates(): void
    {
        $task = $this->task($this->enquiry(), 'handover');
        HandoverSurvey::create([
            'task_id' => $task->id,
            'access_token' => 'tok_' . uniqid(),
            'submitted' => false,
        ]);

        $this->completeAs($task, $this->user('Super Admin'))->assertOk();
    }

    public function test_quote_tasks_are_restricted_to_finance_costing_and_project_manager_roles(): void
    {
        $enquiry = $this->enquiry();
        $quote = $this->task($enquiry, 'quote');
        $approval = $this->task($enquiry, 'quote_approval');

        foreach (['Super Admin', 'Project Manager', 'Costing', 'Accounts', 'Finance'] as $role) {
            $actor = $this->user($role);
            $this->assertTrue($quote->isUserAuthorized($actor), $role.' should access quote preparation.');
            $this->assertTrue($approval->isUserAuthorized($actor), $role.' should access quote approval.');
        }

        foreach (['Admin', 'Project Officer', 'Designer'] as $role) {
            $actor = $this->user($role);
            $this->assertFalse($quote->isUserAuthorized($actor), $role.' must not access quote preparation.');
            $this->assertFalse($approval->isUserAuthorized($actor), $role.' must not access quote approval.');
        }

        $designer = $this->user('Designer');
        Sanctum::actingAs($designer);
        $this->getJson("/api/projects/tasks/{$quote->id}/quote")->assertForbidden();
        $this->getJson("/api/projects/tasks/{$approval->id}/approval")->assertForbidden();
    }

    private function completeAs(EnquiryTask $task, User $actor)
    {
        Sanctum::actingAs($actor);

        return $this->putJson("/api/projects/enquiry-tasks/{$task->id}", ['status' => 'completed']);
    }

    private function validLogisticsPlanning(): array
    {
        return [
            'vehicle_identification' => 'KCA 001B',
            'driver_name' => 'Alex Kioko',
            'route' => ['destination' => 'Project Site'],
            'timeline' => [
                'loading_time' => '07:00',
                'departure_time' => '08:00',
                'setup_start_time' => now()->addDay()->toDateString(),
                'setup_start_hour' => '09:00',
            ],
        ];
    }

    private function user(?string $role = null): User
    {
        $user = User::create([
            'name' => uniqid('user_'),
            'email' => uniqid('user_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);

        if ($role) {
            \Spatie\Permission\Models\Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    private function client(): Client
    {
        return Client::create([
            'full_name' => 'Acme Test Client',
            'contact_person' => 'Jane Test',
            'email' => uniqid('client_') . '@test.local',
            'phone' => '0700000000',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'test',
            'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    private function enquiry(array $overrides = []): ProjectEnquiry
    {
        $creator = $overrides['created_by'] ?? $this->user()->id;

        return ProjectEnquiry::create(array_merge([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Gate Test Project',
            'description' => 'Completion-gate test project',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_IN_PROGRESS,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-TEST-' . uniqid(),
            'created_by' => $creator,
            'selected_workflow_tasks' => ['handover', 'setdown', 'report', 'setup', 'production', 'logistics', 'teams'],
            'workflow_preset_type' => 'external_project',
        ], $overrides));
    }

    private function task(ProjectEnquiry $enquiry, string $type, array $overrides = []): EnquiryTask
    {
        return EnquiryTask::create(array_merge([
            'project_enquiry_id' => $enquiry->id,
            'title' => ucfirst(str_replace('_', ' ', $type)),
            'type' => $type,
            'status' => 'in_progress',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'task_order' => 1,
            'created_by' => $enquiry->created_by,
        ], $overrides));
    }
}
