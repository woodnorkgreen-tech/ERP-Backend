<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

class UniversalTaskAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $task;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a user and task for testing
        $this->user = User::factory()->create();
        $this->task = Task::factory()->create([
            'created_by' => $this->user->id,
            'department_id' => 1,
        ]);
        
        // Mock the permission service to always allow actions
        $permissionServiceMock = \Mockery::mock('App\\Modules\\UniversalTask\\Services\\TaskPermissionService');
        $permissionServiceMock->shouldReceive('canView')->andReturn(true);
        $permissionServiceMock->shouldReceive('canEdit')->andReturn(true);
        $permissionServiceMock->shouldReceive('canDelete')->andReturn(true);
        $permissionServiceMock->shouldReceive('canChangeStatus')->andReturn(true);
        $permissionServiceMock->shouldReceive('canAssign')->andReturn(true);
        $permissionServiceMock->shouldReceive('logPermissionDenial')->andReturn(null);
        
        $this->app->instance('App\\Modules\\UniversalTask\\Services\\TaskPermissionService', $permissionServiceMock);
    }

    /** @test */
    public function it_can_assign_multiple_users_with_roles_to_a_task()
    {
        $this->actingAs($this->user, 'sanctum');

        // Create additional users for assignment
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        $response = $this->postJson("/api/universal-tasks/tasks/{$this->task->id}/assign", [
            'assignments' => [
                [
                    'user_id' => $user1->id,
                    'role' => 'captain',
                    'is_primary' => true,
                ],
                [
                    'user_id' => $user2->id,
                    'role' => 'technician',
                    'is_primary' => false,
                ],
                [
                    'user_id' => $user3->id,
                    'role' => 'reviewer',
                    'is_primary' => false,
                ],
            ],
            'replace_existing' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Task assigned successfully.',
        ]);

        // Verify assignments were created
        $this->assertCount(3, $this->task->assignments);
        
        // Verify primary assignee
        $primaryAssignment = $this->task->assignments()->where('is_primary', true)->first();
        $this->assertEquals($user1->id, $primaryAssignment->user_id);
        $this->assertEquals('captain', $primaryAssignment->role);
        
        // Verify other assignments
        $technicianAssignment = $this->task->assignments()->where('user_id', $user2->id)->first();
        $this->assertEquals('technician', $technicianAssignment->role);
        $this->assertFalse($technicianAssignment->is_primary);
        
        $reviewerAssignment = $this->task->assignments()->where('user_id', $user3->id)->first();
        $this->assertEquals('reviewer', $reviewerAssignment->role);
        $this->assertFalse($reviewerAssignment->is_primary);
    }

    /** @test */
    public function it_can_get_assignees_with_roles_for_a_task()
    {
        $this->actingAs($this->user, 'sanctum');

        // Create users and assignments
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        TaskAssignment::create([
            'task_id' => $this->task->id,
            'user_id' => $user1->id,
            'assigned_by' => $this->user->id,
            'role' => 'captain',
            'is_primary' => true,
        ]);

        TaskAssignment::create([
            'task_id' => $this->task->id,
            'user_id' => $user2->id,
            'assigned_by' => $this->user->id,
            'role' => 'technician',
            'is_primary' => false,
        ]);

        $response = $this->getJson("/api/universal-tasks/tasks/{$this->task->id}/assignees");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
        
        $responseData = $response->json()['data'];
        $this->assertCount(2, $responseData);
        
        // Verify captain
        $captain = collect($responseData)->firstWhere('role', 'captain');
        $this->assertEquals($user1->id, $captain['user']['id']);
        $this->assertTrue($captain['is_primary']);
        
        // Verify technician
        $technician = collect($responseData)->firstWhere('role', 'technician');
        $this->assertEquals($user2->id, $technician['user']['id']);
        $this->assertFalse($technician['is_primary']);
    }

    /** @test */
    public function it_can_remove_an_assignee_from_a_task()
    {
        $this->actingAs($this->user, 'sanctum');

        // Create users and assignments
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $assignment1 = TaskAssignment::create([
            'task_id' => $this->task->id,
            'user_id' => $user1->id,
            'assigned_by' => $this->user->id,
            'role' => 'captain',
            'is_primary' => true,
        ]);

        $assignment2 = TaskAssignment::create([
            'task_id' => $this->task->id,
            'user_id' => $user2->id,
            'assigned_by' => $this->user->id,
            'role' => 'technician',
            'is_primary' => false,
        ]);

        // Verify initial state
        $this->assertCount(2, $this->task->assignments);

        // Remove the technician
        $response = $this->deleteJson("/api/universal-tasks/tasks/{$this->task->id}/assignees/{$assignment2->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Assignee removed successfully.',
        ]);

        // Verify assignment was removed
        $this->assertCount(1, $this->task->fresh()->assignments);
        
        // Verify the captain is still there
        $remainingAssignment = $this->task->assignments->first();
        $this->assertEquals($user1->id, $remainingAssignment->user_id);
        $this->assertEquals('captain', $remainingAssignment->role);
        $this->assertTrue($remainingAssignment->is_primary);
    }
}