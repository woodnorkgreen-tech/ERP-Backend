<?php

namespace App\Modules\Finance\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * VAT treatments and withholding-tax categories.
 *
 * DRAFT PENDING ACCOUNTANT SIGN-OFF. Only rates the WNG brief states explicitly
 * are seeded — resident professional/management/training fees at 5%, resident
 * contractual payments at 3%, and the standard VAT rate the brief's own worked
 * example implies (KES 11,600 inclusive of KES 1,600 on a KES 10,000 net).
 * Non-resident WHT categories are deliberately absent rather than guessed.
 *
 * `effective_from` is set to 2020-01-01 so that no historical transaction falls
 * outside a rate window and lookups cannot fail on old data. That is an
 * engineering floor, NOT a claim about when the rate took legal effect — the
 * real dates need confirming, and correcting one means inserting a row with the
 * right date, never editing this one.
 */
class FinanceTaxSeeder extends Seeder
{
    private const FLOOR = '2020-01-01';

    /**
     * The GL account each tax posts to, by chart code.
     *
     * Only the recoverable treatments carry one. Non-recoverable, exempt and
     * out-of-scope VAT is genuinely part of what the project cost, so it stays
     * inside `net_amount` and posts to the expense account with everything else
     * — a null here is that statement, not a missing configuration.
     */
    private const VAT_INPUT_ACCOUNT = '1330';     // Input VAT Recoverable
    private const WHT_PAYABLE_ACCOUNT = '2120';   // Withholding Tax Payable

    /** [code, name, rate, recoverable, requires_etims, claim_window_months, gl_code] */
    private const VAT_TREATMENTS = [
        ['STD16-REC',    'Standard rated 16% – input recoverable',     16.000, true,  true,  6,    self::VAT_INPUT_ACCOUNT],
        ['STD16-NONREC', 'Standard rated 16% – input NOT recoverable',  16.000, false, true,  null, null],
        ['ZERO',         'Zero rated',                                   0.000, true,  true,  6,    self::VAT_INPUT_ACCOUNT],
        ['EXEMPT',       'Exempt',                                       0.000, false, false, null, null],
        // Brief §8: emoluments, imports, interest and airline passenger ticketing
        // need their own code so they are never treated as missing-document
        // exceptions in the eTIMS gap report.
        ['OOS',          'Out of scope / eTIMS excluded',                0.000, false, false, null, null],
    ];

    /** [code, name, rate, residency, threshold, aggregate_monthly, gl_code] */
    private const WHT_CATEGORIES = [
        ['PROF-RES',     'Resident professional / management / training fees', 5.000, 'resident', null, true,  self::WHT_PAYABLE_ACCOUNT],
        ['CONTRACT-RES', 'Resident contractual payments',                      3.000, 'resident', null, true,  self::WHT_PAYABLE_ACCOUNT],
        ['NONE',         'Not subject to withholding tax',                     0.000, 'resident', null, false, null],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $now = now();

            // Resolved once by chart code rather than held as ids: the chart is
            // reseeded independently and its primary keys are not stable, but
            // the codes are what the expense catalogue itself references.
            $accounts = DB::table('chart_of_accounts')
                ->whereIn('code', [self::VAT_INPUT_ACCOUNT, self::WHT_PAYABLE_ACCOUNT])
                ->pluck('id', 'code');

            foreach (self::VAT_TREATMENTS as [$code, $name, $rate, $recoverable, $etims, $window, $glCode]) {
                DB::table('vat_treatments')->updateOrInsert(
                    ['code' => $code, 'effective_from' => self::FLOOR],
                    [
                        'name' => $name,
                        'rate_percent' => $rate,
                        'is_recoverable' => $recoverable,
                        'requires_etims' => $etims,
                        'claim_window_months' => $window,
                        'gl_account_id' => $glCode ? $accounts->get($glCode) : null,
                        'effective_to' => null,
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }

            foreach (self::WHT_CATEGORIES as [$code, $name, $rate, $residency, $threshold, $monthly, $glCode]) {
                DB::table('wht_categories')->updateOrInsert(
                    ['code' => $code, 'effective_from' => self::FLOOR],
                    [
                        'name' => $name,
                        'rate_percent' => $rate,
                        'residency' => $residency,
                        'threshold_amount' => $threshold,
                        'aggregate_monthly' => $monthly,
                        'gl_account_id' => $glCode ? $accounts->get($glCode) : null,
                        'effective_to' => null,
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }
        });
    }
}
