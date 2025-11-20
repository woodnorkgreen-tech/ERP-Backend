<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TeamCategoryTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $combinations = [
            // Workshop teams (category_id: 1)
            ['category_id' => 1, 'team_type_id' => 1, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 10],
            ['category_id' => 1, 'team_type_id' => 2, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 8],
            ['category_id' => 1, 'team_type_id' => 3, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 6],
            ['category_id' => 1, 'team_type_id' => 4, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 4],
            ['category_id' => 1, 'team_type_id' => 5, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 5],
            ['category_id' => 1, 'team_type_id' => 6, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 3],
            ['category_id' => 1, 'team_type_id' => 7, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 12],
            ['category_id' => 1, 'team_type_id' => 8, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 12],
            ['category_id' => 1, 'team_type_id' => 9, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 7],

            // Setup teams (category_id: 2)
            ['category_id' => 2, 'team_type_id' => 1, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 10],
            ['category_id' => 2, 'team_type_id' => 2, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 8],
            ['category_id' => 2, 'team_type_id' => 3, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 6],
            ['category_id' => 2, 'team_type_id' => 4, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 4],
            ['category_id' => 2, 'team_type_id' => 5, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 5],
            ['category_id' => 2, 'team_type_id' => 6, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 3],
            ['category_id' => 2, 'team_type_id' => 7, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 12],
            ['category_id' => 2, 'team_type_id' => 8, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 12],
            ['category_id' => 2, 'team_type_id' => 9, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 7],

            // Setdown teams (category_id: 3)
            ['category_id' => 3, 'team_type_id' => 1, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 10],
            ['category_id' => 3, 'team_type_id' => 2, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 8],
            ['category_id' => 3, 'team_type_id' => 3, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 6],
            ['category_id' => 3, 'team_type_id' => 4, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 4],
            ['category_id' => 3, 'team_type_id' => 5, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 5],
            ['category_id' => 3, 'team_type_id' => 6, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 3],
            ['category_id' => 3, 'team_type_id' => 7, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 12],
            ['category_id' => 3, 'team_type_id' => 8, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 12],
            ['category_id' => 3, 'team_type_id' => 9, 'is_available' => true, 'required' => false, 'min_members' => 1, 'max_members' => 7],
        ];

        foreach ($combinations as $combination) {
            \App\Modules\Teams\Models\TeamCategoryType::create($combination);
        }
    }
}
