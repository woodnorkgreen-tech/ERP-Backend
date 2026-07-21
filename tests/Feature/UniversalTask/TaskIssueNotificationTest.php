<?php

namespace Tests\Feature\UniversalTask;

use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\UniversalTask\Listeners\SendTaskIssueNotification;
use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskIssue;
use App\Modules\UniversalTask\Services\TaskNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for two 2026-07-21 fixes to the notification-
 * consolidation work: (1) SendTaskIssueNotification now routes through the
 * central notification engine (TaskNotificationService::notifyTaskIssue)
 * instead of writing App\Models\Notification rows directly, so mail/push
 * preferences are honoured; (2) TaskNotificationService::getTaskSupervisors()
 * was a stub that always returned [] — department managers silently never
 * got escalation notifications for critical/high issues. Both are fixed
 * together since the listener depends on the (now-fixed) supervisor lookup.
 */
class TaskIssueNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_critical_issue_notifies_both_assignee_and_department_manager(): void
    {
        $managerUser = User::create([
            'name' => 'Dept Manager',
            'email' => uniqid('manager_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);

        $department = Department::create([
            'name' => 'Fabrication',
            'description' => 'Test department',
            'budget' => 100000,
            'location' => 'Workshop',
        ]);

        $managerEmployee = Employee::create([
            'employee_id' => 'EMP-TEST-' . uniqid(),
            'first_name' => 'Dept',
            'last_name' => 'Manager',
            'email' => uniqid('emp_') . '@test.local',
            'department_id' => $department->id,
            'position' => 'Manager',
            'hire_date' => now()->toDateString(),
        ]);
        $managerUser->update(['employee_id' => $managerEmployee->id]);
        $department->update(['manager_id' => $managerEmployee->id]);

        $assignee = User::create([
            'name' => 'Assignee',
            'email' => uniqid('assignee_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);

        $task = Task::factory()->create([
            'department_id' => $department->id,
            'assigned_user_id' => $assignee->id,
        ]);

        $issue = TaskIssue::create([
            'task_id' => $task->id,
            'title' => 'Machine down',
            'description' => 'CNC router jammed mid-cut',
            'issue_type' => 'blocker',
            'severity' => 'critical',
            'status' => 'open',
            'reported_by' => $assignee->id,
            'reported_at' => now(),
        ]);

        app(SendTaskIssueNotification::class)->handle(
            new \App\Modules\UniversalTask\Events\TaskIssueLogged($issue)
        );

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $assignee->id,
            'type' => 'universal_task_issue',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $managerUser->id,
            'type' => 'universal_task_issue',
        ]);
    }

    public function test_low_severity_issue_does_not_notify_anyone(): void
    {
        $assignee = User::create([
            'name' => 'Assignee',
            'email' => uniqid('assignee_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);

        $task = Task::factory()->create(['assigned_user_id' => $assignee->id]);

        $issue = TaskIssue::create([
            'task_id' => $task->id,
            'title' => 'Minor cosmetic scratch',
            'description' => 'Small scratch on panel edge',
            'issue_type' => 'other',
            'severity' => 'low',
            'status' => 'open',
            'reported_by' => $assignee->id,
            'reported_at' => now(),
        ]);

        app(SendTaskIssueNotification::class)->handle(
            new \App\Modules\UniversalTask\Events\TaskIssueLogged($issue)
        );

        $this->assertDatabaseMissing('app_notifications', ['user_id' => $assignee->id]);
    }

    public function test_department_with_no_manager_still_notifies_assignee(): void
    {
        $assignee = User::create([
            'name' => 'Assignee',
            'email' => uniqid('assignee_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);

        $department = Department::create([
            'name' => 'Unmanaged Dept',
            'description' => 'Test department',
            'budget' => 50000,
            'location' => 'Warehouse',
        ]);

        $task = Task::factory()->create([
            'department_id' => $department->id,
            'assigned_user_id' => $assignee->id,
        ]);

        $issue = TaskIssue::create([
            'task_id' => $task->id,
            'title' => 'Broken hinge',
            'description' => 'Hinge snapped during transport',
            'issue_type' => 'blocker',
            'severity' => 'high',
            'status' => 'open',
            'reported_by' => $assignee->id,
            'reported_at' => now(),
        ]);

        app(SendTaskIssueNotification::class)->handle(
            new \App\Modules\UniversalTask\Events\TaskIssueLogged($issue)
        );

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $assignee->id,
            'type' => 'universal_task_issue',
        ]);
    }
}
