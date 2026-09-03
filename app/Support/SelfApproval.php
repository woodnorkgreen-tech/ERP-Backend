<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * One answer to "may this person sign off their own submission?".
 *
 * Separation of duties is enforced in about a dozen independent places —
 * petty-cash requisitions, spend vouchers, budget additions, cost verification,
 * client receipts, overtime. Before this existed each of them decided the
 * exception case on its own: PettyCashRequisitionController hard-coded
 * `hasRole('Super Admin')`, CostLinePolicy::verifyOwn hard-coded it again, and
 * the rest simply refused outright — so a Super Admin could not clear a stuck
 * approval without someone editing code.
 *
 * The rule itself is unchanged and still the default: whoever raises a thing
 * does not approve it. This only centralises the documented exception, which is
 * the `self-approve` Gate ability defined in AppServiceProvider — Super Admin
 * through the global bypass, everyone else through the assignable
 * APPROVALS_SELF_APPROVE permission.
 *
 * Lifting the block is not the same as hiding the act: every caller still
 * records that the approval was a self-approval.
 */
final class SelfApproval
{
    /**
     * May the currently authenticated user self-approve?
     */
    public static function allowed(): bool
    {
        return Gate::allows('self-approve');
    }

    /**
     * May this specific user self-approve?
     *
     * Takes an id rather than a User because the services that need this
     * (FinanceService::verifyPayment and friends) are handed an actor id and
     * may run outside an HTTP request, where `Auth::user()` is null.
     */
    public static function allowedFor(int|User|null $user): bool
    {
        $user = $user instanceof User ? $user : ($user === null ? Auth::user() : User::find($user));

        return $user !== null && Gate::forUser($user)->allows('self-approve');
    }

    /**
     * True when this action is the actor signing off their own submission AND
     * they are permitted to. Callers use it to decide whether to demand a
     * written reason and flag the audit entry.
     */
    public static function isPermittedSelfApproval(int|User|null $actor, ?int $ownerId): bool
    {
        if ($ownerId === null) {
            return false;
        }

        $actorId = $actor instanceof User ? $actor->id : ($actor ?? Auth::id());

        return (int) $actorId === (int) $ownerId && self::allowedFor($actor);
    }
}
