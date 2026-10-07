<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Report 75: who may do what to a Finance configuration proposal.
 *
 * Seeing, preparing and reviewing a proposal are ordinary Finance work and are
 * granted here to the roles that already do that work.
 *
 * APPROVING and ACTIVATING are accounting authority, and this migration grants
 * them to nobody. Who holds that authority at WNG is WNG's decision; it is
 * assigned to a role deliberately, in Admin > Roles, and not inherited from
 * being an administrator. Super Admin does not receive them either: the
 * governance service checks the permission itself and ignores the Super Admin
 * bypass, because technical access is not accounting authority.
 */
return new class extends Migration
{
    private const WORK = [
        'finance.config.view' => ['Super Admin', 'Admin', 'Manager', 'Accounts', 'Costing'],
        // Super Admin holds every non-authority permission by construction
        // (RolePermissions::matrix), so it is granted here too rather than left to
        // differ until the next permissions:sync. It can prepare and review. It
        // cannot approve or activate.
        'finance.config.propose' => ['Super Admin', 'Accounts', 'Costing'],
        'finance.config.review' => ['Super Admin', 'Accounts'],
    ];

    private const AUTHORITY = [
        'finance.config.approve_operational',
        'finance.config.approve_accounting',
        'finance.config.approve_management',
        'finance.config.activate',
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach ([...array_keys(self::WORK), ...self::AUTHORITY] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (self::WORK as $permission => $roles) {
            foreach ($roles as $roleName) {
                $role = Role::where('name', $roleName)->first();
                if (! $role) {
                    echo "WARNING: '{$roleName}' role not found — {$permission} not granted to it.\n";

                    continue;
                }
                $role->givePermissionTo($permission);
            }
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach ([...array_keys(self::WORK), ...self::AUTHORITY] as $name) {
            Permission::where('name', $name)->where('guard_name', 'web')->delete();
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
