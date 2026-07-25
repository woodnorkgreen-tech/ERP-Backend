<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Exceptions\WorkflowValidationException;
use App\Models\DesignAsset;
use App\Models\ProjectEnquiry;
use App\Models\SiteSurvey;
use App\Models\TaskProcurementData;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\Projects\Services\EnquiryWorkflowService;
use App\Modules\Teams\Models\TeamCategory;
use App\Modules\Teams\Models\TeamType;
use App\Modules\Teams\Models\TeamsMember;
use App\Modules\Teams\Models\TeamsTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowCompletionValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_survey_requires_a_final_submission(): void
    {
        $task = $this->task('site-survey');

        $this->expectException(WorkflowValidationException::class);
        $this->expectExceptionMessage('Submit the final survey');

        $this->workflow()->validateTaskCompletion($task);
    }

    public function test_completed_site_survey_is_valid_completion_evidence(): void
    {
        $task = $this->task('site-survey');
        SiteSurvey::create([
            'project_enquiry_id' => $task->project_enquiry_id,
            'enquiry_task_id' => $task->id,
            'site_visit_date' => now()->toDateString(),
            'client_name' => 'Test Client',
            'location' => 'Test Site',
            'project_description' => 'Test survey',
            'status' => 'completed',
        ]);

        $this->workflow()->validateTaskCompletion($task);
        $this->assertTrue(true);
    }

    public function test_design_requires_an_approved_asset(): void
    {
        $task = $this->task('design');
        DesignAsset::create([
            'enquiry_task_id' => $task->id,
            'name' => 'Draft layout',
            'original_name' => 'layout.pdf',
            'file_path' => 'designs/layout.pdf',
            'file_size' => 100,
            'mime_type' => 'application/pdf',
            'category' => 'concept',
            'status' => 'pending',
            'version' => 1,
            'uploaded_by' => auth()->id(),
        ]);

        $this->expectException(WorkflowValidationException::class);
        $this->expectExceptionMessage('design asset must be approved');

        $this->workflow()->validateTaskCompletion($task);
    }

    public function test_procurement_blocks_only_items_still_awaiting_receipt(): void
    {
        $task = $this->task('procurement');
        $data = TaskProcurementData::create([
            'enquiry_task_id' => $task->id,
            'project_info' => [],
            'budget_imported' => true,
            'procurement_items' => [[
                'budgetItemId' => 'item-1',
                'description' => 'Timber',
                'purchaseQuantity' => 10,
                'procurementStatus' => 'ordered',
                'availabilityStatus' => 'ordered',
            ]],
            'budget_summary' => [],
        ]);

        try {
            $this->workflow()->validateTaskCompletion($task);
            $this->fail('Open procurement items should block completion.');
        } catch (WorkflowValidationException $exception) {
            $this->assertStringContainsString('awaiting receipt', $exception->getMessage());
        }

        $items = $data->procurement_items;
        $items[0]['procurementStatus'] = 'received';
        $data->update(['procurement_items' => $items]);

        $this->workflow()->validateTaskCompletion($task->fresh());
        $this->assertTrue(true);
    }

    public function test_every_planned_team_must_be_fully_staffed(): void
    {
        $task = $this->task('teams');
        $category = TeamCategory::create([
            'category_key' => 'setup-test',
            'name' => 'Setup',
            'display_name' => 'Setup',
        ]);
        $type = TeamType::create([
            'type_key' => 'technicians-test',
            'name' => 'Technicians',
            'display_name' => 'Technicians',
        ]);

        $first = TeamsTask::create([
            'task_id' => $task->id,
            'category_id' => $category->id,
            'team_type_id' => $type->id,
            'required_members' => 1,
            'assigned_members_count' => 1,
            'priority' => 'medium',
        ]);
        $second = TeamsTask::create([
            'task_id' => $task->id,
            'category_id' => $category->id,
            'team_type_id' => $type->id,
            'required_members' => 2,
            'assigned_members_count' => 1,
            'priority' => 'medium',
        ]);

        TeamsMember::create(['teams_task_id' => $first->id, 'member_name' => 'First Person']);
        TeamsMember::create(['teams_task_id' => $second->id, 'member_name' => 'Second Person']);

        try {
            $this->workflow()->validateTaskCompletion($task);
            $this->fail('An understaffed team should block the lifecycle task.');
        } catch (WorkflowValidationException $exception) {
            $this->assertStringContainsString('1 team(s) still need 1 member', $exception->getMessage());
        }

        TeamsMember::create(['teams_task_id' => $second->id, 'member_name' => 'Third Person']);

        $this->workflow()->validateTaskCompletion($task->fresh());
        $this->assertTrue(true);
    }

    private function workflow(): EnquiryWorkflowService
    {
        return app(EnquiryWorkflowService::class);
    }

    private function task(string $type): EnquiryTask
    {
        $user = User::create([
            'name' => uniqid('workflow_user_'),
            'email' => uniqid('workflow_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $client = Client::create([
            'full_name' => 'Workflow Test Client',
            'contact_person' => 'Jane Test',
            'email' => uniqid('client_') . '@test.local',
            'phone' => '0700000000',
            'address' => 'Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'test',
            'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
            'status' => 'active',
            'is_active' => true,
        ]);

        $enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $client->id,
            'title' => 'Workflow Completion Test',
            'description' => 'Completion evidence test',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-TEST-' . uniqid(),
            'created_by' => $user->id,
            'selected_workflow_tasks' => [$type],
        ]);

        return EnquiryTask::create([
            'project_enquiry_id' => $enquiry->id,
            'title' => ucfirst(str_replace('-', ' ', $type)),
            'type' => $type,
            'status' => 'in_progress',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'task_order' => 1,
            'created_by' => $user->id,
        ]);
    }
}
