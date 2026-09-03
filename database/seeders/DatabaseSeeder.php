<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Modules\MaterialsLibrary\Database\Seeders\WorkstationSeeder;
use App\Modules\MaterialsLibrary\Database\Seeders\MaterialCategorySeeder;
use App\Modules\Design\Database\Seeders\DesignTypeSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed departments first
        $this->call(DepartmentSeeder::class);

        // Then seed employees
        $this->call(EmployeeSeeder::class);

        // Finally seed roles and permissions
        $this->call(RoleAndPermissionSeeder::class);

        // Seed clients
        $this->call(ClientSeeder::class);

        // Seed team categories and types
        $this->call(TeamCategoriesSeeder::class);
        $this->call(TeamTypesSeeder::class);
        $this->call(TeamCategoryTypesSeeder::class);

        // Create department-specific users
        $this->call([
            SuperAdminUserSeeder::class,
            AdminUserSeeder::class,
            HRUserSeeder::class,
            ClientServiceUserSeeder::class,
            DesignerUserSeeder::class,
            ProjectsUserSeeder::class,
        ]);

        // Materials Library — workstations and category taxonomy
        $this->call(WorkstationSeeder::class);
        $this->call(MaterialCategorySeeder::class);

        // Design module — starter Graphic/Structural types
        $this->call(DesignTypeSeeder::class);

        // Finance Module Seeds
        $this->call([
            \App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class,
            \App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder::class,
            \App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class,
            \App\Modules\Finance\Database\Seeders\FinanceTaxSeeder::class,
            \App\Modules\Finance\Database\Seeders\FinanceSettingsSeeder::class,
            \App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder::class,
            \App\Modules\Finance\Database\Seeders\PaymentSourceSeeder::class,
            \App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder::class,
        ]);

        // Seed Universal Task System data
        $this->call(UniversalTaskSeeder::class);

    }
}
