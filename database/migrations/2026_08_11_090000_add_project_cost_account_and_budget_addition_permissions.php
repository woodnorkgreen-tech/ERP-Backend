<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ALL = [
        Permissions::PROJECT_COSTS_READ_ASSIGNED,
        Permissions::PROJECT_BUDGET_ADDITIONS_CREATE,
        Permissions::FINANCE_BUDGET_ADDITIONS_READ,
        Permissions::FINANCE_BUDGET_ADDITIONS_APPROVE,
        Permissions::FINANCE_BUDGET_ADDITIONS_REJECT,
        Permissions::FINANCE_BUDGET_ADDITIONS_REVERSE,
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = collect(self::ALL)
            ->mapWithKeys(fn (string $name) => [$name => Permission::findOrCreate($name, 'web')]);

        Role::query()->whereIn('name', ['Project Officer', 'Project Manager'])
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permissions->only([
                Permissions::PROJECT_COSTS_READ_ASSIGNED,
                Permissions::PROJECT_BUDGET_ADDITIONS_CREATE,
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
