<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = [
            Permissions::MATERIALS_LIBRARY_VIEW,
            Permissions::MATERIALS_LIBRARY_MANAGE,
            Permissions::MATERIALS_LIBRARY_IMPORT,
            Permissions::STORES_VIEW,
            Permissions::STORES_MANAGE,
            Permissions::STORES_REVIEW,
        ];
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->grant('Super Admin', $names);
        $this->grant('Manager', $names);
        $this->grant('Stores', [
            Permissions::MATERIALS_LIBRARY_VIEW,
            Permissions::MATERIALS_LIBRARY_MANAGE,
            Permissions::MATERIALS_LIBRARY_IMPORT,
            Permissions::STORES_VIEW,
            Permissions::STORES_MANAGE,
        ]);
        $this->grant('Procurement', [
            Permissions::MATERIALS_LIBRARY_VIEW,
            Permissions::MATERIALS_LIBRARY_MANAGE,
            Permissions::MATERIALS_LIBRARY_IMPORT,
            Permissions::STORES_VIEW,
        ]);
        $this->grant('Production', [Permissions::MATERIALS_LIBRARY_VIEW, Permissions::STORES_VIEW]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            Permissions::MATERIALS_LIBRARY_VIEW,
            Permissions::MATERIALS_LIBRARY_MANAGE,
            Permissions::MATERIALS_LIBRARY_IMPORT,
            Permissions::STORES_VIEW,
            Permissions::STORES_MANAGE,
            Permissions::STORES_REVIEW,
        ])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function grant(string $roleName, array $permissions): void
    {
        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $role->givePermissionTo($permissions);
        }
    }
};
