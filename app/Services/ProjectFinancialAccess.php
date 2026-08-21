<?php

namespace App\Services;

use App\Constants\Permissions;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Projects\Models\EnquiryTask;

/** Project-scoped finance access shared by the cost account and additions. */
class ProjectFinancialAccess
{
    public function task(int $taskId): EnquiryTask
    {
        return EnquiryTask::query()->with(['enquiry', 'assignedUsers'])->findOrFail($taskId);
    }

    public function isAssigned(User $user, ProjectEnquiry $enquiry): bool
    {
        if ((int) $enquiry->project_officer_id === (int) $user->id
            || (int) $enquiry->assigned_po === (int) $user->id) {
            return true;
        }

        $assigned = collect($enquiry->assigned_users ?? [])->map(fn ($id) => (int) $id);
        if ($assigned->contains((int) $user->id)) {
            return true;
        }

        return $enquiry->enquiryTasks()
            ->where(function ($query) use ($user) {
                $query->where('assigned_user_id', $user->id)
                    ->orWhere('assigned_to', $user->id)
                    ->orWhereHas('assignedUsers', fn ($users) => $users->where('users.id', $user->id));
            })->exists();
    }

    public function canReadAccount(User $user, ProjectEnquiry $enquiry): bool
    {
        return $user->can(Permissions::FINANCE_COSTS_READ)
            || ($user->can(Permissions::PROJECT_COSTS_READ_ASSIGNED) && $this->isAssigned($user, $enquiry));
    }

}
