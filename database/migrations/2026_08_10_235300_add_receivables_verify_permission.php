<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate(Permissions::FINANCE_RECEIVABLES_VERIFY, 'web');
        Role::query()->whereIn('name', ['Super Admin', 'Admin', 'Finance', 'Finance Manager', 'Accounts', 'Accountant'])
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->where('name', Permissions::FINANCE_RECEIVABLES_VERIFY)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
