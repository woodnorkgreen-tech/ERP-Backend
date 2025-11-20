<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TeamTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $teamTypes = [
            [
                'type_key' => 'pasting_team',
                'name' => 'Pasting Team',
                'display_name' => 'Pasting Team',
                'description' => 'Team responsible for pasting and assembly work',
                'default_hourly_rate' => 500.00,
                'max_members_per_team' => 10,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'type_key' => 'technicians',
                'name' => 'Technicians',
                'display_name' => 'Technicians',
                'description' => 'Technical support and maintenance team',
                'default_hourly_rate' => 750.00,
                'max_members_per_team' => 8,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'type_key' => 'painters',
                'name' => 'Painters',
                'display_name' => 'Painters',
                'description' => 'Painting and finishing team',
                'default_hourly_rate' => 600.00,
                'max_members_per_team' => 6,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'type_key' => 'welders',
                'name' => 'Welders',
                'display_name' => 'Welders',
                'description' => 'Welding and metalwork team',
                'default_hourly_rate' => 800.00,
                'max_members_per_team' => 4,
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'type_key' => 'electricians',
                'name' => 'Electricians',
                'display_name' => 'Electricians',
                'description' => 'Electrical installation and maintenance team',
                'default_hourly_rate' => 700.00,
                'max_members_per_team' => 5,
                'is_active' => true,
                'sort_order' => 5,
            ],
            [
                'type_key' => 'ict',
                'name' => 'ICT',
                'display_name' => 'ICT',
                'description' => 'Information and Communications Technology team',
                'default_hourly_rate' => 900.00,
                'max_members_per_team' => 3,
                'is_active' => true,
                'sort_order' => 6,
            ],
            [
                'type_key' => 'loading',
                'name' => 'Loading',
                'display_name' => 'Loading',
                'description' => 'Equipment loading team',
                'default_hourly_rate' => 400.00,
                'max_members_per_team' => 12,
                'is_active' => true,
                'sort_order' => 7,
            ],
            [
                'type_key' => 'offloading',
                'name' => 'Offloading',
                'display_name' => 'Offloading',
                'description' => 'Equipment offloading team',
                'default_hourly_rate' => 400.00,
                'max_members_per_team' => 12,
                'is_active' => true,
                'sort_order' => 8,
            ],
            [
                'type_key' => 'carpenters',
                'name' => 'Carpenters',
                'display_name' => 'Carpenters',
                'description' => 'Woodworking and carpentry team',
                'default_hourly_rate' => 650.00,
                'max_members_per_team' => 7,
                'is_active' => true,
                'sort_order' => 9,
            ],
        ];

        foreach ($teamTypes as $teamType) {
            \App\Modules\Teams\Models\TeamType::create($teamType);
        }
    }
}
