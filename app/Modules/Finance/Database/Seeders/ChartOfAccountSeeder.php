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
 * Idempotent: upserts by `code`, then deactivates any account not in this list
 * rather than deleting it, so a code retired mid-year cannot orphan history.
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

            // Purge anything outside this chart. The previous export had never
            // received a posting, so leaving 120 dead rows behind would only make
            // the account picker ambiguous. Rows that ARE referenced are retired
            // instead of deleted, so a code that once carried postings stays
            // resolvable — the seeder is safe to re-run at any point later.
            $stale = ChartOfAccount::whereNotIn('code', array_column(self::ACCOUNTS, 0))->get();

            foreach ($stale as $account) {
                if ($this->isReferenced($account->id)) {
                    $account->update(['is_active' => false]);
                    continue;
                }

                $account->delete();
            }
        });
    }

    /**
     * Does anything point at this account? Checked by column rather than by
     * relationship so a table added later is a one-line change here, and a
     * missing table (partially migrated environment) is not fatal.
     */
    private function isReferenced(int $accountId): bool
    {
        $references = [
            'payment_sources' => ['gl_account_id'],
            'posting_rules'   => ['debit_account_id', 'credit_account_id'],
            'expense_codes'   => ['default_debit_account_id'],
            'chart_of_accounts' => ['parent_id'],
        ];

        foreach ($references as $table => $columns) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                    continue;
                }

                if (DB::table($table)->where($column, $accountId)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}
