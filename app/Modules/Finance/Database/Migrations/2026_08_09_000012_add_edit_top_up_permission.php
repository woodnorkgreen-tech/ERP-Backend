<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Editing a top-up needs a permission of its own.
 *
 * `delete_top_up` already exists; there was no counterpart for editing, which is
 * part of why that endpoint ended up with no check at all rather than the wrong
 * one. Both now move the cash balance only for someone explicitly granted it.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate(Permissions::FINANCE_PETTY_CASH_EDIT_TOP_UP, 'web');

        Role::query()->where('name', 'Super Admin')->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->where('name', Permissions::FINANCE_PETTY_CASH_EDIT_TOP_UP)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
