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
