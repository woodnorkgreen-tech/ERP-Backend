<?php

namespace App\Modules\Teams\Services;

use App\Modules\Teams\Models\TeamsMember;
use App\Modules\Teams\Models\TeamsTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

            // required_members is a staffing TARGET, not a hard cap — reaching
            // it must not block adding one more person if the crew genuinely
            // needs it. Only the combination's real physical max_members (set
            // from team_category_types at creation) is an actual ceiling.
            $activeCount = $teamTask->activeMembers()->count();
            $ceiling = $teamTask->max_members ? (int) $teamTask->max_members : null;
            if ($ceiling !== null && $activeCount >= $ceiling) {
                throw ValidationException::withMessages([
                    'member_name' => "This crew is already at its maximum of {$ceiling} people.",
                ]);
            }

            // Check for duplicate active member names
            $existingMember = TeamsMember::where('teams_task_id', $teamTaskId)
                                        ->where('member_name', $data['member_name'])
                                        ->where('is_active', true)
                                        ->first();

            if ($existingMember) {
                throw ValidationException::withMessages([
                    'member_name' => 'This person is already assigned to the crew.',
                ]);
            }

            $alreadyAssignedToPlan = TeamsMember::whereHas(
                'teamsTask',
                fn ($query) => $query->where('task_id', $teamTask->task_id)
            )
                ->where('is_active', true)
                ->where(function ($query) use ($data) {
                    if (!empty($data['technical_labour_id'])) {
                        $query->where('technical_labour_id', $data['technical_labour_id']);
                    } else {
                        $query->whereRaw('LOWER(member_name) = ?', [mb_strtolower($data['member_name'])]);
                    }
                })
                ->exists();

            if ($alreadyAssignedToPlan) {
                throw ValidationException::withMessages([
                    'member_name' => 'This person is already assigned elsewhere in this team plan.',
                ]);
            }

            $member = TeamsMember::create([
                'teams_task_id' => $teamTaskId,
                'technical_labour_id' => $data['technical_labour_id'] ?? null,
                'member_name' => $data['member_name'],
                'member_email' => $data['member_email'] ?? null,
                'member_phone' => $data['member_phone'] ?? null,
                'member_role' => $data['member_role'] ?? null,
                'hourly_rate' => $data['hourly_rate'] ?? null,
                'is_lead' => $data['is_lead'] ?? false,
                'assigned_by' => auth()->id()
            ]);

            // Update team's assigned members count. Growing past the current
            // target raises the target to match — the person was just added
            // because the crew needs them, not because the plan was wrong.
            $newActiveCount = $activeCount + 1;
            $updates = ['assigned_members_count' => $newActiveCount];
            if ($newActiveCount > (int) $teamTask->required_members) {
                $updates['required_members'] = $newActiveCount;
            }
            $teamTask->update($updates);

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

        if (!$member->is_active) {
            return true;
        }
        
        return DB::transaction(function () use ($member) {
            // Mark member as inactive instead of deleting
            $member->update([
                'is_active' => false,
                'unassigned_at' => now(),
                'unassigned_by' => auth()->id()
            ]);

            // Update team's assigned members count
            $teamTask = $member->teamsTask;
            $teamTask->update([
                'assigned_members_count' => max(0, $teamTask->activeMembers()->count()),
            ]);

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
