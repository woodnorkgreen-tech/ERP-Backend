<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Admin User
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@company.com'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make('password'),
                'is_active' => true,
                'last_login_at' => now(),
            ]
        );

        // Remove any existing roles and assign only Admin role
        $adminUser->roles()->detach();
        $adminRole = Role::where('name', 'Admin')->first();
        if ($adminRole) {
            $adminUser->assignRole($adminRole);
        }

        $this->command->info('Admin user created/updated successfully!');
        $this->command->info('Email: admin@company.com');
        $this->command->info('Password: password');
        $this->command->info('Role: Admin');
    }
}