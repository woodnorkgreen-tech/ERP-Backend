<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class HRUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create HR User
        $hrUser = User::firstOrCreate(
            ['email' => 'hr@company.com'],
            [
                'name' => 'HR Manager',
                'password' => Hash::make('password'),
                'is_active' => true,
                'last_login_at' => now(),
            ]
        );

        // Assign HR role
        $hrRole = Role::where('name', 'HR')->first();
        if ($hrRole) {
            $hrUser->assignRole($hrRole);
        }

        $this->command->info('HR user created/updated successfully!');
        $this->command->info('Email: hr@company.com');
        $this->command->info('Password: password');
        $this->command->info('Role: HR');
    }
}