<?php

namespace Tests\Feature\UniversalTask;

use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\UniversalTask\Events\TaskStatusChanged;
use App\Modules\UniversalTask\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TaskBlockedReasonTest extends TestCase
{
    use RefreshDatabase;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        $department = Department::create(['name' => 'Task testing']);
        $this->task = Task::create([
            'title' => 'Prepare artwork',
            'created_by' => $user->id,
            'assigned_user_id' => $user->id,
            'department_id' => $department->id,
            'due_date' => now()->addDay(),
        ]);
        $this->actingAs($user, 'sanctum');
        Event::fake([TaskStatusChanged::class]);
    }

    public function test_blocking_saves_the_reason_submitted_with_the_status(): void
    {
        $this->patchJson("/api/universal-tasks/tasks/{$this->task->id}/status", [
            'status' => 'blocked',
            'blocked_reason' => 'Waiting for client approval',
        ])->assertOk()->assertJsonPath('data.status', 'blocked');

        $this->assertDatabaseHas('tasks', [
            'id' => $this->task->id,
            'status' => 'blocked',
            'blocked_reason' => 'Waiting for client approval',
        ]);
        Event::assertDispatched(TaskStatusChanged::class);
    }

    public function test_blocking_without_a_reason_does_not_change_the_task(): void
    {
        $this->patchJson("/api/universal-tasks/tasks/{$this->task->id}/status", [
            'status' => 'blocked',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame('pending', $this->task->fresh()->status);
        Event::assertNotDispatched(TaskStatusChanged::class);
    }

    public function test_other_statuses_do_not_require_a_blocking_reason(): void
    {
        $this->patchJson("/api/universal-tasks/tasks/{$this->task->id}/status", [
            'status' => 'in_progress',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');
    }

    public function test_overdue_cannot_be_assigned_manually(): void
    {
        $this->patchJson("/api/universal-tasks/tasks/{$this->task->id}/status", [
            'status' => 'overdue',
        ])->assertUnprocessable();

        $this->assertSame('pending', $this->task->fresh()->status);
    }
}
