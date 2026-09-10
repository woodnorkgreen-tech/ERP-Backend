<?php

namespace App\Modules\Finance\Database\Seeders;

use App\Modules\Finance\Models\ChartOfAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * WNG chart of accounts.
 *
 * Replaces the previous 120-row export, which used a `COS-001` / `ADM-002`
 * scheme with colon-nested names and had never received a posting anywhere in
 * the application. The expense catalogue references a numeric chart (1030 Petty
 * Cash Float, 1211 Project WIP – Direct Materials, 5100–5800 Cost of Sales), so
 * the chart is rebuilt to match the catalogue rather than the catalogue bent to
 * match a stale export.
 *
 * Structure:
 *   1000–1999  Assets            (1400/1500/1600 are the capex families)
 *   2000–2999  Liabilities
 *   3000–3999  Equity
 *   4000–4999  Revenue
 *   5000–5999  Cost of sales     — mirrors the 121x WIP children one-for-one, so
 *                                  the WIP→COS transfer at revenue recognition
 *                                  (catalogue NE-023) is a straight mapping
 *   6000–6999  Production overhead   (brief §2C)
 *   7000–7999  Operating expenses    (brief §2D)
 *   8000–8999  Other / below-the-line
 *
 * Header accounts aggregate and are not postable; only leaves accept entries.
 *
 * Idempotent, and it touches only the accounts listed here: upserts by `code`
 * and never deletes, deactivates or reparents anything outside the list.
 *
 * It used to end by purging everything outside the list — deleting an account
 * where four named tables did not reference it, deactivating it where they did.
 * That was written to clear a 120-row export that had never received a posting,
 * and it read as safe because the docblock said "deactivates rather than
 * deletes". The code deleted, and `isReferenced()` checked four of the eight
 * columns that point at the chart: `journal_lines.account_id` is not among them,
 * so the ledger was protected only by that column's RESTRICT, while
 * `project_invoice_lines.revenue_account_id`, `vat_treatments.gl_account_id` and
 * `wht_categories.gl_account_id` are ON DELETE SET NULL and would have been
 * emptied in silence.
 *
 * The export it was written for is long gone. What remains is a seeder that
 * assumed it owned the whole chart, which on WNG's production ledger is 123
 * mnemonic accounts it has never heard of. Whether this installation keeps the
 * reference chart at all is now config/finance_accounts.php's answer to give.
 *
 * @see config/finance_accounts.php
 */
class ChartOfAccountSeeder extends Seeder
{
    /** [code, name, category, account_type, normal_balance, parent, postable] */
    private const ACCOUNTS = [
        // ── Assets ────────────────────────────────────────────────────────
        ['1000', 'Current Assets',                     'asset', 'balance_sheet', 'debit',  null,   false],
        ['1010', 'Bank – Main Account',                'asset', 'balance_sheet', 'debit',  '1000', true],
        ['1020', 'Bank – Secondary Account',           'asset', 'balance_sheet', 'debit',  '1000', true],
        ['1030', 'Petty Cash Float',                   'asset', 'balance_sheet', 'debit',  '1000', true],
        ['1040', 'Mobile Money Float',                 'asset', 'balance_sheet', 'debit',  '1000', true],
        ['1100', 'Accounts Receivable',                'asset', 'balance_sheet', 'debit',  '1000', true],
        ['1110', 'Retentions Receivable',              'asset', 'balance_sheet', 'debit',  '1000', true],
        ['1200', 'Raw-material Inventory',             'asset', 'balance_sheet', 'debit',  '1000', true],

        ['1210', 'Project Work in Progress',           'asset', 'balance_sheet', 'debit',  '1000', false],
        ['1211', 'Project WIP – Direct Materials',     'asset', 'balance_sheet', 'debit',  '1210', true],
        ['1212', 'Project WIP – Direct Labour',        'asset', 'balance_sheet', 'debit',  '1210', true],
        ['1213', 'Project WIP – Subcontractors',       'asset', 'balance_sheet', 'debit',  '1210', true],
        ['1214', 'Project WIP – Transport & Logistics','asset', 'balance_sheet', 'debit',  '1210', true],
        ['1215', 'Project WIP – Equipment & Site',     'asset', 'balance_sheet', 'debit',  '1210', true],
        ['1216', 'Project WIP – Project Utilities',    'asset', 'balance_sheet', 'debit',  '1210', true],
        ['1217', 'Project WIP – Project Facilitation', 'asset', 'balance_sheet', 'debit',  '1210', true],
        ['1218', 'Project WIP – Venue & Statutory',    'asset', 'balance_sheet', 'debit',  '1210', true],
        ['1219', 'Project WIP – Rework & Warranty',    'asset', 'balance_sheet', 'debit',  '1210', true],

        ['1300', 'Staff Advances / Imprest',           'asset', 'balance_sheet', 'debit',  '1000', true],
        ['1310', 'Supplier Advances',                  'asset', 'balance_sheet', 'debit',  '1000', true],
        ['1320', 'Refundable Deposits',                'asset', 'balance_sheet', 'debit',  '1000', true],
        ['1330', 'Input VAT Recoverable',              'asset', 'balance_sheet', 'debit',  '1000', true],
        ['1340', 'Prepaid Expenses',                   'asset', 'balance_sheet', 'debit',  '1000', true],

        ['1400', 'Property, Plant & Equipment',        'asset', 'capex', 'debit', null,   false],
        ['1410', 'Machinery & Equipment',              'asset', 'capex', 'debit', '1400', true],
        ['1420', 'Motor Vehicles',                     'asset', 'capex', 'debit', '1400', true],
        ['1430', 'Furniture & Fittings',               'asset', 'capex', 'debit', '1400', true],
        ['1440', 'Computers & IT Equipment',           'asset', 'capex', 'debit', '1400', true],
        ['1450', 'Tools & Equipment',                  'asset', 'capex', 'debit', '1400', true],

        ['1500', 'Reusable Hire Assets',               'asset', 'capex', 'debit', null,   false],
        ['1510', 'Exhibition Counters & Booths',       'asset', 'capex', 'debit', '1500', true],
        ['1520', 'Stage & Flooring Systems',           'asset', 'capex', 'debit', '1500', true],
        ['1530', 'Lightboxes & Display Systems',       'asset', 'capex', 'debit', '1500', true],

        ['1600', 'Leasehold Improvements',             'asset', 'capex', 'debit', null,   true],
        ['1900', 'Accumulated Depreciation',           'asset', 'balance_sheet', 'credit', null, true],

        // ── Liabilities ───────────────────────────────────────────────────
        ['2000', 'Current Liabilities',                'liability', 'balance_sheet', 'credit', null,   false],
        ['2100', 'Accounts Payable',                   'liability', 'balance_sheet', 'credit', '2000', true],
        ['2110', 'Output VAT Payable',                 'liability', 'balance_sheet', 'credit', '2000', true],
        ['2120', 'Withholding Tax Payable',            'liability', 'balance_sheet', 'credit', '2000', true],
        ['2130', 'PAYE Payable',                       'liability', 'balance_sheet', 'credit', '2000', true],
        ['2140', 'Statutory Deductions Payable',       'liability', 'balance_sheet', 'credit', '2000', true],
        ['2150', 'Accrued Expenses',                   'liability', 'balance_sheet', 'credit', '2000', true],
        ['2160', 'Net Payroll Payable',                'liability', 'balance_sheet', 'credit', '2000', true],
        ['2200', 'Client Deposits',                    'liability', 'balance_sheet', 'credit', '2000', true],
        ['2300', 'Loans Payable',                      'liability', 'balance_sheet', 'credit', null,   true],

        // ── Equity ────────────────────────────────────────────────────────
        ['3100', 'Share Capital',                      'equity', 'balance_sheet', 'credit', null, true],
        ['3200', 'Retained Earnings',                  'equity', 'balance_sheet', 'credit', null, true],
        ['3300', 'Dividends & Drawings',               'equity', 'balance_sheet', 'debit',  null, true],
        /*
         * Where a starting position lands.
         *
         * Stock that existed before this ledger did has no purchase behind it to
         * credit — it simply IS, on the day the books open. The other side of
         * that entry is equity, because it is part of what the owners already
         * had. Held in its own account rather than going straight to retained
         * earnings so the opening exercise can be seen, checked and finished:
         * once every opening balance is in, this account nets to zero, and any
         * balance left in it is the part of the starting position nobody has
         * accounted for yet.
         */
        ['3900', 'Opening Balance Equity',             'equity', 'balance_sheet', 'credit', null, true],

        // ── Revenue ───────────────────────────────────────────────────────
        ['4100', 'Project Revenue',                    'revenue', 'revenue', 'credit', null, true],
        ['4200', 'Hire & Rental Revenue',              'revenue', 'revenue', 'credit', null, true],
        ['4900', 'Other Income',                       'revenue', 'revenue', 'credit', null, true],

        // ── Cost of sales — one-for-one with the 121x WIP children ────────
        ['5000', 'Cost of Sales',                      'expense', 'direct_cost', 'debit', null,   false],
        ['5100', 'Cost of Sales – Direct Materials',   'expense', 'direct_cost', 'debit', '5000', true],
        ['5200', 'Cost of Sales – Direct Labour',      'expense', 'direct_cost', 'debit', '5000', true],
        ['5300', 'Cost of Sales – Subcontractors',     'expense', 'direct_cost', 'debit', '5000', true],
        ['5400', 'Cost of Sales – Transport & Logistics','expense','direct_cost','debit', '5000', true],
        ['5500', 'Cost of Sales – Equipment & Site',   'expense', 'direct_cost', 'debit', '5000', true],
        ['5600', 'Cost of Sales – Project Utilities',  'expense', 'direct_cost', 'debit', '5000', true],
        ['5700', 'Cost of Sales – Project Facilitation','expense','direct_cost', 'debit', '5000', true],
        ['5800', 'Cost of Sales – Venue & Statutory',  'expense', 'direct_cost', 'debit', '5000', true],
        ['5900', 'Cost of Sales – Rework & Warranty',  'expense', 'direct_cost', 'debit', '5000', true],

        // ── Production overhead (brief §2C) ───────────────────────────────
        ['6000', 'Production Overhead',                'expense', 'overhead', 'debit', null,   false],
        ['6100', 'Workshop Electricity',               'expense', 'overhead', 'debit', '6000', true],
        ['6110', 'Workshop Rent',                      'expense', 'overhead', 'debit', '6000', true],
        ['6200', 'Machinery Repairs & Maintenance',    'expense', 'overhead', 'debit', '6000', true],
        ['6300', 'Indirect Production Labour',         'expense', 'overhead', 'debit', '6000', true],
        ['6400', 'Small Tools & Workshop Consumables', 'expense', 'overhead', 'debit', '6000', true],
        ['6500', 'Machinery Depreciation',             'expense', 'overhead', 'debit', '6000', true],
        ['6600', 'PPE & Workshop Safety',              'expense', 'overhead', 'debit', '6000', true],
        ['6700', 'Cleaning & Waste Disposal',          'expense', 'overhead', 'debit', '6000', true],
        /*
         * What a physical count found that the records did not.
         *
         * A count that finds less stock than the books claim has discovered a
         * real loss — breakage, theft, mis-issue — and a loss is an expense, not
         * a quiet edit to a quantity. Finding MORE is the same event in reverse
         * and credits this account, which is why one account serves both rather
         * than a separate gain line: over and under counts on the same material
         * in successive months should offset, and splitting them hides that.
         */
        ['6800', 'Inventory Adjustments & Shrinkage',  'expense', 'overhead', 'debit', '6000', true],

        // ── Operating expenses (brief §2D) ────────────────────────────────
        ['7000', 'Operating Expenses',                 'expense', 'opex', 'debit', null,   false],
        ['7100', 'Office Rent & Electricity',          'expense', 'opex', 'debit', '7000', true],
        // Added because the catalogue needed it, not the other way round:
        // the requisition list had no way to name stationery or printer
        // consumables, and OperationalExpenseCodes said so in as many words
        // rather than fold them into Office Rent & Electricity.
        ['7150', 'Office Supplies & Stationery',       'expense', 'opex', 'debit', '7000', true],
        ['7200', 'Administration Airtime & Internet',  'expense', 'opex', 'debit', '7000', true],
        ['7300', 'Professional Fees – Finance, HR, IT, Legal', 'expense', 'opex', 'debit', '7000', true],
        ['7400', 'Office Transport',                   'expense', 'opex', 'debit', '7000', true],
        ['7500', 'Recruitment & Training',             'expense', 'opex', 'debit', '7000', true],
        ['7550', 'Salaries & Wages',                   'expense', 'opex', 'debit', '7000', true],
        ['7600', 'Staff Welfare',                      'expense', 'opex', 'debit', '7000', true],
        ['7700', 'Marketing & Business Development',   'expense', 'opex', 'debit', '7000', true],
        ['7800', 'Bank & Mobile-money Charges',        'expense', 'opex', 'debit', '7000', true],
        ['7900', 'Insurance & Licences',               'expense', 'opex', 'debit', '7000', true],

        // ── Other / below the line ────────────────────────────────────────
        ['8100', 'Interest Expense',                   'expense', 'opex', 'debit', null, true],
        ['8200', 'Foreign Exchange Gain / Loss',       'expense', 'opex', 'debit', null, true],
        ['8300', 'Depreciation – Non-production',      'expense', 'opex', 'debit', null, true],

        // Catalogue §7: money paid that can never be supported by a valid tax
        // invoice lands here, after senior approval — never hidden in a general
        // expense line, and separately reportable as non-deductible.
        ['8900', 'Unsupported / Non-deductible Expense', 'expense', 'opex', 'debit', null, true],
    ];

    public function run(): void
    {
        if (! config('finance_accounts.seed_reference_chart')) {
            $this->command?->warn(
                'Chart of accounts left alone: this installation keeps its own chart '
                .'(finance_accounts.seed_reference_chart is off).'
            );

            return;
        }

        DB::transaction(function () {
            foreach (self::ACCOUNTS as [$code, $name, $category, $type, $balance, , $postable]) {
                ChartOfAccount::updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'category' => $category,
                        'account_type' => $type,
                        'normal_balance' => $balance,
                        'is_postable' => $postable,
                        'is_active' => true,
                    ]
                );
            }

            // Second pass: parents exist by now.
            $ids = ChartOfAccount::pluck('id', 'code');
            foreach (self::ACCOUNTS as [$code, , , , , $parent]) {
                ChartOfAccount::where('code', $code)
                    ->update(['parent_id' => $parent ? $ids[$parent] ?? null : null]);
            }
        });
    }
}
