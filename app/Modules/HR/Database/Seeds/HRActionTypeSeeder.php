<?php

namespace App\Modules\HR\Database\Seeds;

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
                'description' => 'Change in employee position and/or salary due to performance.',
                'fields_schema' => [
                    ['name' => 'position', 'label' => 'New Position', 'type' => 'string', 'required' => true],
                    ['name' => 'department_id', 'label' => 'New Department', 'type' => 'department_id', 'required' => true],
                    ['name' => 'salary', 'label' => 'New Salary (KES)', 'type' => 'number', 'required' => true],
                ],
                'requires_approval' => false,
            ],
            [
                'name' => 'Salary Increment',
                'code' => 'SALARY_INCREMENT',
                'description' => 'Increase in employee base salary.',
                'fields_schema' => [
                    ['name' => 'salary', 'label' => 'New Salary (KES)', 'type' => 'number', 'required' => true],
                ],
                'requires_approval' => false,
            ],
            [
                'name' => 'Transfer',
                'code' => 'TRANSFER',
                'description' => 'Moving an employee to a different department.',
                'fields_schema' => [
                    ['name' => 'department_id', 'label' => 'Target Department', 'type' => 'department_id', 'required' => true],
                ],
                'requires_approval' => false,
            ],
            [
                'name' => 'Disciplinary Warning',
                'code' => 'WARNING',
                'description' => 'Official warning recorded on employee file.',
                'fields_schema' => [
                    ['name' => 'warning_level', 'label' => 'Warning Level', 'type' => 'select', 'options' => ['Verbal', 'Written', 'Final'], 'required' => true],
                ],
                'requires_approval' => false,
            ],
            [
                'name' => 'Termination',
                'code' => 'TERMINATION',
                'description' => 'Ending the employment relationship.',
                'fields_schema' => [
                    ['name' => 'status', 'label' => 'Termination Type', 'type' => 'select', 'options' => ['terminated', 'resigned', 'retired'], 'required' => true],
                ],
                'requires_approval' => false,
            ],
        ];

        foreach ($types as $type) {
            HRActionType::updateOrCreate(['code' => $type['code']], $type);
        }
    }
}
