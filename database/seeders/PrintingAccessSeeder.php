<?php

namespace Database\Seeders;

use App\Constants\Permissions;
use App\Constants\RolePermissions;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Add Printing access to an existing ERP without modifying grants on other roles. */
class PrintingAccessSeeder extends Seeder
{
    public function run(): void
    {
        Department::firstOrCreate(
            ['name' => 'Printing'],
            ['description' => 'Printing operations, print jobs, and material usage'],
        );

        $grants = RolePermissions::matrix()['Printing'];
        foreach ($grants as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role = Role::firstOrCreate(
            ['name' => 'Printing', 'guard_name' => 'web'],
            ['description' => RolePermissions::DESCRIPTIONS['Printing']],
        );
        $role->description = RolePermissions::DESCRIPTIONS['Printing'];
        $role->save();

        // An early local Printing role carried only Design permissions. Correct
        // that exact legacy set without pruning any administrator's later grants.
        $legacy = [
            Permissions::CREATIVES_VIEW,
            Permissions::CREATIVES_DESIGN_CREATE,
            Permissions::CREATIVES_DESIGN_UPDATE,
            Permissions::CREATIVES_DESIGN_APPROVE,
            Permissions::CREATIVES_MATERIALS_MANAGE,
        ];
        if ($role->permissions->pluck('name')->sort()->values()->all() === collect($legacy)->sort()->values()->all()) {
            $role->revokePermissionTo($legacy);
        }
        $role->givePermissionTo($grants);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
