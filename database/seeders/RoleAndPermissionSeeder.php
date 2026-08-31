<?php

namespace Database\Seeders;

use App\Constants\Permissions;
use App\Constants\RolePermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create every role and permission, then allocate from the matrix.
 *
 * This used to hold fifteen hand-written per-role lists, which is why a seeded
 * database and a migrated one could disagree about who could do what: a
 * permission added by a one-off migration was never added here, so it existed
 * on a live system and nowhere on a fresh one. The lists now come from
 * RolePermissions, the same source `permissions:sync` reconciles against, so
 * the two cannot drift apart again.
 *
 * @see \App\Constants\RolePermissions
 * @see \App\Console\Commands\SyncPermissionsCommand
 */
class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Every constant becomes a row first. Granting a permission that has no
        // row throws, so this ordering is what lets the matrix name anything the
        // registry declares without depending on some migration having run.
        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (RolePermissions::matrix() as $name => $permissions) {
            $role = Role::firstOrCreate(
                ['name' => $name],
                ['description' => RolePermissions::DESCRIPTIONS[$name] ?? null],
            );

            // Additive, like permissions:sync — seeding an existing database
            // must not silently strip an authority somebody depends on.
            $role->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
