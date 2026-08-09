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
