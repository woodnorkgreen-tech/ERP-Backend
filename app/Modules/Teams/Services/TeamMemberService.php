<?php

namespace App\Modules\Teams\Services;

use App\Modules\Teams\Models\TeamsMember;
use App\Modules\Teams\Models\TeamsTask;
use Illuminate\Support\Facades\DB;

class TeamMemberService
{
    public function getTeamMembers(int $teamTaskId): array
    {
        $members = TeamsMember::where('teams_task_id', $teamTaskId)
                             ->with(['assigner', 'unassigner'])
                             ->orderBy('is_lead', 'desc')
                             ->orderBy('assigned_at', 'asc')
                             ->get();

        return $members->toArray();
    }

    public function addMember(int $teamTaskId, array $data): TeamsMember
    {
        return DB::transaction(function () use ($teamTaskId, $data) {
            $teamTask = TeamsTask::findOrFail($teamTaskId);
            
            // Check if team has reached maximum members
            if ($teamTask->max_members && $teamTask->assigned_members_count >= $teamTask->max_members) {
                throw new \InvalidArgumentException('Team has reached maximum member capacity');
            }

            // Check for duplicate active member names
            $existingMember = TeamsMember::where('teams_task_id', $teamTaskId)
                                        ->where('member_name', $data['member_name'])
                                        ->where('is_active', true)
                                        ->first();

            if ($existingMember) {
                throw new \InvalidArgumentException('A team member with this name already exists');
            }

            $member = TeamsMember::create([
                'teams_task_id' => $teamTaskId,
                'member_name' => $data['member_name'],
                'member_email' => $data['member_email'] ?? null,
                'member_phone' => $data['member_phone'] ?? null,
                'member_role' => $data['member_role'] ?? null,
                'hourly_rate' => $data['hourly_rate'] ?? null,
                'is_lead' => $data['is_lead'] ?? false,
                'assigned_by' => auth()->id()
            ]);

            // Update team's assigned members count
            $teamTask->increment('assigned_members_count');

            // Log activity
            app(TeamsActivityLogService::class)->log([
                'teams_task_id' => $teamTaskId,
                'teams_member_id' => $member->id,
                'action' => 'member_added',
                'new_values' => $member->toArray(),
                'performed_by' => auth()->id()
            ]);

            return $member->load('assigner');
        });
    }

    public function updateMember(int $memberId, array $data): TeamsMember
    {
        $member = TeamsMember::findOrFail($memberId);
        $oldData = $member->toArray();

        return DB::transaction(function () use ($member, $data) {
            $member->update($data);

            // Log activity
            app(TeamsActivityLogService::class)->log([
                'teams_task_id' => $member->teams_task_id,
                'teams_member_id' => $member->id,
                'action' => 'updated',
                'old_values' => $oldData,
                'new_values' => $member->toArray(),
                'performed_by' => auth()->id()
            ]);

            return $member->fresh(['assigner', 'unassigner']);
        });
    }

    public function removeMember(int $memberId): bool
    {
        $member = TeamsMember::findOrFail($memberId);
        
        return DB::transaction(function () use ($member) {
            // Mark member as inactive instead of deleting
            $member->update([
                'is_active' => false,
                'unassigned_at' => now(),
                'unassigned_by' => auth()->id()
            ]);

            // Update team's assigned members count
            $member->teamsTask->decrement('assigned_members_count');

            // Log activity
            app(TeamsActivityLogService::class)->log([
                'teams_task_id' => $member->teams_task_id,
                'teams_member_id' => $member->id,
                'action' => 'member_removed',
                'old_values' => $member->toArray(),
                'performed_by' => auth()->id()
            ]);

            return true;
        });
    }
}