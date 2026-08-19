<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('support.manage', 'web');
        Role::query()->whereIn('name', ['Super Admin', 'Admin'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::query()->where('name', 'support.manage')->where('guard_name', 'web')->first();
        if ($permission) {
            Role::query()->whereIn('name', ['Super Admin', 'Admin'])->get()
                ->each(fn (Role $role) => $role->revokePermissionTo($permission));
            $permission->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
