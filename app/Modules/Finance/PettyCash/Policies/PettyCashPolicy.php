<?php

namespace App\Modules\Finance\PettyCash\Policies;

use App\Constants\Permissions;
use App\Models\User;

/**
 * Single source of truth for *who may act on petty cash* (GL plan BE-0).
 *
 * This replaces eleven hand-typed `hasRole('Super Admin')` checks in
 * PettyCashController and two role-list checks in PettyCashRequisitionController,
 * each re-deriving the same question slightly differently. The July audit found
 * them (BE7); the GL plan makes them Phase 0 because the brief's separation of
 * duties — one person may not request, approve and post the same voucher — is a
 * permissions problem, and it cannot be expressed at all while every sensitive
 * action is gated on one role name.
 *
 * **This widens access on purpose.** The permissions already existed and were
 * already granted: Accounts, Admin and Manager hold
 * `void_disbursement`/`delete_disbursement`/`edit_disbursement` today, and the
 * Super-Admin-only checks meant none of them could use what they had been given.
 * Honouring the grant is the point of the consolidation — Finance staff doing
 * Finance work without a Super Admin in the loop.
 *
 * `clearAll` is the deliberate exception: see that method.
 *
 * Abilities take a nullable model so bulk endpoints can authorize against the
 * class (`$this->authorize('delete', PettyCashDisbursement::class)`) with the
 * same rule as the single-record path.
 */
class PettyCashPolicy
{
    /** Super Admin is omnipotent — short-circuits every ability. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('Super Admin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::FINANCE_PETTY_CASH_VIEW);
    }

    public function viewBalance(User $user): bool
    {
        return $user->can(Permissions::FINANCE_PETTY_CASH_VIEW_BALANCE);
    }

    public function viewReports(User $user): bool
    {
        return $user->can(Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::FINANCE_PETTY_CASH_CREATE);
    }

    public function update(User $user, $disbursement = null): bool
    {
        return $user->can(Permissions::FINANCE_PETTY_CASH_UPDATE);
    }

    public function void(User $user, $disbursement = null): bool
    {
        return $user->can(Permissions::FINANCE_PETTY_CASH_VOID);
    }

    public function delete(User $user, $disbursement = null): bool
    {
        return $user->can(Permissions::FINANCE_PETTY_CASH_DELETE);
    }

    /**
     * Archiving posts no ledger entry — it is a filing decision, not a financial
     * one — so it rides on the same right as editing rather than deletion.
     */
    public function archive(User $user, $disbursement = null): bool
    {
        return $user->can(Permissions::FINANCE_PETTY_CASH_UPDATE);
    }

    public function uploadExcel(User $user): bool
    {
        return $user->can(Permissions::FINANCE_PETTY_CASH_UPLOAD_EXCEL);
    }

    /** The audit trail is what makes the rest of this reviewable. */
    public function viewActivityLogs(User $user): bool
    {
        return $user->can(Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS);
    }

    /**
     * Whether the whole requisition queue is visible, or only your own.
     *
     * A scoping rule rather than a gate: everyone may see their own requisitions,
     * and this decides who additionally sees everybody's. The role list it
     * replaces named a "Finance Manager" role that does not exist in the roles
     * table, so that arm had never matched anyone.
     *
     * The surviving `hasRole(['Admin', 'Accounts'])` arm went the same way: both
     * roles are granted finance.petty_cash.view_reports by the role matrix, so
     * the permission above already admitted them and the role check decided
     * nothing. Naming roles here would only let the two drift apart again.
     */
    public function viewAllRequisitions(User $user): bool
    {
        return $user->can(Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS);
    }

    /**
     * Wipe every disbursement, top-up and balance.
     *
     * Left as Super Admin only, via before(). `finance.petty_cash.admin` is
     * granted to Accounts, so gating this on the permission — as every other
     * ability here does — would hand a full-data-wipe to a second role. That is
     * a change of a different kind from letting Finance void a voucher, and it
     * is not one to make as a side effect of consolidating authorization.
     */
    public function clearAll(User $user): bool
    {
        return false;
    }
}
