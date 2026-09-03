<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    // Historical migration. The budget-addition permissions it grants were
    // retired with the feature itself; their names are pinned as literals here
    // so this file stays runnable against a fresh database. A later migration
    // revokes them.
    private const ALL = [
        Permissions::PROJECT_COSTS_READ_ASSIGNED,
        'project.budget_additions.create',
        'finance.budget_additions.read',
        'finance.budget_additions.approve',
        'finance.budget_additions.reject',
        'finance.budget_additions.reverse',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = collect(self::ALL)
            ->mapWithKeys(fn (string $name) => [$name => Permission::findOrCreate($name, 'web')]);

        Role::query()->whereIn('name', ['Project Officer', 'Project Manager'])
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permissions->only([
                Permissions::PROJECT_COSTS_READ_ASSIGNED,
                'project.budget_additions.create',
            ])->values()));

        Role::query()->whereIn('name', ['Accounts', 'Accountant', 'Costing', 'Finance', 'Finance Manager'])
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permissions->values()));

        Role::query()->whereIn('name', ['Super Admin', 'Admin'])
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permissions->values()));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->whereIn('name', self::ALL)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
