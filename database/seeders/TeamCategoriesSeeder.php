<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TeamCategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'category_key' => 'workshop',
                'name' => 'Workshop Teams',
                'display_name' => 'Workshop Teams',
                'color_code' => '#3B82F6',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'category_key' => 'setup',
                'name' => 'Setup Teams',
                'display_name' => 'Setup Teams',
                'color_code' => '#10B981',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'category_key' => 'setdown',
                'name' => 'Setdown Teams',
                'display_name' => 'Setdown Teams',
                'color_code' => '#F59E0B',
                'sort_order' => 3,
                'is_active' => true,
            ],
        ];

        foreach ($categories as $category) {
            \App\Modules\Teams\Models\TeamCategory::create($category);
        }
    }
}
