<?php

namespace Tests\Feature\UniversalTask;

use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskDepartmentPrefix;
use App\Modules\UniversalTask\Models\TaskLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaskTrackerApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('Super Admin', 'web');

        $this->admin = User::create([
            'name' => 'Task Admin',
            'email' => uniqid('task_admin_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $this->admin->assignRole('Super Admin');

        $this->department = Department::create([
            'name' => 'Operations',
            'description' => 'Task tracker test department',
            'budget' => 100000,
            'location' => 'HQ',
        ]);

        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_admin_can_create_update_and_delete_task_labels(): void
    {
        $createResponse = $this->postJson('/api/universal-tasks/admin/labels', [
            'name' => 'Design Review',
            'color' => '#2563eb',
            'description' => 'Tasks that need design review',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.name', 'Design Review')
            ->assertJsonPath('data.slug', 'design-review');

        $labelId = $createResponse->json('data.id');

        $this->putJson("/api/universal-tasks/admin/labels/{$labelId}", [
            'name' => 'Production Review',
            'color' => '#16a34a',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Production Review')
            ->assertJsonPath('data.slug', 'production-review')
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/universal-tasks/admin/labels/{$labelId}")
            ->assertOk();

        $this->assertDatabaseMissing('task_labels', ['id' => $labelId]);
    }

    public function test_tasks_can_be_created_and_updated_with_labels(): void
    {
        $firstLabel = TaskLabel::create([
            'name' => 'Urgent Client',
            'slug' => 'urgent-client',
            'color' => '#dc2626',
            'created_by' => $this->admin->id,
        ]);
        $secondLabel = TaskLabel::create([
            'name' => 'Internal',
            'slug' => 'internal',
            'color' => '#0f766e',
            'created_by' => $this->admin->id,
        ]);

        $createResponse = $this->postJson('/api/universal-tasks/tasks', [
            'title' => 'Prepare project tracker',
            'description' => 'Create the initial tracker task.',
            'task_type' => 'coordination',
            'status' => 'pending',
            'priority' => 'high',
            'department_id' => $this->department->id,
            'due_date' => now()->addWeek()->toDateString(),
            'label_ids' => [$firstLabel->id],
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.labels.0.id', $firstLabel->id);

        $taskId = $createResponse->json('data.id');
        $this->assertDatabaseHas('task_label', [
            'task_id' => $taskId,
            'task_label_id' => $firstLabel->id,
        ]);

        $this->putJson("/api/universal-tasks/tasks/{$taskId}", [
            'label_ids' => [$secondLabel->id],
        ])->assertOk()
            ->assertJsonPath('data.labels.0.id', $secondLabel->id);

        $this->assertDatabaseMissing('task_label', [
            'task_id' => $taskId,
            'task_label_id' => $firstLabel->id,
        ]);
        $this->assertDatabaseHas('task_label', [
            'task_id' => $taskId,
            'task_label_id' => $secondLabel->id,
        ]);
    }

    public function test_label_attached_to_tasks_cannot_be_deleted(): void
    {
        $label = TaskLabel::create([
            'name' => 'In Use',
            'slug' => 'in-use',
            'color' => '#7c3aed',
            'created_by' => $this->admin->id,
        ]);
        $task = Task::factory()->create([
            'created_by' => $this->admin->id,
            'department_id' => $this->department->id,
        ]);
        $task->labels()->attach($label->id);

        $this->deleteJson("/api/universal-tasks/admin/labels/{$label->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LABEL_IN_USE');

        $this->assertDatabaseHas('task_labels', ['id' => $label->id]);
        $this->assertDatabaseHas('task_label', [
            'task_id' => $task->id,
            'task_label_id' => $label->id,
        ]);
    }

    public function test_created_task_receives_department_prefixed_task_code(): void
    {
        TaskDepartmentPrefix::create([
            'department_id' => $this->department->id,
            'prefix' => 'OPS',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->postJson('/api/universal-tasks/tasks', [
            'title' => 'Code generation task',
            'department_id' => $this->department->id,
            'due_date' => now()->addWeek()->toDateString(),
        ]);

        $response->assertCreated();

        $taskId = $response->json('data.id');
        $this->assertSame(
            sprintf('OPS-%04d', $taskId),
            $response->json('data.task_code')
        );
        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'task_code' => sprintf('OPS-%04d', $taskId),
        ]);
    }

    public function test_task_can_be_created_with_urgent_priority(): void
    {
        $response = $this->postJson('/api/universal-tasks/tasks', [
            'title' => 'Urgent priority task',
            'department_id' => $this->department->id,
            'priority' => 'urgent',
            'due_date' => now()->addWeek()->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.priority', 'urgent');

        $this->assertDatabaseHas('tasks', [
            'id' => $response->json('data.id'),
            'priority' => 'urgent',
        ]);
    }

    public function test_archived_list_modes_filter_tasks(): void
    {
        $openTask = Task::factory()->create([
            'created_by' => $this->admin->id,
            'department_id' => $this->department->id,
            'title' => 'Open task',
            'status' => 'pending',
            'archived_at' => null,
        ]);
        $archivedTask = Task::factory()->create([
            'created_by' => $this->admin->id,
            'department_id' => $this->department->id,
            'title' => 'Archived task',
            'status' => 'completed',
            'completed_at' => now()->subMonths(7),
            'archived_at' => now(),
            'archived_by' => $this->admin->id,
        ]);

        $this->getJson('/api/universal-tasks/tasks?per_page=50')
            ->assertOk()
            ->assertJsonFragment(['title' => $openTask->title])
            ->assertJsonMissing(['title' => $archivedTask->title]);

        $this->getJson('/api/universal-tasks/tasks?archived=only&per_page=50')
            ->assertOk()
            ->assertJsonFragment(['title' => $archivedTask->title])
            ->assertJsonMissing(['title' => $openTask->title]);

        $this->getJson('/api/universal-tasks/tasks?archived=with&per_page=50')
            ->assertOk()
            ->assertJsonFragment(['title' => $openTask->title])
            ->assertJsonFragment(['title' => $archivedTask->title]);
    }

    public function test_department_member_can_view_department_tasks_and_filter_to_self(): void
    {
        $member = $this->departmentMember($this->department, 'Department Member');
        $peer = $this->departmentMember($this->department, 'Department Peer');
        $otherDepartment = $this->department('Finance');

        $peerTask = Task::factory()->create([
            'created_by' => $peer->id,
            'assigned_user_id' => $peer->id,
            'department_id' => $this->department->id,
            'title' => 'Peer department task',
            'status' => 'pending',
        ]);
        $memberTask = Task::factory()->create([
            'created_by' => $peer->id,
            'assigned_user_id' => $member->id,
            'department_id' => $this->department->id,
            'title' => 'Member assigned task',
            'status' => 'pending',
        ]);
        $otherDepartmentTask = Task::factory()->create([
            'created_by' => $peer->id,
            'department_id' => $otherDepartment->id,
            'title' => 'Other department task',
            'status' => 'pending',
        ]);

        $this->actingAs($member, 'sanctum');

        $this->getJson('/api/universal-tasks/tasks?per_page=50')
            ->assertOk()
            ->assertJsonFragment(['title' => $peerTask->title])
            ->assertJsonFragment(['title' => $memberTask->title])
            ->assertJsonMissing(['title' => $otherDepartmentTask->title]);

        $this->getJson("/api/universal-tasks/tasks?assigned_user_id={$member->id}&per_page=50")
            ->assertOk()
            ->assertJsonFragment(['title' => $memberTask->title])
            ->assertJsonMissing(['title' => $peerTask->title])
            ->assertJsonMissing(['title' => $otherDepartmentTask->title]);
    }

    public function test_department_member_can_archive_restore_and_view_department_archives(): void
    {
        $member = $this->departmentMember($this->department, 'Archive Member');
        $peer = $this->departmentMember($this->department, 'Archive Peer');
        $otherDepartment = $this->department('Stores');

        $departmentTask = Task::factory()->create([
            'created_by' => $peer->id,
            'department_id' => $this->department->id,
            'title' => 'Department completed task',
            'status' => 'completed',
            'completed_at' => now()->subMonths(8),
        ]);
        $otherDepartmentTask = Task::factory()->create([
            'created_by' => $peer->id,
            'department_id' => $otherDepartment->id,
            'title' => 'Other completed task',
            'status' => 'completed',
            'completed_at' => now()->subMonths(8),
        ]);

        $this->actingAs($member, 'sanctum');

        $this->postJson('/api/universal-tasks/tasks/archive', [
            'task_ids' => [$departmentTask->id, $otherDepartmentTask->id],
        ])->assertOk()
            ->assertJsonPath('data.archived', 1)
            ->assertJsonPath('data.skipped', 1);

        $this->assertNotNull($departmentTask->fresh()->archived_at);
        $this->assertNull($otherDepartmentTask->fresh()->archived_at);

        $this->getJson('/api/universal-tasks/tasks?archived=only&per_page=50')
            ->assertOk()
            ->assertJsonFragment(['title' => $departmentTask->title])
            ->assertJsonMissing(['title' => $otherDepartmentTask->title]);

        $this->patchJson("/api/universal-tasks/tasks/{$departmentTask->id}/restore-archive")
            ->assertOk()
            ->assertJsonPath('data.id', $departmentTask->id);

        $this->assertNull($departmentTask->fresh()->archived_at);
    }

    public function test_completed_and_cancelled_tasks_can_be_archived_and_restored(): void
    {
        $completedTask = Task::factory()->create([
            'created_by' => $this->admin->id,
            'department_id' => $this->department->id,
            'status' => 'completed',
            'completed_at' => now()->subMonths(8),
        ]);
        $openTask = Task::factory()->create([
            'created_by' => $this->admin->id,
            'department_id' => $this->department->id,
            'status' => 'in_progress',
        ]);

        $this->postJson('/api/universal-tasks/tasks/archive', [
            'task_ids' => [$completedTask->id, $openTask->id],
        ])->assertOk()
            ->assertJsonPath('data.archived', 1)
            ->assertJsonPath('data.skipped', 1);

        $this->assertNotNull($completedTask->fresh()->archived_at);
        $this->assertNull($openTask->fresh()->archived_at);

        $this->patchJson("/api/universal-tasks/tasks/{$completedTask->id}/restore-archive")
            ->assertOk()
            ->assertJsonPath('data.id', $completedTask->id);

        $this->assertNull($completedTask->fresh()->archived_at);
    }

    public function test_force_archive_does_not_archive_active_tasks(): void
    {
        $recentCompletedTask = Task::factory()->create([
            'created_by' => $this->admin->id,
            'department_id' => $this->department->id,
            'status' => 'completed',
            'completed_at' => now()->subWeek(),
        ]);
        $activeTask = Task::factory()->create([
            'created_by' => $this->admin->id,
            'department_id' => $this->department->id,
            'status' => 'in_progress',
        ]);

        $this->postJson('/api/universal-tasks/tasks/archive', [
            'task_ids' => [$recentCompletedTask->id, $activeTask->id],
            'force' => true,
        ])->assertOk()
            ->assertJsonPath('data.archived', 1)
            ->assertJsonPath('data.skipped', 1);

        $this->assertNotNull($recentCompletedTask->fresh()->archived_at);
        $this->assertNull($activeTask->fresh()->archived_at);
    }

    public function test_blocked_and_overdue_are_rejected_as_task_statuses(): void
    {
        foreach (['blocked', 'overdue'] as $status) {
            $this->postJson('/api/universal-tasks/tasks', [
                'title' => "Invalid {$status} task",
                'department_id' => $this->department->id,
                'due_date' => now()->addWeek()->toDateString(),
                'status' => $status,
            ])->assertUnprocessable();
        }

        $task = Task::factory()->create([
            'created_by' => $this->admin->id,
            'department_id' => $this->department->id,
            'status' => 'pending',
        ]);

        foreach (['blocked', 'overdue'] as $status) {
            $this->patchJson("/api/universal-tasks/tasks/{$task->id}/status", [
                'status' => $status,
            ])->assertUnprocessable();
        }
    }

    private function department(string $name): Department
    {
        return Department::create([
            'name' => $name,
            'description' => "{$name} department",
            'budget' => 100000,
            'location' => 'HQ',
        ]);
    }

    private function departmentMember(Department $department, string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => uniqid('dept_member_') . '@test.local',
            'password' => bcrypt('secret'),
            'department_id' => $department->id,
            'is_active' => true,
        ]);
    }
}
