<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * W2 (Report 60): supplier-bill verification becomes a permission.
 *
 * It was granted by role NAME ('Super Admin', 'Admin', 'Accounts') in
 * BillController and FinanceWorkQueueService. The same population receives the
 * permission here — Admin and Accounts explicitly, Super Admin through the
 * global Gate::before — so nobody gains or loses the power on the day this
 * runs; what changes is that the grant is now visible and assignable.
 *
 * Grants only to roles that exist, and says so when one does not (the
 * phantom-role trap: a missing role used to make a grant silently vanish).
 * RolePermissions::matrix() carries the same grant for freshly built databases.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['finance.payables.read', 'finance.payables.verify'];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (['Admin', 'Accounts'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                echo "WARNING: '{$roleName}' role not found — payables permissions not granted to it.\n";
                continue;
            }
            $role->givePermissionTo(self::PERMISSIONS);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::where('name', $name)->where('guard_name', 'web')->first()?->delete();
        }
    }
};
