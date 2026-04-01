<?php

namespace App\Modules\HR\Database\Seeders;

use App\Modules\HR\Models\HRActionType;
use Illuminate\Database\Seeder;

class HRActionTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'name' => 'Promotion',
                'code' => 'PROMOTION',
                'description' => 'Elevating an employee to a higher position or rank.',
                'fields_schema' => [
                    ['name' => 'position', 'label' => 'New Job Title', 'type' => 'text', 'required' => true],
                    ['name' => 'department_id', 'label' => 'New Department', 'type' => 'select', 'required' => true],
                    ['name' => 'salary', 'label' => 'New Base Salary', 'type' => 'number', 'required' => true],
                ],
                'requires_approval' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Department Transfer',
                'code' => 'TRANSFER',
                'description' => 'Moving an employee to a different department or unit.',
                'fields_schema' => [
                    ['name' => 'department_id', 'label' => 'Target Department', 'type' => 'select', 'required' => true],
                    ['name' => 'position', 'label' => 'New Position (Optional)', 'type' => 'text', 'required' => false],
                ],
                'requires_approval' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Salary Increment',
                'code' => 'SALARY_UPDATE',
                'description' => 'Updating the base salary of an employee.',
                'fields_schema' => [
                    ['name' => 'salary', 'label' => 'New Monthly Salary', 'type' => 'number', 'required' => true],
                ],
                'requires_approval' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Warning Letter',
                'code' => 'WARNING',
                'description' => 'Disciplinary action for performance or conduct issues.',
                'fields_schema' => [
                    ['name' => 'warning_level', 'label' => 'Warning Level', 'type' => 'select', 'options' => ['First', 'Second', 'Final'], 'required' => true],
                    ['name' => 'details', 'label' => 'Incident Details', 'type' => 'textarea', 'required' => true],
                ],
                'requires_approval' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Employment Termination',
                'code' => 'TERMINATION',
                'description' => 'Ending the employment relationship.',
                'fields_schema' => [
                    ['name' => 'status', 'label' => 'Final Status', 'type' => 'text', 'required' => true],
                    ['name' => 'termination_type', 'label' => 'Type', 'type' => 'select', 'options' => ['Resignation', 'Dismissal', 'Redundancy', 'Retirement'], 'required' => true],
                ],
                'requires_approval' => false,
                'is_active' => true,
            ],
        ];

        foreach ($types as $type) {
            HRActionType::updateOrCreate(['code' => $type['code']], $type);
        }
    }
}
