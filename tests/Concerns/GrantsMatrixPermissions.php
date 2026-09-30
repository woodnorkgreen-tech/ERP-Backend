<?php

namespace Tests\Concerns;

use App\Constants\RolePermissions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Give a test role exactly the permissions production gives it
 * (RolePermissions::matrix()). Report 61 moved Stores authorisation from role
 * names to permissions; a fixture that creates a bare "Stores" role must now
 * also give it the Stores role's real grants — as production does — rather
 * than rely on the name.
 */
trait GrantsMatrixPermissions
{
    protected function grantMatrixPermissions(string ...$roles): void
    {
        $matrix = RolePermissions::matrix();
        foreach ($roles as $name) {
            $role = Role::findOrCreate($name, 'web');
            foreach ($matrix[$name] ?? [] as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
            $role->givePermissionTo($matrix[$name] ?? []);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
