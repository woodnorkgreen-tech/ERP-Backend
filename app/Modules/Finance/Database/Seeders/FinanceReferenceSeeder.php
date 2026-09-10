<?php

namespace App\Modules\Finance\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * All Finance reference data, in dependency order.
 *
 * Every seeder below is idempotent and keyed on a natural code, so this is safe
 * to re-run on every deploy — which is the point: the reference data is defined
 * by these files, not by whatever happens to be in a given database.
 *
 * Order matters once: payment sources resolve GL account IDs, so the chart has
 * to exist first.
 *
 * ChartOfAccountSeeder is the exception to "every seeder below is idempotent and
 * safe to re-run". It is, now — but only because it asks
 * finance_accounts.seed_reference_chart whose chart this is, and does nothing on
 * an installation that brought its own. Before that gate existed, this
 * docblock's promise was false of its own first child: on WNG's production
 * ledger the chart seeder would have stood 88 numeric accounts beside 123
 * mnemonic ones and then purged what it did not recognise.
 */
class FinanceReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ChartOfAccountSeeder::class,
            FinanceDimensionSeeder::class,
            FinanceTaxSeeder::class,
            PaymentSourceSeeder::class,
            FinanceSettingsSeeder::class,
            AccountingPeriodSeeder::class,
            // Expense codes resolve GL accounts, cost centres, activities and
            // tax treatments that the seeders above create.
            ExpenseCodeSeeder::class,
            // Last: a fund-requisition category IS an expense code, wearing the
            // name a requester would use, so the catalogue has to exist first.
            //
            // This list used to be defined by four migrations instead. That made
            // it the one piece of Finance reference data nothing re-asserted:
            // seeding refreshed the catalogue these categories derive from and
            // left the categories frozen wherever the last migration put them.
            PettyCashRequisitionTypeSeeder::class,
        ]);
    }
}
