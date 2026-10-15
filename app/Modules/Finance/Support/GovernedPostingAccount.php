<?php

namespace App\Modules\Finance\Support;

use App\Modules\Finance\Governance\GovernanceRuntime;
use App\Modules\Finance\Models\ChartOfAccount;
use InvalidArgumentException;

/**
 * Accounts that only an approved decision can supply (Report 76A Part 2).
 *
 * Every other posting function has a reference-chart code to fall back on.
 * These do not, on purpose: where a purchase-price difference, a write-off or
 * stock used by the company itself is charged is WNG's accounting decision, and
 * no chart profile or reference number is allowed to make it instead. Each is a
 * governed item in Finance Setup (proposed → approved → active). Until one is
 * active, anything that needs it is refused with ACCOUNTING CONFIGURATION
 * REQUIRED — it is never sent to Inventory, Accounts Payable, work in progress
 * or a miscellaneous expense.
 */
final class GovernedPostingAccount
{
    public const PURCHASE_PRICE_VARIANCE = 'purchase_price_variance';
    public const STOCK_INTERNAL_CONSUMPTION = 'stock_internal_consumption';
    public const STOCK_DAMAGE_LOSS = 'stock_damage_loss';
    public const STOCK_WASTE_LOSS = 'stock_waste_loss';
    // Report 76A-3: the two counterparts of stock that changes without a
    // purchase. They used to resolve through the reference chart (6800, 3900)
    // when nothing was approved; a new posting may no longer do that.
    public const STOCK_INVENTORY_ADJUSTMENT = 'stock_inventory_adjustment';
    public const STOCK_OPENING_BALANCE = 'stock_opening_balance';
    // Report 76A-3: received value that will never be invoiced, written off the
    // receipt accrual when an order line is closed out after reconciliation.
    public const RECEIPT_ACCRUAL_CLOSEOUT = 'receipt_accrual_closeout';

    /**
     * function => [title, the question Finance answers, what it affects]
     */
    public const FUNCTIONS = [
        self::PURCHASE_PRICE_VARIANCE => [
            'Purchase price variance',
            'Where should the difference go when a supplier invoices more or less than the value of the goods received?',
            'Supplier invoices whose net value differs from the receipt value they cover. The difference is posted here instead of staying in stock, the job or the supplier balance.',
        ],
        self::STOCK_INTERNAL_CONSUMPTION => [
            'Stock used by the company (not a project)',
            'Which expense should stock issued for the company\'s own use be charged to?',
            'Stores issues with no project: workshop, office and general consumption. Never charged to a job.',
        ],
        self::STOCK_DAMAGE_LOSS => [
            'Damaged stock',
            'Which account should stock written off as damaged be charged to?',
            'Stores write-offs recorded as damage. Not a project cost.',
        ],
        self::STOCK_WASTE_LOSS => [
            'Stock waste and scrap',
            'Which account should stock written off as waste or scrap be charged to?',
            'Stores write-offs recorded as waste. Not a project cost.',
        ],
        self::STOCK_INVENTORY_ADJUSTMENT => [
            'Inventory adjustments',
            'Which account takes the difference when an approved stock count, stock found or a stock correction changes what Stores holds?',
            'Approved stock counts, roll counts, stock found and stock corrections. Inventory moves; this account takes the other side.',
        ],
        self::STOCK_OPENING_BALANCE => [
            'Opening stock',
            'Which account is the other side of stock WNG already held when the books opened?',
            'Opening inventory counts and opening-balance stock receipts.',
            'equity', 'credit',
        ],
        self::RECEIPT_ACCRUAL_CLOSEOUT => [
            'Received, never invoiced (close-out)',
            'Where should received value go when Finance confirms the supplier will never invoice it?',
            'Order lines closed out after reconciliation: the uninvoiced receipt accrual is released here, never left standing and never netted into stock or a job.',
        ],
    ];

    /** [category, normal balance] an approved account for this function is expected to have. */
    public static function expected(string $function): array
    {
        return [self::FUNCTIONS[$function][3] ?? 'expense', self::FUNCTIONS[$function][4] ?? 'debit'];
    }

    public static function item(string $function): string
    {
        return 'posting.'.$function;
    }

    /** The approved, active account code, or null when nobody has decided. */
    public static function code(string $function, ?string $on = null): ?string
    {
        $code = GovernanceRuntime::instance()->value(self::item($function), $on)['account_code'] ?? null;

        return filled($code) ? (string) $code : null;
    }

    /**
     * The postable account id, or a refusal that names exactly what is missing.
     *
     * @throws InvalidArgumentException
     */
    public static function require(string $function, string $for): int
    {
        $title = self::FUNCTIONS[$function][0] ?? $function;
        $code = self::code($function);

        if ($code === null) {
            throw new InvalidArgumentException(
                'ACCOUNTING CONFIGURATION REQUIRED — '.strtoupper($title).' ACCOUNT. '
                ."No approved, active account is set for \"{$title}\", so {$for} was not posted. "
                .'Finance decides it in Finance Setup > Accounting Policies; no account is assumed.'
            );
        }

        $id = ChartOfAccount::postable()->where('code', $code)->value('id');
        if (! $id) {
            throw new InvalidArgumentException(
                'ACCOUNTING CONFIGURATION REQUIRED — '.strtoupper($title).' ACCOUNT. '
                ."The approved account {$code} is no longer an active, postable account, so {$for} was not posted."
            );
        }

        return (int) $id;
    }
}
