<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Makes `finance.reports.view` grantable, for the new read-only GL endpoints.
 *
 * The permission has been declared in Permissions.php since the finance module
 * was written and enforced by nothing — there was no reporting surface to guard.
 * The journal endpoints are the first, so it finally has a job.
 *
 * Granted to Super Admin ONLY, following the precedent set when the missing
 * petty-cash permissions were added: this changes nobody's effective access, it
 * makes the access grantable from the admin screen. Accounts and Finance staff
 * are the intended day-to-day readers of a trial balance, but widening a role's
 * permissions is a decision for whoever owns those roles, not a side effect of
 * shipping the endpoint they will read through.
 */
return new class extends Migration
{
    private const PERMISSION = 'finance.reports.view';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate(self::PERMISSION, 'web');

        Role::query()->where('name', 'Super Admin')->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The permission itself predates this migration in Permissions.php, so
        // only the grant is withdrawn — deleting it would strip a constant that
        // other code may come to reference.
        Role::query()->where('name', 'Super Admin')->get()
            ->each(fn (Role $role) => $role->revokePermissionTo(self::PERMISSION));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
