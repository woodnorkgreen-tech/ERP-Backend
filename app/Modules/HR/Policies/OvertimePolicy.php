<?php

namespace App\Modules\HR\Policies;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\OTEntry;

/**
 * Single source of truth for *who may act on an overtime entry*. This replaces the role-string
 * checks that were duplicated across OvertimeController (supervisorApprove/hrApprove/reject/...),
 * each of which re-derived "is global HR / is manager / is dept lead" slightly differently.
 *
 * Direction of the consolidation: permissions are authoritative (e.g. OVERTIME_APPROVE_HR), with
 * the legacy HR role names kept as a fallback so nobody loses access during the transition. The
 * hierarchy rules (a manager/lead may act on their own people) live here too, in one place.
 */
class OvertimePolicy
{
    /** Super Admin is omnipotent — short-circuits every ability. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('Super Admin') ? true : null;
    }

    /**
     * Supervisor sign-off. HR may self-approve; everyone else may not approve their own entry,
     * and an entry belonging to a manager/lead must be validated by HR (not a peer).
     */
    public function supervisorApprove(User $user, OTEntry $entry): bool
    {
        // HR tier has full autonomy at the supervisor stage (including their own entry).
        if ($user->hasAnyRole(['HR'])) {
            return true;
        }

        // No self-approval for anyone else.
        if ($this->isOwn($user, $entry)) {
            return false;
        }

        $isGlobal = $this->isGlobalHr($user);

        // A manager's / dept-lead's own overtime must be cleared by HR, never a subordinate/peer.
        if ($this->subjectIsManager($entry)) {
            return $isGlobal;
        }

        return $isGlobal || $this->hasHierarchyOver($user, $entry);
    }

    /**
     * Final HR approval — the step that credits the ledger. Requires the HR-approval permission
     * (or legacy HR role) and may not be performed on one's own entry (Super Admin excepted, via
     * before()), enforcing segregation of duties.
     */
    public function hrApprove(User $user, OTEntry $entry): bool
    {
        if (! $this->canApproveAsHr($user)) {
            return false;
        }

        return ! $this->isOwn($user, $entry);
    }

    /** Reject / re-open: global HR, or the entry owner's direct manager / department lead. */
    public function reject(User $user, OTEntry $entry): bool
    {
        return $this->isGlobalHr($user) || $this->isDirectManagerOrLead($user, $entry);
    }

    public function reopen(User $user, OTEntry $entry): bool
    {
        return $this->reject($user, $entry);
    }

    /** Hard delete is reserved for Super Admin (granted via before(); denied to everyone else). */
    public function delete(User $user, OTEntry $entry): bool
    {
        return false;
    }

    // ── shared predicates ──────────────────────────────────────────────────────────────────

    private function canApproveAsHr(User $user): bool
    {
        return $user->can(Permissions::OVERTIME_APPROVE_HR) || $this->isGlobalHr($user);
    }

    /** The "global HR" tier (Super Admin is handled in before()). */
    private function isGlobalHr(User $user): bool
    {
        return $user->hasAnyRole(['Admin', 'HR']);
    }

    private function isOwn(User $user, OTEntry $entry): bool
    {
        return $entry->employee_id !== null && $entry->employee_id === $user->employee_id;
    }

    /** Does the entry belong to someone who is themselves a manager or department lead? */
    private function subjectIsManager(OTEntry $entry): bool
    {
        if (! $entry->employee_id) {
            return false;
        }

        return Employee::where('manager_id', $entry->employee_id)->exists()
            || Department::where('manager_id', $entry->employee_id)->exists();
    }

    /** Direct manager or department lead of the entry's employee. */
    private function isDirectManagerOrLead(User $user, OTEntry $entry): bool
    {
        if (! $entry->employee) {
            return false;
        }

        return $entry->employee->manager_id === $user->employee_id
            || $entry->employee->department?->manager_id === $user->employee_id;
    }

    /** Manager/lead of the employee, or the Production lead for a technical-labour entry. */
    private function hasHierarchyOver(User $user, OTEntry $entry): bool
    {
        if ($this->isDirectManagerOrLead($user, $entry)) {
            return true;
        }

        if ($entry->technical_labour_id && $user->employee_id) {
            $production = Department::where('name', 'Production')->first();
            return $production && $production->manager_id === $user->employee_id;
        }

        return false;
    }
}
