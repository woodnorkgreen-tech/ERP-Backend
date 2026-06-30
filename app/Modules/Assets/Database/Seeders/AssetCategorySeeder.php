<?php

namespace App\Modules\Assets\Database\Seeders;

use App\Modules\Assets\Models\AssetCategory;
use Illuminate\Database\Seeder;

class AssetCategorySeeder extends Seeder
{
    /**
     * Root categories — copied exactly from the data-validation dropdown in
     * the "Category" column of the WNG Asset Register spreadsheet, so the
     * picklist here matches what everyone's used to. Add more any time
     * from the Categories screen — this seeder is just a starting point.
     */
    public function run(): void
    {
        $categories = [
            'Facility' => 'FAC',
            'ICT' => 'ICT',
            'Logistics & Transport' => 'LOG',
            'Printing' => 'PRN',
            'Production' => 'PRO',
            'Tailoring' => 'TLR',
            'Workshop Machinery' => 'WSM',
            'Workshop Tools & Equipment' => 'WTE',
            'Utility Installation' => 'UTL',
            'IT & Digital Devices' => 'ITD',
            'Office Furniture' => 'OFF',
            'Printing Dept.' => 'PRD',
            'Office Printing' => 'OPR',
            'Branding' => 'BRD',
            'Vehicle & Transport Tools' => 'VTT',
        ];

        $index = 0;
        foreach ($categories as $name => $code) {
            $category = AssetCategory::firstOrCreate(
                ['name' => $name, 'parent_id' => null],
                ['code' => $code, 'sort_order' => $index]
            );

            // Backfill the code if this category already existed from a
            // previous run of the seeder before the code column existed.
            if (empty($category->code)) {
                $category->update(['code' => $code]);
            }

            $index++;
        }
    }
}
