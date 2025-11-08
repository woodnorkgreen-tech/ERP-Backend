<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SuperAdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Super Admin User
        $superAdminUser = User::firstOrCreate(
            ['email' => 'superadmin@company.com'],
            [
                'name' => 'Super Administrator',
                'password' => Hash::make('password'),
                'is_active' => true,
                'last_login_at' => now(),
            ]
        );

        // Remove any existing roles and assign only Super Admin role
        $superAdminUser->roles()->detach();
        $superAdminRole = Role::where('name', 'Super Admin')->first();
        if ($superAdminRole) {
            $superAdminUser->assignRole($superAdminRole);
        }

        $this->command->info('Super Admin user created/updated successfully!');
        $this->command->info('Email: superadmin@company.com');
        $this->command->info('Password: password');
        $this->command->info('Role: Super Admin');
    }
}