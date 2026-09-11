<?php

namespace Database\Seeders;

use App\Modules\MaterialsLibrary\Database\Seeders\LegacyMaterialCategorySeeder as ModuleLegacyCategorySeeder;
use Illuminate\Database\Seeder;

class LegacyMaterialCategorySeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ModuleLegacyCategorySeeder::class);
    }
}
