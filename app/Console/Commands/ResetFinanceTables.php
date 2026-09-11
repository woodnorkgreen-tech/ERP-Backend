<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetFinanceTables extends Command
{
    protected $signature = 'finance:reset-tables';
    protected $description = 'Truncate finance tables only, keep users/inventory/materials';

    public function handle(): int
    {
        $this->warn('This will DELETE all finance data. Continue?');
        if (! $this->confirm('Proceed?')) return 1;

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Finance tables to truncate (FK-safe order)
        $tables = [
            // Level 1: Leaf children
            'journal_lines',
            'bill_payments',
            'enquiry_payments',
            'petty_cash_requisition_items',
            'petty_cash_ledger_entries',
            'stores_finance_postings',
            'spend_voucher_allocations',

            // Level 2: Parents of Level 1
            'journal_entries',
            'client_receipts',
            'cost_lines',

            // Level 3: Core finance documents
            'spend_vouchers',
            'bills',
            'payments',

            // Level 4: Masters that Level 3 depends on
            'payment_sources',
            'expense_codes',
            'vat_treatments',
            'wht_categories',
            'cost_centres',
            'activities',
            'cost_causes',
            'payee_types',

            // Level 5: Masters that depend on Level 4
            'petty_cash_requisition_types',
            'petty_cash_requisitions',
            'petty_cash_balances',
            'petty_cash_top_ups',
            'petty_cash_activity_logs',
            'petty_cash_offline_batches',
            'petty_cash_offline_rows',

            // Level 6: Root
            'chart_of_accounts',
            'accounting_periods',
            'finance_settings',
            'finance_dimensions',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
                $this->info("Truncated: {$table}");
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Enable reference chart for this run
        config(['finance_accounts.seed_reference_chart' => true]);

        // Re-seed finance reference data
        $this->call('db:seed', [
            '--class' => 'App\\Modules\\Finance\\Database\\Seeders\\FinanceReferenceSeeder',
            '--force' => true,
        ]);

        // Also seed the other reference data (cost centres, activities, etc.)
        $this->call('db:seed', [
            '--class' => 'Database\\Seeders\\ReferenceDataSeeder',
            '--force' => true,
        ]);

        $this->info('Finance tables reset and re-seeded.');
        return 0;
    }
}