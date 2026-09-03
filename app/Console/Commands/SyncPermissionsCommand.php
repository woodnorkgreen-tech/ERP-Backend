<?php

namespace App\Console\Commands;

use App\Constants\Permissions;
use App\Constants\RolePermissions;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reconcile the database to the role matrix.
 *
 * This replaces the habit of writing another migration every time somebody
 * needs a permission. Fifteen of those accumulated, nine of them granting to
 * role names that had never existed, and every one reported success while
 * doing nothing — because `Role::whereIn(...)->get()->each(...)` simply skips a
 * name it cannot find. This command fails on an unknown role instead.
 *
 * Additive by default: it grants what the matrix declares and leaves anything
 * else a role has alone, so running it can never quietly strip an authority
 * somebody is relying on. `--prune` opts into removing grants the matrix does
 * not declare, which is what makes the matrix genuinely canonical — but that is
 * a deliberate choice, made when you have read the diff.
 */
class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync
        {--dry-run : Report what would change and write nothing}
        {--prune : Also revoke grants the matrix does not declare}';

    protected $description = 'Reconcile roles and permissions to the RolePermissions matrix';

    public function handle(): int
    {
        $matrix = RolePermissions::matrix();

        // A role in the matrix that does not exist is the failure this whole
        // command exists to stop being silent. Report every one, then stop.
        $missing = array_values(array_diff(array_keys($matrix), Role::query()->pluck('name')->all()));
        if ($missing !== []) {
            $this->error('These roles are in the matrix but not in the database: '.implode(', ', $missing));
            $this->line('Create them, or correct the name in '.RolePermissions::class.'.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $prune = (bool) $this->option('prune');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $createdPermissions = $this->syncPermissionRows($dryRun);
        [$granted, $revoked] = $this->syncRoleGrants($matrix, $dryRun, $prune);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->newLine();
        if ($createdPermissions === [] && $granted === [] && $revoked === []) {
            $this->info('Already in sync — nothing to do.');

            return self::SUCCESS;
        }

        $this->info($dryRun ? 'Dry run — nothing was written.' : 'Sync complete.');

        return self::SUCCESS;
    }

    /**
     * Every constant must exist as a row.
     *
     * A constant that was never registered in Permissions::all() could not be
     * created by the seeder, so it existed only where some migration had
     * happened to run — which is how a freshly seeded database and a migrated
     * one ended up disagreeing about what authorities even exist.
     *
     * @return list<string>
     */
    private function syncPermissionRows(bool $dryRun): array
    {
        $existing = Permission::query()->pluck('name')->all();
        $missing = array_values(array_diff(Permissions::all(), $existing));

        if ($missing === []) {
            return [];
        }

        $this->line('Permissions to create ('.count($missing).'):');
        foreach ($missing as $name) {
            $this->line('  + '.$name);
            if (! $dryRun) {
                Permission::findOrCreate($name, 'web');
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, list<string>>  $matrix
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>}
     */
    private function syncRoleGrants(array $matrix, bool $dryRun, bool $prune): array
    {
        $granted = [];
        $revoked = [];

        foreach ($matrix as $roleName => $declared) {
            $role = Role::query()->where('name', $roleName)->firstOrFail();
            $held = $role->permissions->pluck('name')->all();

            $toGrant = array_values(array_diff($declared, $held));
            $toRevoke = $prune ? array_values(array_diff($held, $declared)) : [];

            if ($toGrant !== []) {
                $granted[$roleName] = $toGrant;
                $this->line($roleName.' gains '.count($toGrant).':');
                foreach ($toGrant as $name) {
                    $this->line('  + '.$name);
                }
                if (! $dryRun) {
                    $role->givePermissionTo($toGrant);
                }
            }

            if ($toRevoke !== []) {
                $revoked[$roleName] = $toRevoke;
                $this->warn($roleName.' loses '.count($toRevoke).':');
                foreach ($toRevoke as $name) {
                    $this->line('  - '.$name);
                }
                if (! $dryRun) {
                    $role->revokePermissionTo($toRevoke);
                }
            }
        }

        return [$granted, $revoked];
    }
}
