<?php

namespace App\Modules\Projects\Support;

use App\Models\ProjectEnquiry;

/**
 * The project-billing register's slices, and the one definition of each of them
 * (Report 59).
 *
 * A tab's count and the rows behind it are the same question asked twice, so
 * they are answered from the same predicate here. They used to be written out
 * twice — once as a count in `EnquiryController::receivablesSummary`, once as a
 * `Array.prototype.filter` in the browser — which is how a tab came to promise
 * twelve projects and show none of them once the list was paged.
 *
 * Every predicate reads `FinanceService::getPaymentProgress()`, so a project's
 * membership never depends on SQL that cannot see quote waivers, approval
 * snapshots or verified-receipt state.
 */
final class ReceivablesTabs
{
    public const ACTION = 'action';
    public const PARTIAL = 'partial';
    public const MOBILIZED = 'mobilized';
    public const RELEASED = 'released';
    public const SHORTFALL = 'shortfall';
    public const SETTLED = 'settled';
    public const ALL = 'all';

    /** Slices the register offers, in the order it offers them. */
    public const SLICES = [
        self::ACTION,
        self::PARTIAL,
        self::MOBILIZED,
        self::RELEASED,
        self::SHORTFALL,
        self::SETTLED,
    ];

    public static function known(string $tab): bool
    {
        return $tab === self::ALL || in_array($tab, self::SLICES, true);
    }

    /**
     * Does this project belong in this slice?
     *
     * @param  array<string, mixed>  $progress  FinanceService::getPaymentProgress()
     */
    public static function matches(string $tab, ProjectEnquiry $enquiry, array $progress): bool
    {
        $quote = (float) $progress['total_quote'];
        $paid = (float) $progress['total_paid'];
        $remaining = (float) $progress['remaining'];
        $released = ! empty($progress['finance_released']);

        return match ($tab) {
            // A missing billing basis, or money still owed before production starts.
            self::ACTION => ! $progress['has_approved_quote']
                || ((float) $progress['amount_required_for_threshold'] > 0 && ! $released),
            self::PARTIAL => $paid > 0 && $remaining > 0,
            // Deposit met, production not yet started, balance still to collect.
            self::MOBILIZED => ! $released
                && (float) $progress['percentage'] >= (float) $progress['threshold_percentage']
                && $remaining > 0,
            self::RELEASED => $released,
            // Released on an override, or after receipt reversal, and now short.
            self::SHORTFALL => ! empty($progress['is_post_release_breach']),
            self::SETTLED => $remaining <= 0 && $quote > 0,
            default => true,
        };
    }
}
