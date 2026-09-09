<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may open a paying account.
 *
 * Granted only to roles that exist, and loud about any that do not — a bare
 * Role::whereIn()->get() finds nothing for a misspelled role and grants nothing,
 * leaving authorization half-applied with no signal.
 */
return new class extends Migration
{
    private const ROLES = ['Super Admin', 'Accounts'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE, 'web');

        $missing = [];
        foreach (self::ROLES as $name) {
            $role = Role::where('name', $name)->first();
            if (! $role) {
                $missing[] = $name;
                continue;
            }
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($missing) {
            echo PHP_EOL.'  [warning] '.Permissions::FINANCE_PAYMENT_SOURCES_MANAGE
                .' was not granted to missing role(s): '.implode(', ', $missing).PHP_EOL;
        }
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', Permissions::FINANCE_PAYMENT_SOURCES_MANAGE)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
