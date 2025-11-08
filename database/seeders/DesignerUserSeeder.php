<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;

class DesignerUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the Design/Creatives department
        $creativesDepartment = Department::where('name', 'Design/Creatives')->first();

        if (!$creativesDepartment) {
            return; // Department not found, skip creating designer user
        }

        // Create user account for designer (without employee record)
        $designerUser = User::firstOrCreate(
            ['email' => 'designer@company.com'],
            [
                'name' => 'Designer',
                'password' => Hash::make('password'),
                'department_id' => $creativesDepartment->id,
                'is_active' => true,
                'email_verified_at' => now(),
                'last_login_at' => null,
            ]
        );

        // Assign Designer role
        $designerUser->assignRole('Designer');

        $this->command->info('Designer user created/updated successfully!');
        $this->command->info('Email: designer@company.com');
        $this->command->info('Password: password');
        $this->command->info('Role: Designer');
    }
}
