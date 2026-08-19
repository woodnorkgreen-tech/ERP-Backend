<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ROLE_NAMES = [
        'Super Admin',
        'Admin',
        'Logistics',
        'Logistics Officer',
        'Logistics Manager',
    ];

    private const PERMISSION_NAMES = [
        Permissions::LOGISTICS_VIEW,
        Permissions::LOGISTICS_DELIVERIES_MANAGE,
        Permissions::LOGISTICS_DRIVERS_MANAGE,
        Permissions::LOGISTICS_FLEET_MANAGE,
        Permissions::LOGISTICS_ROUTES_MANAGE,
        Permissions::LOGISTICS_TRACKING_VIEW,
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::PERMISSION_NAMES)
            ->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        Role::query()->whereIn('name', self::ROLE_NAMES)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSION_NAMES)
            ->get();

        Role::query()->whereIn('name', self::ROLE_NAMES)->get()
            ->each(fn (Role $role) => $role->revokePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
