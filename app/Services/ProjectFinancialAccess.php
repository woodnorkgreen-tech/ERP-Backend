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

    /**
     * Read access to a project's *receivables* — money in, and the governance
     * trail behind it.
     *
     * Deliberately separate from canReadAccount(). The cost account exposes
     * internal spend and margin; receivables exposes what the client has paid.
     * Accounts, Costing and Project Manager carry finance.receivables.read but
     * not finance.costs.read, so gating the receivables modal on the cost
     * permission locked out exactly the roles the screen is built for, while
     * folding receivables into canReadAccount() would have handed those roles
     * the cost account too.
     */
    public function canReadReceivables(User $user, ProjectEnquiry $enquiry): bool
    {
        return $user->can(Permissions::FINANCE_RECEIVABLES_READ)
            || $this->canReadAccount($user, $enquiry);
    }

}
