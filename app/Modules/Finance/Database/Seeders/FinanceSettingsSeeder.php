<?php

namespace App\Modules\Finance\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Finance policy values, effective-dated.
 *
 * Every one of these is a decision that will change, so none of them is a
 * constant anywhere in the code. Changing one is a NEW ROW with a later
 * `effective_from` — editing in place would silently restate history and, for
 * the petty-cash cap in particular, retrospectively make past breaches
 * compliant.
 *
 * ALL ROWS SEED UNAPPROVED (`approved_by` null). The brief requires accountant
 * sign-off before go-live, and an unapproved threshold should be visible as
 * unapproved rather than quietly enforced as though someone had agreed to it.
 */
class FinanceSettingsSeeder extends Seeder
{
    private const FLOOR = '2020-01-01';

    /** [key, value, label, description] */
    private const SETTINGS = [
        ['petty_cash_max_per_transaction', 20000,
            'Petty cash cap per transaction (KES)',
            'Brief §6.3 recommends KES 20,000; above this a payment must route through procurement / accounts payable. Needs confirming before enforcement.'],

        ['input_vat_claim_window_months', 6,
            'Input VAT claim window (months)',
            'KRA currently states a six-month input-tax claim window. Drives the eTIMS gap and VAT claim schedule reports.'],

        ['margin_warning_percent', 40,
            'Projected margin warning threshold (%)',
            'Brief §9: warn when a project\'s projected margin falls below this.'],

        ['margin_escalation_percent', 35,
            'Final margin escalation threshold (%)',
            'Brief §9: escalate to leadership when final margin falls below this.'],

        ['cost_overrun_alert_percent', 10,
            'Cost overrun alert threshold (%)',
            'Brief §9: alert when actual plus committed cost exceeds budget by more than this.'],

        // Seeded deliberately null. Capex review cannot function without it, and
        // a guessed threshold would be worse than a visibly missing one.
        ['capitalisation_threshold', null,
            'Capitalisation threshold (KES)',
            'Brief §2E / §7 open decision. MUST be set by WNG\'s accountant before capex flagging is switched on.'],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::SETTINGS as [$key, $value, $label, $description]) {
            DB::table('finance_settings')->updateOrInsert(
                ['key' => $key, 'effective_from' => self::FLOOR],
                [
                    'value' => json_encode($value),
                    'label' => $label,
                    'description' => $description,
                    'approved_by' => null,
                    'approved_at' => null,
                    'effective_to' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }
}
