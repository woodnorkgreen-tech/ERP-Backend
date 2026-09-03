<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const FINANCE_ROLES = ['Super Admin', 'Admin', 'Finance', 'Finance Manager', 'Accountant'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect([
            Permissions::FINANCE_SPEND_VOUCHERS_READ,
            Permissions::FINANCE_SPEND_VOUCHERS_CREATE,
            Permissions::FINANCE_SPEND_VOUCHERS_APPROVE,
            Permissions::FINANCE_SPEND_VOUCHERS_POST,
        ])->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        Role::query()->whereIn('name', self::FINANCE_ROLES)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->whereIn('name', [
            Permissions::FINANCE_SPEND_VOUCHERS_READ,
            Permissions::FINANCE_SPEND_VOUCHERS_CREATE,
            Permissions::FINANCE_SPEND_VOUCHERS_APPROVE,
            Permissions::FINANCE_SPEND_VOUCHERS_POST,
        ])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
