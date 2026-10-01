<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Stream F (Report 67): Finance's own payroll permissions, so paying payroll no
 * longer requires HR's full payroll-management permission. Grants follow
 * RolePermissions::matrix(): Admin reads; Accounts reads, pays and classifies.
 * A role that does not exist yet is reported, not silently skipped.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'finance.payroll.read', 'finance.payroll.pay', 'finance.payroll.labour_classification.manage',
    ];

    private const GRANTS = [
        'Admin' => ['finance.payroll.read'],
        'Accounts' => ['finance.payroll.read', 'finance.payroll.pay', 'finance.payroll.labour_classification.manage'],
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (self::GRANTS as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                echo "WARNING: '{$roleName}' role not found — payroll finance permissions not granted to it.\n";
                continue;
            }
            $role->givePermissionTo($permissions);
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
