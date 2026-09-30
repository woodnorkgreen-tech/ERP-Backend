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

    // W6: Project Costing confirmed-subset permissions.

    /** W6-2: View portfolio-level margin summary (batched, opt-in). */
    public function canViewPortfolio(User $user): bool
    {
        return $user->can(Permissions::FINANCE_COSTS_PORTFOLIO);
    }

    /** W6-3: Split a verified cost line across multiple projects. */
    public function canAllocate(User $user): bool
    {
        return $user->can(Permissions::FINANCE_COSTS_ALLOCATE);
    }

    /** W6-4: Reclassify a cost to another project via reversing pair. */
    public function canTransfer(User $user): bool
    {
        return $user->can(Permissions::FINANCE_COSTS_TRANSFER);
    }

    /** W6-5: Initiate project financial closure. */
    public function canClose(User $user): bool
    {
        return $user->can(Permissions::FINANCE_COSTS_CLOSE);
    }

    /**
     * W6-6: Override a closure to record a late cost.
     *
     * This permission is created in the DB but assigned to no role by default.
     * WNG must explicitly grant it to a named person per late-cost event.
     */
    public function canReopen(User $user): bool
    {
        return $user->can(Permissions::FINANCE_COSTS_REOPEN);
    }

    // W7: Labour Cost confirmed-subset permissions.

    /** W7: View project labour actuals and budget-vs-actual breakdown. */
    public function canViewLabour(User $user, ProjectEnquiry $enquiry): bool
    {
        return $user->can(Permissions::FINANCE_LABOUR_VIEW)
            || ($user->can(Permissions::PROJECT_COSTS_READ_ASSIGNED) && $this->isAssigned($user, $enquiry));
    }

    /** W7-4: Record actual labour usage (Recorder role — operational lead). */
    public function canRecordLabour(User $user, ProjectEnquiry $enquiry): bool
    {
        return $user->can(Permissions::FINANCE_LABOUR_RECORD)
            && ($user->can(Permissions::FINANCE_LABOUR_FINANCE_VERIFY) || $this->isAssigned($user, $enquiry));
    }

    /** W7-4: Project Officer verifies operational labour attribution. */
    public function canPoVerifyLabour(User $user, ProjectEnquiry $enquiry): bool
    {
        return $user->can(Permissions::FINANCE_LABOUR_PO_VERIFY)
            && ($user->can(Permissions::FINANCE_LABOUR_FINANCE_VERIFY) || $this->isAssigned($user, $enquiry));
    }

    /** W7-5: Finance verifies monetary labour cost → posts analytical CostLine. */
    public function canFinanceVerifyLabour(User $user): bool
    {
        return $user->can(Permissions::FINANCE_LABOUR_FINANCE_VERIFY);
    }

    /** W7-13: Finance corrects / reclassifies a verified labour actual. */
    public function canCorrectLabour(User $user): bool
    {
        return $user->can(Permissions::FINANCE_LABOUR_CORRECT);
    }

    /**
     * W7: correct and resubmit a returned labour actual. This is the recorder's
     * step, so it is the recording capability on this project.
     */
    public function canResubmitLabour(User $user, ProjectEnquiry $enquiry): bool
    {
        return $this->canRecordLabour($user, $enquiry);
    }

}
