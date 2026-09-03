<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Four petty-cash permissions the frontend has always asked for but which were
 * never seeded.
 *
 * `usePermissions.ts` declares thirteen granular permissions; only nine exist
 * server-side. The missing four are precisely the sensitive ones the composable
 * gave up on and hardcoded to the Super Admin role instead — so the role check
 * was not a policy decision, it was a workaround for a permission that could not
 * be granted.
 *
 * Granted to Super Admin ONLY, which is exactly who can perform these actions
 * today. This migration changes nobody's access; it makes the access grantable,
 * so a Finance Manager can be given it from the admin screen rather than by
 * editing two repositories.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'finance.petty_cash.delete_top_up',
        'finance.petty_cash.recalculate_balance',
        'finance.petty_cash.manage_settings',
        'finance.petty_cash.export_data',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::PERMISSIONS)
            ->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        Role::query()->where('name', 'Super Admin')->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
