<?php

namespace App\Modules\Finance\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Where money physically leaves from.
 *
 * The brief's first principle: petty cash, bank, mobile money and card are
 * PAYMENT METHODS, not expense categories. Each is a row here carrying its own
 * GL account, which is what lets one posting engine handle all of them
 * identically — and what makes opening a second float, or adding a new bank
 * account, a data change.
 *
 * Custodians and float limits are left null: they are per-person operational
 * settings for Finance to fill in, not something a seeder should assert.
 */
class PaymentSourceSeeder extends Seeder
{
    /** [code, name, type, gl_account_code, active] */
    private const SOURCES = [
        ['PC-MAIN',   'Main Petty Cash Float',  'petty_cash',   '1030'],
        ['BANK-MAIN', 'Equity Bank – Operating Account', 'bank', '1010'],
        ['BANK-ALT',  'NCBA Bank – Operations Account',  'bank', '1020'],
        ['MPESA',     'Company M-Pesa',         'mobile_money', '1040'],
        ['CARD',      'Company Card',           'card',         '1010'],
        // Credit purchases: nothing leaves today, the liability is recognised
        // instead. Modelling it as a payment source means an invoice on credit
        // posts through exactly the same path as a cash payment.
        ['AP',        'Supplier Credit (Payable)', 'payable',   '2100'],

        // Named by the old payment-method enum, which offered them as ways of
        // paying rather than accounts. Inactive: whether WNG banks with them is
        // Finance's to confirm from the admin screen.
        ['BANK-STANBIC', 'Stanbic Bank', 'bank', '1010', false],
        ['BANK-KCB',     'KCB Bank',     'bank', '1010', false],
        ['BANK-FAMILY',  'Family Bank',  'bank', '1010', false],
    ];

    /**
     * Identity is re-asserted; availability is not.
     *
     * `is_active` is set only when the row is first created. Finance opens and
     * retires accounts from /finance/setup/paying-accounts, and a seeder that
     * re-asserted the flag would silently undo that on the next deploy — the
     * same reason PettyCashRequisitionTypeSeeder leaves the fields an
     * administrator owns alone.
     */
    public function run(): void
    {
        $accounts = DB::table('chart_of_accounts')->pluck('id', 'code');
        $now = now();

        foreach (self::SOURCES as $source) {
            [$code, $name, $type, $accountCode] = $source;

            $exists = DB::table('payment_sources')->where('code', $code)->exists();

            DB::table('payment_sources')->updateOrInsert(
                ['code' => $code],
                array_merge([
                    'name' => $name,
                    'type' => $type,
                    'gl_account_id' => $accounts[$accountCode] ?? null,
                    'currency' => 'KES',
                    'updated_at' => $now,
                    'created_at' => $now,
                ], $exists ? [] : ['is_active' => $source[4] ?? true]),
            );
        }
    }
}
