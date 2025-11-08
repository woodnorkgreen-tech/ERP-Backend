<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class ProjectsUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the Projects department
        $projectsDepartment = \App\Modules\HR\Models\Department::where('name', 'Projects')->first();

        // Create Project Manager User
        $projectManagerUser = User::firstOrCreate(
            ['email' => 'pm@company.com'],
            [
                'name' => 'John Project Manager',
                'password' => Hash::make('password'),
                'is_active' => true,
                'department_id' => $projectsDepartment?->id,
                'last_login_at' => now(),
            ]
        );

        // Remove any existing roles and assign Project Manager role
        $projectManagerUser->roles()->detach();
        $projectManagerRole = Role::where('name', 'Project Manager')->first();
        if ($projectManagerRole) {
            $projectManagerUser->assignRole($projectManagerRole);
        }

        // Create Project Officer User
        $projectOfficerUser = User::firstOrCreate(
            ['email' => 'po@company.com'],
            [
                'name' => 'Sarah Project Officer',
                'password' => Hash::make('password'),
                'is_active' => true,
                'department_id' => $projectsDepartment?->id,
                'last_login_at' => now(),
            ]
        );

        // Remove any existing roles and assign Project Officer role
        $projectOfficerUser->roles()->detach();
        $projectOfficerRole = Role::where('name', 'Project Officer')->first();
        if ($projectOfficerRole) {
            $projectOfficerUser->assignRole($projectOfficerRole);
        }

        $this->command->info('Project users created/updated successfully!');
        $this->command->info('Project Manager - Email: pm@company.com, Password: password');
        $this->command->info('Project Officer - Email: po@company.com, Password: password');
    }
}
