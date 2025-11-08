<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class ClientServiceUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Client Service User
        $clientServiceUser = User::firstOrCreate(
            ['email' => 'clientservice@company.com'],
            [
                'name' => 'Client Service Officer',
                'password' => Hash::make('password'),
                'is_active' => true,
                'last_login_at' => now(),
            ]
        );

        // Assign Client Service role
        $clientServiceRole = Role::where('name', 'Client Service')->first();
        if ($clientServiceRole) {
            $clientServiceUser->assignRole($clientServiceRole);
        }

        $this->command->info('Client Service user created/updated successfully!');
        $this->command->info('Email: clientservice@company.com');
        $this->command->info('Password: password');
        $this->command->info('Role: Client Service');
    }
}
