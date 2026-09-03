<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cost collector permissions.
 *
 * Reporting a cost is granted widely — anyone who can spend on a project needs
 * it, and a control that stops people recording what they spent produces missing
 * data rather than compliance.
 *
 * Verifying and reversing are granted narrowly, to Finance. That split is the
 * separation of duties the brief requires: the person who reports a cost must
 * not be the person who approves it, and the service enforces that even when one
 * user happens to hold both permissions.
 */
return new class extends Migration
{
    private const REPORTERS = [
        'Super Admin', 'Admin', 'Project Officer', 'Projects', 'Production',
        'Logistics', 'Logistics Officer', 'Procurement', 'Stores', 'Technical',
    ];

    private const VERIFIERS = [
        'Super Admin', 'Admin', 'Finance', 'Finance Manager', 'Accountant',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $create = Permission::findOrCreate(Permissions::FINANCE_COSTS_CREATE, 'web');
        $read = Permission::findOrCreate(Permissions::FINANCE_COSTS_READ, 'web');
        $verify = Permission::findOrCreate(Permissions::FINANCE_COSTS_VERIFY, 'web');
        $reverse = Permission::findOrCreate(Permissions::FINANCE_COSTS_REVERSE, 'web');

        Role::query()->whereIn('name', self::REPORTERS)->get()
            ->each(fn (Role $role) => $role->givePermissionTo([$create, $read]));

        Role::query()->whereIn('name', self::VERIFIERS)->get()
            ->each(fn (Role $role) => $role->givePermissionTo([$create, $read, $verify, $reverse]));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->whereIn('name', [
            Permissions::FINANCE_COSTS_CREATE,
            Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_COSTS_VERIFY,
            Permissions::FINANCE_COSTS_REVERSE,
        ])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
