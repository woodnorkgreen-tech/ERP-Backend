<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\Finance\Models\FinanceSetting;
use App\Modules\ProcurementStores\Models\PurchaseOrder;

/**
 * Whether a purchase order still needs a person to approve it.
 *
 * A KES 500 stapler and a KES 2,000,000 truss order took the identical route:
 * `approve()` is a role check with no notion of value, and the requisition and
 * the order each carry their own approval — so buying one thing asked the same
 * organisation the same question twice.
 *
 * What this removes is the SECOND question, and only when the first one already
 * answered it. An order that came from an approved requisition, for no more
 * money than that requisition was approved for, is not an unexamined purchase:
 * somebody with authority has already agreed to this spend on these lines. Re-
 * approving it is ceremony, and ceremony is what makes people approve without
 * reading.
 *
 * It is NOT a blanket "small purchases skip approval" rule. An order with no
 * requisition behind it, or one that grew after the requisition was signed,
 * always goes to a human however small it is.
 *
 * ## The limit is a cap, not a switch
 *
 * This class used to do nothing at all unless Finance set and signed off
 * `purchase_order_auto_approval_limit`, which made "one approval per purchase"
 * conditional on a number nobody had entered — so in practice every purchase
 * was still approved twice.
 *
 * The rule that actually protects the money is *cover*: an approved requisition
 * for at least this much. That rule now applies on its own. The limit stays as
 * an OPTIONAL ceiling on top of it: set and sign one off and orders above it go
 * back to a person even when a requisition covers them. Left unset, cover alone
 * decides. Read through {@see FinanceSetting::approvedValue()}, so an unsigned
 * value still caps nothing — an unapproved threshold must never quietly tighten
 * or loosen anything.
 */
class PurchaseApprovalPolicy
{
    public const LIMIT_KEY = 'purchase_order_auto_approval_limit';

    /** The signed-off ceiling, or null when Finance has not set or approved one. */
    public function autoApprovalLimit(?string $on = null): ?string
    {
        $limit = FinanceSetting::approvedValue(self::LIMIT_KEY, null, $on);

        return is_numeric($limit) && bccomp((string) $limit, '0', 2) > 0
            ? (string) $limit
            : null;
    }

    /**
     * May this order approve itself, and if not, why not.
     *
     * The reason is returned rather than logged because it belongs on the order:
     * "went to Ruth because it is 12,000 over its requisition" is the sort of
     * thing somebody asks about a week later.
     *
     * @return array{auto:bool, reason:string}
     */
    public function evaluate(PurchaseOrder $order): array
    {
        $limit = $this->autoApprovalLimit();
        $total = (string) ($order->total_amount ?? '0');

        if (bccomp($total, '0.00', 2) <= 0) {
            return ['auto' => false, 'reason' => 'An order with no value is not a purchase anyone can agree to.'];
        }

        // The optional ceiling, checked before cover so that the reason a large
        // order went to a person names the ceiling rather than the requisition.
        if ($limit !== null && bccomp($total, $limit, 2) > 0) {
            return [
                'auto' => false,
                'reason' => sprintf(
                    'This order is %s, above the %s that may approve itself.',
                    number_format((float) $total, 2),
                    number_format((float) $limit, 2),
                ),
            ];
        }

        $requisition = $order->requisition;

        if (! $requisition) {
            return [
                'auto' => false,
                'reason' => 'This order was raised without a requisition, so no one has approved the need for it yet.',
            ];
        }

        if ($requisition->status !== 'approved' || ! $requisition->approved_at) {
            return [
                'auto' => false,
                'reason' => 'The requisition behind this order has not been approved.',
            ];
        }

        // The requisition is the approval, so the order may not quietly outgrow
        // it. Equal is fine — prices are carried across unchanged; more is a new
        // decision and goes back to a person.
        if (bccomp($total, (string) ($requisition->total_amount ?? '0'), 2) > 0) {
            return [
                'auto' => false,
                'reason' => sprintf(
                    'This order is %s against a requisition approved at %s, so the extra needs approving.',
                    number_format((float) $total, 2),
                    number_format((float) $requisition->total_amount, 2),
                ),
            ];
        }

        return [
            'auto' => true,
            'reason' => $limit === null
                ? sprintf(
                    'Approved automatically: covered by requisition %s, approved on %s.',
                    $requisition->requisition_number,
                    $requisition->approved_at->toDateString(),
                )
                : sprintf(
                    'Approved automatically: within the %s limit and covered by requisition %s, approved on %s.',
                    number_format((float) $limit, 2),
                    $requisition->requisition_number,
                    $requisition->approved_at->toDateString(),
                ),
        ];
    }
}
