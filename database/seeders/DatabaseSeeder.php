<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * A working developer database: the real reference data, then the invented rest.
 *
 * Neither half is addressed by name any more. `ReferenceDataSeeder` is the one
 * to run on production; `DemoDataSeeder` refuses to run anywhere else.
 *
 * @see ReferenceDataSeeder
 * @see DemoDataSeeder
 * @see docs/seeding-in-production.md
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);
        $this->call(DemoDataSeeder::class);
    }
}
