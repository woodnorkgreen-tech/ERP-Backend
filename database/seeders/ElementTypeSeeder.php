<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ElementType;
use Illuminate\Support\Facades\DB;

class ElementTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing data
        DB::table('element_types')->truncate();

        // Default element types matching frontend
        $elementTypes = [
            ['name' => 'stage', 'display_name' => 'Stage', 'category' => 'structure', 'is_predefined' => true, 'order' => 1],
            ['name' => 'backdrop', 'display_name' => 'Backdrop', 'category' => 'decoration', 'is_predefined' => true, 'order' => 2],
            ['name' => 'skirting', 'display_name' => 'Stage Skirting', 'category' => 'decoration', 'is_predefined' => true, 'order' => 3],
            ['name' => 'entrance-arc', 'display_name' => 'Entrance Arc', 'category' => 'decoration', 'is_predefined' => true, 'order' => 4],
            ['name' => 'dance-floor', 'display_name' => 'Dance Floor', 'category' => 'flooring', 'is_predefined' => true, 'order' => 5],
            ['name' => 'walkway', 'display_name' => 'Walkway', 'category' => 'flooring', 'is_predefined' => true, 'order' => 6],
            ['name' => 'lighting', 'display_name' => 'Lighting Setup', 'category' => 'technical', 'is_predefined' => true, 'order' => 7],
            ['name' => 'sound', 'display_name' => 'Sound System', 'category' => 'technical', 'is_predefined' => true, 'order' => 8],
            ['name' => 'seating', 'display_name' => 'Seating Arrangement', 'category' => 'furniture', 'is_predefined' => true, 'order' => 9],
            ['name' => 'tables', 'display_name' => 'Tables', 'category' => 'furniture', 'is_predefined' => true, 'order' => 10],
            ['name' => 'decor', 'display_name' => 'Decorative Elements', 'category' => 'decoration', 'is_predefined' => true, 'order' => 11],
            ['name' => 'signage', 'display_name' => 'Signage & Branding', 'category' => 'branding', 'is_predefined' => true, 'order' => 12],
        ];

        foreach ($elementTypes as $type) {
            ElementType::create($type);
        }

        $this->command->info('Element types seeded successfully!');
    }
}
