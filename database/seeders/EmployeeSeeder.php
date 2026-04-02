<?php

namespace Database\Seeders;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get departments
        $projectsDept = Department::where('name', 'Projects')->first();
        $productionDept = Department::where('name', 'Production')->first();
        $designDept = Department::where('name', 'Design/Creatives')->first();
        $financeDept = Department::where('name', 'Accounts/Finance')->first();
        $logisticsDept = Department::where('name', 'Logistics')->first();
        $clientServiceDept = Department::where('name', 'Client Service')->first();
        $procurementDept = Department::where('name', 'Procurement')->first();

        // Sample employees data
        $employees = [
            // Projects Department
            [
                'employee_id' => 'EMP001',
                'first_name' => 'John',
                'last_name' => 'Manager',
                'email' => 'john.manager@company.com',
                'phone' => '+254700001001',
                'department_id' => $projectsDept?->id,
                'position' => 'Project Manager',
                'hire_date' => '2024-01-15',
                'status' => 'active',
                'salary' => 150000,
                'employment_type' => 'full-time',
                'address' => 'Nairobi, Kenya',
                'emergency_contact' => ['name' => 'Jane Manager', 'phone' => '+254700001002'],
                'performance_rating' => 4.5,
                'last_review_date' => '2025-12-01',
                'id_number' => '12345678',
                'kra_pin' => 'A001234567Z',
                'nssf_id' => 'NSSF001',
                'nhif_id' => 'NHIF001',
                'bank_name' => 'KCB Bank',
                'bank_branch' => 'Nairobi Central',
                'account_number' => '1100223344',
                'is_on_probation' => false
            ],
            [
                'employee_id' => 'EMP002',
                'first_name' => 'Sarah',
                'last_name' => 'Officer',
                'email' => 'sarah.officer@company.com',
                'phone' => '+254700002001',
                'department_id' => $projectsDept?->id,
                'position' => 'Project Officer',
                'hire_date' => '2024-03-01',
                'status' => 'active',
                'salary' => 85000,
                'employment_type' => 'full-time',
                'address' => 'Nairobi, Kenya',
                'emergency_contact' => ['name' => 'Mike Officer', 'phone' => '+254700002002'],
                'performance_rating' => 4.0,
                'last_review_date' => '2025-11-15'
            ],
            // Production Department
            [
                'employee_id' => 'EMP003',
                'first_name' => 'Peter',
                'last_name' => 'Production',
                'email' => 'peter.production@company.com',
                'phone' => '+254700003001',
                'department_id' => $productionDept?->id,
                'position' => 'Production Manager',
                'hire_date' => '2023-06-01',
                'status' => 'active',
                'salary' => 120000,
                'employment_type' => 'full-time',
                'address' => 'Nairobi, Kenya',
                'emergency_contact' => ['name' => 'Grace Production', 'phone' => '+254700003002'],
                'performance_rating' => 4.2,
                'last_review_date' => '2025-10-01'
            ],
            [
                'employee_id' => 'EMP004',
                'first_name' => 'David',
                'last_name' => 'Technician',
                'email' => 'david.technician@company.com',
                'phone' => '+254700004001',
                'department_id' => $productionDept?->id,
                'position' => 'Lead Technician',
                'hire_date' => '2023-09-15',
                'status' => 'active',
                'salary' => 75000,
                'employment_type' => 'full-time',
                'address' => 'Nairobi, Kenya',
                'emergency_contact' => ['name' => 'Mary Technician', 'phone' => '+254700004002'],
                'performance_rating' => 4.0,
                'last_review_date' => '2025-09-15'
            ],
            [
                'employee_id' => 'EMP005',
                'first_name' => 'James',
                'last_name' => 'Tech',
                'email' => 'james.tech@company.com',
                'phone' => '+254700005001',
                'department_id' => $productionDept?->id,
                'position' => 'Technician',
                'hire_date' => '2024-02-01',
                'status' => 'active',
                'salary' => 45000,
                'employment_type' => 'full-time',
                'address' => 'Nairobi, Kenya',
                'emergency_contact' => ['name' => 'Faith Tech', 'phone' => '+254700005002'],
                'performance_rating' => 3.8,
                'last_review_date' => '2025-08-01'
            ],
            [
                'employee_id' => 'EMP006',
                'first_name' => 'Michael',
                'last_name' => 'Junior',
                'email' => 'michael.junior@company.com',
                'phone' => '+254700006001',
                'department_id' => $productionDept?->id,
                'position' => 'Junior Technician',
                'hire_date' => '2025-01-10',
                'status' => 'active',
                'salary' => 35000,
                'employment_type' => 'full-time',
                'address' => 'Kisumu, Kenya',
                'emergency_contact' => ['name' => 'Susan Junior', 'phone' => '+254700006002'],
                'performance_rating' => 3.5,
                'last_review_date' => '2025-12-15'
            ],
            // Design Department
            [
                'employee_id' => 'EMP007',
                'first_name' => 'Emily',
                'last_name' => 'Lead',
                'email' => 'emily.lead@company.com',
                'phone' => '+254700007001',
                'department_id' => $designDept?->id,
                'position' => 'Design Lead',
                'hire_date' => '2023-04-01',
                'status' => 'active',
                'salary' => 110000,
                'employment_type' => 'full-time',
                'address' => 'Nairobi, Kenya',
                'emergency_contact' => ['name' => 'Robert Lead', 'phone' => '+254700007002'],
                'performance_rating' => 4.7,
                'last_review_date' => '2025-11-01'
            ],
            [
                'employee_id' => 'EMP008',
                'first_name' => 'Alex',
                'last_name' => 'Designer',
                'email' => 'alex.designer@company.com',
                'phone' => '+254700008001',
                'department_id' => $designDept?->id,
                'position' => 'Graphic Designer',
                'hire_date' => '2024-05-15',
                'status' => 'active',
                'salary' => 65000,
                'employment_type' => 'full-time',
                'address' => 'Nairobi, Kenya',
                'emergency_contact' => ['name' => 'Lisa Designer', 'phone' => '+254700008002'],
                'performance_rating' => 4.1,
                'last_review_date' => '2025-10-15'
            ],
            // Finance Department
            [
                'employee_id' => 'EMP009',
                'first_name' => 'Robert',
                'last_name' => 'Finance',
                'email' => 'robert.finance@company.com',
                'phone' => '+254700009001',
                'department_id' => $financeDept?->id,
                'position' => 'Finance Manager',
                'hire_date' => '2023-01-01',
                'status' => 'active',
                'salary' => 130000,
                'employment_type' => 'full-time',
                'address' => 'Nairobi, Kenya',
                'emergency_contact' => ['name' => 'Nancy Finance', 'phone' => '+254700009002'],
                'performance_rating' => 4.3,
                'last_review_date' => '2025-12-01'
            ],
            [
                'employee_id' => 'EMP010',
                'first_name' => 'Linda',
                'last_name' => 'Accountant',
                'email' => 'linda.accountant@company.com',
                'phone' => '+254700010001',
                'department_id' => $financeDept?->id,
                'position' => 'Senior Accountant',
                'hire_date' => '2023-07-01',
                'status' => 'active',
                'salary' => 90000,
                'employment_type' => 'full-time',
                'address' => 'Nairobi, Kenya',
                'emergency_contact' => ['name' => 'Tom Accountant', 'phone' => '+254700010002'],
                'performance_rating' => 4.0,
                'last_review_date' => '2025-11-15'
            ],
            // Logistics Department
            [
                'employee_id' => 'EMP011',
                'first_name' => 'George',
                'last_name' => 'Logistics',
                'email' => 'george.logistics@company.com',
                'phone' => '+254700011001',
                'department_id' => $logisticsDept?->id,
                'position' => 'Logistics Manager',
                'hire_date' => '2023-05-01',
                'status' => 'active',
                'salary' => 100000,
                'employment_type' => 'full-time',
                'address' => 'Mombasa, Kenya',
                'emergency_contact' => ['name' => 'Grace Logistics', 'phone' => '+254700011002'],
                'performance_rating' => 4.2,
                'last_review_date' => '2025-10-01'
            ],
            [
                'employee_id' => 'EMP012',
                'first_name' => 'Francis',
                'last_name' => 'Driver',
                'email' => 'francis.driver@company.com',
                'phone' => '+254700012001',
                'department_id' => $logisticsDept?->id,
                'position' => 'Driver',
                'hire_date' => '2024-01-01',
                'status' => 'active',
                'salary' => 40000,
                'employment_type' => 'full-time',
                'address' => 'Mombasa, Kenya',
                'emergency_contact' => ['name' => 'Ann Driver', 'phone' => '+254700012002'],
                'performance_rating' => 3.9,
                'last_review_date' => '2025-09-01'
            ],
            // Client Service Department
            [
                'employee_id' => 'EMP013',
                'first_name' => 'Patricia',
                'last_name' => 'Client',
                'email' => 'patricia.client@company.com',
                'phone' => '+254700013001',
                'department_id' => $clientServiceDept?->id,
                'position' => 'Client Service Manager',
                'hire_date' => '2023-08-01',
                'status' => 'active',
                'salary' => 105000,
                'employment_type' => 'full-time',
                'address' => 'Nairobi, Kenya',
                'emergency_contact' => ['name' => 'Kevin Client', 'phone' => '+254700013002'],
                'performance_rating' => 4.5,
                'last_review_date' => '2025-11-01'
            ],
            [
                'employee_id' => 'EMP014',
                'first_name' => 'Daniel',
                'last_name' => 'Support',
                'email' => 'daniel.support@company.com',
                'phone' => '+254700014001',
                'department_id' => $clientServiceDept?->id,
                'position' => 'Client Support',
                'hire_date' => '2024-04-01',
                'status' => 'active',
                'salary' => 55000,
                'employment_type' => 'full-time',
                'address' => 'Nairobi, Kenya',
                'emergency_contact' => ['name' => 'Rose Support', 'phone' => '+254700014002'],
                'performance_rating' => 3.8,
                'last_review_date' => '2025-10-15'
            ],
            // Procurement Department
            [
                'employee_id' => 'EMP015',
                'first_name' => 'Margaret',
                'last_name' => 'Procure',
                'email' => 'margaret.procure@company.com',
                'phone' => '+254700015001',
                'department_id' => $procurementDept?->id,
                'position' => 'Procurement Manager',
                'hire_date' => '2023-03-01',
                'status' => 'active',
                'salary' => 115000,
                'employment_type' => 'full-time',
                'address' => 'Nairobi, Kenya',
                'emergency_contact' => ['name' => 'Paul Procure', 'phone' => '+254700015002'],
                'performance_rating' => 4.4,
                'last_review_date' => '2025-12-01'
            ],
            [
                'employee_id' => 'EMP016',
                'first_name' => 'Joseph',
                'last_name' => 'Buyer',
                'email' => 'joseph.buyer@company.com',
                'phone' => '+254700016001',
                'department_id' => $procurementDept?->id,
                'position' => 'Procurement Officer',
                'hire_date' => '2024-06-01',
                'status' => 'active',
                'salary' => 60000,
                'employment_type' => 'full-time',
                'address' => 'Nairobi, Kenya',
                'emergency_contact' => ['name' => 'Lucy Buyer', 'phone' => '+254700016002'],
                'performance_rating' => 3.9,
                'last_review_date' => '2025-11-15'
            ],
        ];

        // Get or create Employee role
        $employeeRole = Role::firstOrCreate(
            ['name' => 'Employee', 'guard_name' => 'web'],
            ['description' => 'Regular employee']
        );

        // Create employees and their user accounts
        foreach ($employees as $employeeData) {
            // Create employee
            $employee = Employee::create($employeeData);

            // Create associated user account
            $user = User::updateOrCreate(
                ['email' => $employeeData['email']],
                [
                    'name' => $employeeData['first_name'] . ' ' . $employeeData['last_name'],
                    'email' => $employeeData['email'],
                    'password' => Hash::make('password'),
                    'department_id' => $employeeData['department_id'],
                    'employee_id' => $employee->id,
                    'is_active' => true,
                ]
            );

            // Assign Employee role
            $user->assignRole($employeeRole);

            echo "Created employee: {$employeeData['first_name']} {$employeeData['last_name']} ({$employeeData['position']})\n";
        }

        echo "\nSample employees and technicians seeded successfully!\n";
        echo "Default password for all users: password\n";
    }
}
