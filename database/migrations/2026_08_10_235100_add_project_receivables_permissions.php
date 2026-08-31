<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ALL = [
        Permissions::FINANCE_RECEIVABLES_READ,
        Permissions::FINANCE_RECEIVABLES_RECORD,
        Permissions::FINANCE_RECEIVABLES_CORRECT,
        Permissions::FINANCE_RECEIVABLES_REVERSE,
        Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
        Permissions::FINANCE_RECEIVABLES_RELEASE,
        Permissions::FINANCE_RECEIVABLES_OVERRIDE,
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::ALL)
            ->mapWithKeys(fn (string $name) => [$name => Permission::findOrCreate($name, 'web')]);

        Role::query()->whereIn('name', ['Super Admin', 'Admin', 'Finance', 'Finance Manager'])
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permissions->values()));

        Role::query()->whereIn('name', ['Accounts', 'Accountant'])
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permissions->except([
                Permissions::FINANCE_RECEIVABLES_OVERRIDE,
            ])->values()));

        Role::query()->whereIn('name', ['Project Manager', 'Costing'])
            ->get()->each(fn (Role $role) => $role->givePermissionTo(
                $permissions->only([Permissions::FINANCE_RECEIVABLES_READ])->values()
            ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->whereIn('name', self::ALL)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
