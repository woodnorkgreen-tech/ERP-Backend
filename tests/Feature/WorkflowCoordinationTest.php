<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\Projects\Services\EnquiryWorkflowService;
use App\Modules\Projects\Services\NotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Covers the workflow-coordination automation:
 *  - G1: prerequisite (task_dependencies) enforcement in validateTaskCompletion
 *  - G4: the "task ready to start" notification
 */
class WorkflowCoordinationTest extends TestCase
{
    use DatabaseTransactions;

    private EnquiryWorkflowService $workflow;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // A plain user (no roles) so admin-override branches are NOT taken.
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->workflow = $this->app->make(EnquiryWorkflowService::class);
    }

    private function makeEnquiry(): ProjectEnquiry
    {
        $client = \App\Modules\ClientService\Models\Client::factory()->create();

        return ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'title' => 'Test Event Production',
            'status' => 'enquiry_logged',
            'contact_person' => 'Jane Doe',
            'client_id' => $client->id,
            'enquiry_number' => 'ENQ-TEST-' . uniqid(),
            'created_by' => $this->user->id,
        ]);
    }

    private function makeTask(ProjectEnquiry $enquiry, string $type, string $status = 'pending'): EnquiryTask
    {
        return EnquiryTask::create([
            'project_enquiry_id' => $enquiry->id,
            'title' => ucfirst($type) . ' Task',
            'type' => $type,
            'status' => $status,
            'created_by' => $this->user->id,
            'task_order' => 1,
        ]);
    }

    /** @test */
    public function it_blocks_completing_a_task_whose_prerequisites_are_unfinished(): void
    {
        $enquiry = $this->makeEnquiry();
        // design depends on site-survey (config/enquiry_workflow.php)
        $this->makeTask($enquiry, 'site-survey', 'pending');
        $design = $this->makeTask($enquiry, 'design', 'pending');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('prerequisite');

        $this->workflow->validateTaskCompletion($design);
    }

    /** @test */
    public function it_allows_completion_once_all_prerequisites_are_satisfied(): void
    {
        $enquiry = $this->makeEnquiry();
        $this->makeTask($enquiry, 'site-survey', 'skipped'); // skipped counts as satisfied
        $design = $this->makeTask($enquiry, 'design', 'pending');

        // Should not throw.
        $this->workflow->validateTaskCompletion($design);
        $this->assertTrue(true);
    }

    /** @test */
    public function it_ignores_prerequisites_that_do_not_exist_on_the_enquiry(): void
    {
        // Preset omits "site-survey": design should be unblocked.
        $enquiry = $this->makeEnquiry();
        $design = $this->makeTask($enquiry, 'design', 'pending');

        $this->workflow->validateTaskCompletion($design);
        $this->assertTrue(true);
    }

    /** @test */
    public function it_blocks_through_a_multi_level_dependency_chain(): void
    {
        // production -> quote_approval -> quote: production stays blocked until the
        // whole chain upstream is satisfied.
        $enquiry = $this->makeEnquiry();
        $this->makeTask($enquiry, 'quote', 'completed');
        $this->makeTask($enquiry, 'quote_approval', 'pending');
        $production = $this->makeTask($enquiry, 'production', 'pending');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('prerequisite');

        $this->workflow->validateTaskCompletion($production);
    }

    /** @test */
    public function it_records_a_task_ready_notification(): void
    {
        $enquiry = $this->makeEnquiry();
        $task = $this->makeTask($enquiry, 'procurement', 'pending');

        $this->app->make(NotificationService::class)
            ->sendTaskReadyNotification($task, $this->user);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
            'type' => 'enquiry_task_ready',
            'notifiable_type' => EnquiryTask::class,
            'notifiable_id' => $task->id,
        ]);
    }

    /** @test */
    public function task_list_serialization_does_not_n_plus_one(): void
    {
        $enquiry = $this->makeEnquiry();
        foreach (['design', 'budget', 'quote', 'production', 'materials'] as $type) {
            $this->makeTask($enquiry, $type, 'pending');
        }

        // Eager-load (its queries happen here, before we start counting).
        $tasks = EnquiryTask::withTaskData()
            ->where('project_enquiry_id', $enquiry->id)
            ->get();

        \DB::enableQueryLog();
        $tasks->toArray(); // triggers task_data + is_authorized accessors for every row
        $queryCount = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        // Accessor evaluation must not scale with the number of rows.
        $this->assertLessThanOrEqual(3, $queryCount, "task_data/is_authorized caused N+1: {$queryCount} queries for 5 tasks");
    }

    /** @test */
    public function it_grants_pool_access_to_a_collaborator_department(): void
    {
        // 'design' maps to [Design/Creatives, Production, Projects, Client Service].
        $production = Department::create(['name' => 'Production']);
        $user = User::factory()->create(['department_id' => $production->id]);

        $enquiry = $this->makeEnquiry();
        $design = $this->makeTask($enquiry, 'design', 'pending');

        $this->assertTrue($design->isUserAuthorized($user));
    }

    /** @test */
    public function it_denies_pool_access_to_an_unrelated_department(): void
    {
        // 'design' does NOT list Stores.
        $stores = Department::create(['name' => 'Stores']);
        $user = User::factory()->create(['department_id' => $stores->id]);

        $enquiry = $this->makeEnquiry();
        $design = $this->makeTask($enquiry, 'design', 'pending');

        $this->assertFalse($design->isUserAuthorized($user));
    }

    /** @test */
    public function department_pool_scope_returns_owned_and_collaborator_tasks(): void
    {
        $production = Department::create(['name' => 'Production']);
        $enquiry = $this->makeEnquiry();
        $this->makeTask($enquiry, 'design', 'pending');  // Production is a collaborator
        $this->makeTask($enquiry, 'budget', 'pending');  // Production is NOT listed

        $types = EnquiryTask::forDepartmentPool($production->id)
            ->where('project_enquiry_id', $enquiry->id)
            ->pluck('type')
            ->all();

        $this->assertContains('design', $types);
        $this->assertNotContains('budget', $types);
    }
}
