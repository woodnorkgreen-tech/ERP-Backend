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
    /** [code, name, type, gl_account_code] */
    private const SOURCES = [
        ['PC-MAIN',   'Main Petty Cash Float',  'petty_cash',   '1030'],
        ['BANK-MAIN', 'Bank – Main Account',    'bank',         '1010'],
        ['BANK-ALT',  'Bank – Secondary',       'bank',         '1020'],
        ['MPESA',     'Company M-Pesa',         'mobile_money', '1040'],
        ['CARD',      'Company Card',           'card',         '1010'],
        // Credit purchases: nothing leaves today, the liability is recognised
        // instead. Modelling it as a payment source means an invoice on credit
        // posts through exactly the same path as a cash payment.
        ['AP',        'Supplier Credit (Payable)', 'payable',   '2100'],
    ];

    public function run(): void
    {
        $accounts = DB::table('chart_of_accounts')->pluck('id', 'code');
        $now = now();

        foreach (self::SOURCES as [$code, $name, $type, $accountCode]) {
            DB::table('payment_sources')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'gl_account_id' => $accounts[$accountCode] ?? null,
                    'currency' => 'KES',
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }
}
