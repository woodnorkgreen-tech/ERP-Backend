<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Put the requisition-type authority on the roles that actually exist.
 *
 * 2026_08_30_000002 granted this to `['Super Admin', 'Finance Manager']`. There
 * is no role by that second name — the whereIn matched one row, `->each()` over
 * the empty remainder did nothing, and the migration reported success. So the
 * intended second grantee got nothing and only a Super Admin could configure
 * what every requester is asked for.
 *
 * RoleAndPermissionSeeder already gives Accounts this permission, so a freshly
 * seeded environment and a migrated one disagreed. This closes that drift for
 * Accounts and adds Costing, which owns the expense codes a type points at
 * through default_expense_code_id.
 *
 * A role named here that does not exist is a mistake in this file, not a
 * condition to absorb quietly — hence the explicit report rather than the
 * silent no-op that caused the problem in the first place.
 */
return new class extends Migration
{
    private const ROLES = ['Super Admin', 'Accounts', 'Costing'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate(Permissions::FINANCE_REQUISITION_TYPES_MANAGE, 'web');

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
            // Loud, but not fatal: a partially-seeded environment should still
            // migrate. What must never happen again is finishing silently.
            echo PHP_EOL.'  [warning] finance.requisition_types.manage was not granted to missing role(s): '
                .implode(', ', $missing).PHP_EOL;
        }
    }

    /**
     * The permission itself is not dropped — 2026_08_30_000002 owns its
     * lifecycle. Rolling this back only undoes the grants it made.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::where('name', Permissions::FINANCE_REQUISITION_TYPES_MANAGE)->first();
        if ($permission) {
            foreach (['Accounts', 'Costing'] as $name) {
                Role::where('name', $name)->first()?->revokePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
