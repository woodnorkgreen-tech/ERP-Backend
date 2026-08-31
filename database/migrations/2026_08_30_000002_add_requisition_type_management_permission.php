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
        $permission = Permission::findOrCreate(Permissions::FINANCE_REQUISITION_TYPES_MANAGE, 'web');
        Role::query()->whereIn('name', ['Super Admin', 'Finance Manager'])
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', Permissions::FINANCE_REQUISITION_TYPES_MANAGE)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
