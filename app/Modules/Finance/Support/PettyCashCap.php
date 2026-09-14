<?php

namespace App\Modules\Finance\Support;

use App\Modules\Finance\Models\FinanceSetting;

/**
 * Brief §6.3's petty-cash transaction limit — KES 20,000, seeded since the
 * project began and never read by anything (FinanceSettingsSeeder).
 *
 * Read through `FinanceSetting::approvedValue()`, not `value()`: the seeder's
 * own comment says the figure "needs confirming before enforcement", and a
 * cap nobody has signed off blocking a real payment is worse than no cap at
 * all. Every caller stays a no-op until Finance approves the row from
 * /finance/setup, exactly like the purchase-order auto-approval limit.
 */
class PettyCashCap
{
    /** The approved per-transaction cap, or null when none is confirmed yet. */
    public static function limit(): ?string
    {
        $value = FinanceSetting::approvedValue('petty_cash_max_per_transaction');

        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }

    /** Whether an amount is over the approved cap. Always false while unconfirmed. */
    public static function exceeds(string $amount): bool
    {
        $limit = self::limit();

        return $limit !== null && bccomp($amount, $limit, 2) > 0;
    }

    public static function message(string $amount): string
    {
        return sprintf(
            'This payment (KES %s) is above the approved petty cash limit of KES %s per transaction. '
            . 'Route it through procurement / accounts payable instead.',
            number_format((float) $amount, 2),
            self::limit(),
        );
    }
}
