<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\DemoData;
use Illuminate\Database\Seeder;

/**
 * Invented people, jobs and logins, to make an empty developer database usable.
 *
 * Never production. Every seeder below carries the same guard independently,
 * because `--class=` addresses one directly.
 *
 * Worth knowing what this plants, because some of it is already loose in the
 * dev database: sixteen employees with invented salaries, KRA PINs and bank
 * accounts, a handful of clients, a tree of tasks assigned to real users, and
 * eight accounts on `@company.com` whose password is `password` — one of them
 * holding Super Admin.
 */
class DemoDataSeeder extends Seeder
{
    use DemoData;

    public function run(): void
    {
        if (! $this->demoDataIsAllowed()) {
            return;
        }

        $this->call([
            EmployeeSeeder::class,
            ClientSeeder::class,

            SuperAdminUserSeeder::class,
            AdminUserSeeder::class,
            HRUserSeeder::class,
            ClientServiceUserSeeder::class,
            DesignerUserSeeder::class,
            ProjectsUserSeeder::class,

            // Needs departments and users, so it runs after both.
            UniversalTaskSeeder::class,
        ]);
    }
}
