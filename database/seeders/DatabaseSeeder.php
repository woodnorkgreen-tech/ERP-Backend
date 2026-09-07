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

        // Finance reference data.
        //
        // One call, not nine. This block used to list the seven finance seeders
        // individually AND then call the aggregate below, which re-ran all seven
        // in a different order — expense codes before payment sources here,
        // after them there. Nothing corrupted, because every one of them upserts
        // on a natural key, but neither list was authoritative: a seeder added
        // to one and not the other would have run in only half the environments.
        //
        // FinanceReferenceSeeder owns the order and documents why it is what it
        // is. Add new finance reference data there.
        $this->call(\App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder::class);

        // Seed Universal Task System data
        $this->call(UniversalTaskSeeder::class);

    }
}
