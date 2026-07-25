<?php

namespace App\Modules\Teams\Services;

use App\Modules\Teams\Models\TeamsTask;
use App\Modules\Teams\Models\TeamCategory;
use App\Modules\Teams\Models\TeamType;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class TeamsTaskService
{
    public function getTeamsForTask(int $taskId): array
    {
        return $this->getTaskTeams($taskId);
    }

    public function getTaskTeams(int $taskId, array $filters = []): array
    {
        $query = TeamsTask::where('task_id', $taskId)
                          ->with(['category', 'teamType', 'activeMembers', 'members.assigner']);

        // Apply filters
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        $teams = $query->get();

        return $teams->map(function ($team) {
            return [
                'id' => $team->id,
                'category' => $team->category,
                'team_type' => $team->teamType,
                'status' => $team->status,
                'required_members' => $team->required_members,
                'assigned_members_count' => $team->assigned_members_count,
                'completion_percentage' => $team->completion_percentage,
                'start_date' => $team->start_date,
                'end_date' => $team->end_date,
                'priority' => $team->priority,
                'is_overdue' => $team->is_overdue,
                'days_until_deadline' => $team->days_until_deadline,
                'members' => $team->activeMembers,
                'notes' => $team->notes
            ];
        })->toArray();
    }

    public function createTeamTask(int $taskId, array $data): TeamsTask
    {
        return DB::transaction(function () use ($taskId, $data) {
            \App\Modules\Projects\Models\EnquiryTask::findOrFail($taskId);
            $combination = $this->validateCategoryTeamTypeCombination(
                $data['category_id'],
                $data['team_type_id'],
                $data['required_members']
            );

            if (TeamsTask::where('task_id', $taskId)
                ->where('category_id', $data['category_id'])
                ->where('team_type_id', $data['team_type_id'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'team_type_id' => 'This crew already exists for the selected stage.',
                ]);
            }

            $createData = [
                'task_id' => $taskId,
                'category_id' => $data['category_id'],
                'team_type_id' => $data['team_type_id'],
                'status' => 'pending',
                'required_members' => $data['required_members'],
                'assigned_members_count' => 0,
                'max_members' => $combination->max_members,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'estimated_hours' => $data['estimated_hours'] ?? null,
                'notes' => $data['notes'] ?? null,
                'special_requirements' => $data['special_requirements'] ?? null,
                'priority' => $data['priority'] ?? 'medium',
                'created_by' => auth()->id()
            ];

            $teamTask = TeamsTask::create($createData);

            $this->logActivity($teamTask, 'created', null, $teamTask->toArray());

            return $teamTask->load(['category', 'teamType']);
        });
    }

    public function updateTeamTask(int $teamTaskId, array $data): TeamsTask
    {
        $teamTask = TeamsTask::findOrFail($teamTaskId);
        $oldData = $teamTask->toArray();

        return DB::transaction(function () use ($teamTask, $data, $oldData) {
            // If status is being changed to completed, set completed_at
            if (isset($data['status']) && $data['status'] === 'completed' && $teamTask->status !== 'completed') {
                $data['completed_at'] = Carbon::now();
            }

            $teamTask->update([
                ...$data,
                'updated_by' => auth()->id()
            ]);

            // Log activity
            $this->logActivity($teamTask, 'updated', $oldData, $teamTask->toArray());

            return $teamTask->fresh(['category', 'teamType', 'activeMembers']);
        });
    }

    public function deleteTeamTask(int $teamTaskId): bool
    {
        $teamTask = TeamsTask::findOrFail($teamTaskId);
        
        DB::transaction(function () use ($teamTask) {
            // Log deletion activity
            $this->logActivity($teamTask, 'cancelled', $teamTask->toArray(), null);
            
            $teamTask->delete();
        });

        return true;
    }

    public function bulkAssignTeams(int $taskId, array $assignments): array
    {
        $results = [];
        
        DB::transaction(function () use ($taskId, $assignments, &$results) {
            foreach ($assignments as $assignment) {
                try {
                    // Create team task
                    $teamTask = $this->createTeamTask($taskId, $assignment);
                    
                    // Add members if provided
                    $addedMembers = [];
                    if (isset($assignment['members']) && is_array($assignment['members'])) {
                        foreach ($assignment['members'] as $memberName) {
                            $member = app(TeamMemberService::class)->addMember($teamTask->id, [
                                'member_name' => $memberName
                            ]);
                            $addedMembers[] = $member;
                        }
                    }
                    
                    $results[] = [
                        'team_task' => $teamTask,
                        'members_added' => count($addedMembers),
                        'success' => true
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'assignment' => $assignment,
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }
        });

        return $results;
    }

    private function validateCategoryTeamTypeCombination(int $categoryId, int $teamTypeId, int $requiredMembers): object
    {
        $combination = DB::table('team_category_types')
                   ->where('category_id', $categoryId)
                   ->where('team_type_id', $teamTypeId)
                   ->where('is_available', true)
                   ->first();

        if (!$combination) {
            throw ValidationException::withMessages([
                'team_type_id' => 'The selected crew type is not available for this stage.',
            ]);
        }

        if ($requiredMembers < $combination->min_members || $requiredMembers > $combination->max_members) {
            throw ValidationException::withMessages([
                'required_members' => "Crew size must be between {$combination->min_members} and {$combination->max_members}.",
            ]);
        }

        return $combination;
    }

    private function logActivity(TeamsTask $teamTask, string $action, ?array $oldValues, ?array $newValues): void
    {
        app(TeamsActivityLogService::class)->log([
            'teams_task_id' => $teamTask->id,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'performed_by' => auth()->id()
        ]);
    }
}
