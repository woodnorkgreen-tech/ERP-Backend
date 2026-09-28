<?php

namespace App\Modules\ProcurementStores\Policies;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\Requisition;

/**
 * Who may agree to a purchase.
 *
 * Both controllers carried a private `canApproveOrDelete()` that asked whether
 * the user's role name was one of three hard-coded strings. Two copies of the
 * same rule, invisible to the roles-and-permissions screens, and widening the
 * approver pool past Accounts meant editing PHP. Since approving a requisition
 * is now the *only* approval a purchase gets — see PurchaseApprovalPolicy — the
 * rule is worth holding in one place and granting like every other right.
 *
 * Permissions are authoritative; the three legacy role names stay as a fallback
 * so nobody loses access before `permissions:sync` has run on an environment.
 * That is the same transition shape OvertimePolicy uses.
 *
 * Deliberately NOT one policy class per model: the two rules are the same rule
 * seen at two moments, and splitting them is how they drifted apart before.
 */
class PurchasePolicy
{
    /** The roles that could approve before this became a permission. */
    private const LEGACY_APPROVERS = ['Super Admin', 'Admin', 'Accounts'];

    /** Super Admin is omnipotent. Mirrors the global Gate::before defensively. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('Super Admin') ? true : null;
    }

    /**
     * May this user approve or reject a purchase requisition — the single
     * decision that now releases a purchase.
     */
    public function approveRequisition(User $user, ?Requisition $requisition = null): bool
    {
        return $this->holdsApproval($user, Permissions::PROCUREMENT_REQUISITIONS_APPROVE);
    }

    /**
     * Deleting a requisition is the same authority as approving one: both
     * decide whether a request lives. Kept as its own ability so that if the
     * business ever separates them, there is a seam to separate them at.
     */
    public function deleteRequisition(User $user, ?Requisition $requisition = null): bool
    {
        return $this->holdsApproval($user, Permissions::PROCUREMENT_REQUISITIONS_APPROVE);
    }

    /**
     * May this user approve a purchase order.
     *
     * Reached only by the exceptions PurchaseApprovalPolicy refuses to pass —
     * an order with no requisition behind it, or one that outgrew the
     * requisition it came from. An order that stayed inside its approved
     * requisition never asks this question of anybody.
     */
    public function approveOrder(User $user, ?PurchaseOrder $order = null): bool
    {
        return $this->holdsApproval($user, Permissions::PROCUREMENT_ORDERS_APPROVE);
    }

    public function deleteOrder(User $user, ?PurchaseOrder $order = null): bool
    {
        return $this->holdsApproval($user, Permissions::PROCUREMENT_ORDERS_APPROVE);
    }

    /**
     * W2-1: the additional sign-off a high-value order needs above the
     * WNG-configured threshold. Deliberately no legacy-role fallback — Super
     * Admin already bypasses everything via before(), and granting this to
     * the same Admin/Accounts roles that already hold ordinary approval
     * would make the senior tier meaningless the day it goes live.
     */
    public function approveOrderSenior(User $user, ?PurchaseOrder $order = null): bool
    {
        return $user->can(Permissions::PROCUREMENT_ORDERS_APPROVE_SENIOR);
    }

    /** W2-6: send a `pending_approval` order back — the reviewer's own decision, same authority as approving it. */
    public function returnOrder(User $user, ?PurchaseOrder $order = null): bool
    {
        return $this->holdsApproval($user, Permissions::PROCUREMENT_ORDERS_APPROVE);
    }

    /** W2-6: correct a returned order and resubmit — the requesting/procurement side's authority, not the reviewer's. */
    public function correctOrder(User $user, ?PurchaseOrder $order = null): bool
    {
        return $user->can(Permissions::PROCUREMENT_ORDERS_CREATE) || $user->hasAnyRole(self::LEGACY_APPROVERS);
    }

    /** W2-4: approve, reject or return an amendment to an already-approved order. */
    public function amendOrder(User $user, ?PurchaseOrder $order = null): bool
    {
        return $user->can(Permissions::PROCUREMENT_ORDERS_AMEND);
    }

    private function holdsApproval(User $user, string $permission): bool
    {
        return $user->can($permission) || $user->hasAnyRole(self::LEGACY_APPROVERS);
    }
}
