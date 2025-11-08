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
            ]
        ];

        foreach ($departments as $department) {
            Department::create($department);
        }
    }
}
