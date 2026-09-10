<?php

namespace Database\Seeders;

use App\Modules\HR\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            [
                'name' => 'Projects',
                'description' => 'Project management and coordination across all departments',
                'budget' => 0.00,
                'location' => ''
            ],
            [
                'name' => 'Accounts/Finance',
                'description' => 'Financial management, accounting, and budgeting',
                'budget' => 0.00,
                'location' => ''
            ],
            [
                'name' => 'Production',
                'description' => 'Manufacturing, quality control, and production operations',
                'budget' => 0.00,
                'location' => ''
            ],
            [
                'name' => 'Design/Creatives',
                'description' => 'Creative design, branding, and visual communications',
                'budget' => 0.00,
                'location' => ''
            ],
            [
                'name' => 'Procurement',
                'description' => 'Supplier management and purchasing operations',
                'budget' => 0.00,
                'location' => ''
            ],
            [
                'name' => 'Costing',
                'description' => 'Cost analysis, pricing strategy, and financial planning',
                'budget' => 0.00,
                'location' => ''
            ],
            [
                'name' => 'Logistics',
                'description' => 'Transportation, warehousing, and supply chain management',
                'budget' => 0.00,
                'location' => ''
            ],
            [
                'name' => 'Stores',
                'description' => 'Inventory management and stock control',
                'budget' => 0.00,
                'location' => ''
            ],
            [
                'name' => 'Client Service',
                'description' => 'Client acquisition, enquiry management, and marketing',
                'budget' => 0.00,
                'location' => ''
            ],
            [
                'name' => 'Teams',
                'description' => 'Team coordination and management',
                'budget' => 0.00,
                'location' => ''
            ]
        ];

        /*
         * `budget` and `location` are set on the row, not defined by this file.
         * Upserting the whole array put them back to 0.00 and '' on every run,
         * so seeding a live database silently cleared every departmental budget
         * Finance had entered. Only the description is this seeder's to assert;
         * a new department still gets the zero default from the schema.
         */
        foreach ($departments as $department) {
            Department::firstOrCreate(
                ['name' => $department['name']],
                $department
            )->update(['description' => $department['description']]);
        }
    }
}
