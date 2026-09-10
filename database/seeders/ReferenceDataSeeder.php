<?php

namespace Database\Seeders;

use App\Modules\Assets\Database\Seeders\AssetCategorySeeder;
use App\Modules\Design\Database\Seeders\DesignTypeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\HR\Database\Seeders\HRActionTypeSeeder;
use App\Modules\HR\Database\Seeders\PayrollSeeder;
use App\Modules\MaterialsLibrary\Database\Seeders\MaterialCategorySeeder;
use App\Modules\MaterialsLibrary\Database\Seeders\WorkstationSeeder;
use Illuminate\Database\Seeder;

/**
 * The lists the application cannot work without, whose authority is this
 * repository rather than whatever a given database happens to hold.
 *
 * Safe on production, and meant to be run there: every seeder below upserts on
 * a natural key and none of them deletes. Statutory payroll rates, the expense
 * catalogue, the accounting calendar and the permission matrix are defined by
 * these files, so a deploy that ships a change to one of them and never
 * re-asserts it has only half shipped.
 *
 * What is NOT here is as much the point. Invented employees, clients, tasks and
 * `@company.com` logins live in DemoDataSeeder, and until this split they came
 * through the same door: `db:seed` meant "a working developer database", so the
 * only safe thing to do on production was to seed nothing and let the reference
 * data drift.
 *
 * Order: departments first, because FinanceDimensionSeeder links each cost
 * centre to an HR department by name and records a null where it finds none.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DepartmentSeeder::class,
            RoleAndPermissionSeeder::class,

            // Nine finance seeders in dependency order; that file owns the
            // order and says why it is what it is.
            FinanceReferenceSeeder::class,

            TeamCategoriesSeeder::class,
            TeamTypesSeeder::class,
            TeamCategoryTypesSeeder::class,

            WorkstationSeeder::class,
            MaterialCategorySeeder::class,
            DesignTypeSeeder::class,
            AssetCategorySeeder::class,

            // Kenya's statutory rates and PAYE bands. Reached by nothing at all
            // until now, so a payroll run computed against whatever happened to
            // be in payroll_variables.
            PayrollSeeder::class,

            // The eight HR action types the career-history screen is built
            // around. There were two of these seeders holding different lists,
            // and nothing called either, so hr_action_types held one row that
            // was in neither.
            HRActionTypeSeeder::class,
        ]);
    }
}
