<?php

namespace App\Modules\Finance\CostCollector\Policies;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;

/**
 * Authorisation for the cost ledger.
 *
 * A policy rather than hand-typed role strings: the July Finance audit found ten
 * scattered `hasRole('Super Admin')` checks across the petty-cash controllers,
 * which meant a Finance Manager could not be granted rights without a code
 * change on two repositories.
 */
class CostLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::FINANCE_COSTS_READ);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::FINANCE_COSTS_CREATE);
    }

    /**
     * Holding the permission is necessary but not sufficient — the service also
     * refuses to let anyone verify a cost they reported themselves. Kept there
     * rather than here because it is a property of the record, not of the user.
     */
    public function verify(User $user, CostLine $line): bool
    {
        return $user->can(Permissions::FINANCE_COSTS_VERIFY);
    }

    /**
     * Who may break separation of duties and verify their own cost.
     *
     * Separation of duties is the rule; this is the documented exception for a
     * one-person finance function or an out-of-hours close, mirroring the
     * emergency self-approval the petty-cash requisition flow already allows.
     * It is not a silent bypass — the service additionally demands a written
     * reason and records the override against the line.
     *
     * Who counts as the exception is no longer decided here. It is the shared
     * `self-approve` ability (App\Support\SelfApproval), so this module agrees
     * with petty cash, spend vouchers, budget additions and receipts instead of
     * keeping its own hard-coded role string — which is what let Super Admin be
     * the only possible answer, with no way to grant it to a Finance Manager.
     */
    public function verifyOwn(User $user, CostLine $line): bool
    {
        return \App\Support\SelfApproval::allowedFor($user);
    }

    public function reverse(User $user, CostLine $line): bool
    {
        return $user->can(Permissions::FINANCE_COSTS_REVERSE);
    }

    /** Answering a query on your own submission needs no special right. */
    public function resubmit(User $user, CostLine $line): bool
    {
        return $line->submitted_by_user_id === $user->id
            || $user->can(Permissions::FINANCE_COSTS_VERIFY);
    }
}
