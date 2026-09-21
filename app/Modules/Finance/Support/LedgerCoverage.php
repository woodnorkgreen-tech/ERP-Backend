<?php

namespace App\Modules\Finance\Support;

/**
 * What the ledger does and does not contain, in one place.
 *
 * This used to be a separate, near-identical block copied into
 * JournalEntryController's trial balance and LedgerExportService's export —
 * and the copies drifted: both still said revenue and cash movements were
 * excluded after Stage 1 (revenue recognition) and Stage 3 (bank and cash
 * reconciliation) of the general ledger plan made both post. A caveat that is
 * wrong is worse than no caveat, so there is now exactly one description to
 * keep current as later stages land.
 *
 * @see docs/general-ledger-plan.md for the stage-by-stage history this text
 *      tracks.
 */
final class LedgerCoverage
{
    /** @return array<int, string> */
    public static function includes(): array
    {
        return [
            'Verified project and overhead costs',
            'Recoverable input VAT and withholding tax on those costs',
            'Stores inventory movements, goods-received accruals and stock-count adjustments',
            'Payroll accruals and payments explicitly posted from HR',
            'Revenue recognised when an invoice is issued, and client receipts and deposit allocations',
            'Cost of Sales released from Work in Progress when a job is billed',
            'Bank and cash movements posted through reconciliation or recorded directly as a cash movement',
        ];
    }

    /** @return array<int, string> */
    public static function excludes(): array
    {
        return [
            'Payroll not explicitly posted from HR',
            'Purchase price variance between planned and actual material cost',
            'Opening balances, equity, depreciation and year-end adjustments',
        ];
    }

    /**
     * The shape JournalEntryController's trial balance and the new reports
     * return: a statutory-position flag plus the two lists above.
     *
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        return [
            'is_statutory_trial_balance' => false,
            'includes' => self::includes(),
            'excludes' => self::excludes(),
            'note' => 'A cost-and-revenue account summary, not yet a statutory trial balance. Every entry is '
                . 'constructed balanced, so a balanced total confirms the posting logic, not the books. '
                . 'Depreciation, opening balances and equity are not yet recorded here. '
                . 'The statutory position is prepared in WNG\'s external accounting package.',
        ];
    }
}
