<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Budget additions were retired: unplanned spend is now captured through
 * "Record a project cost" on the cost account, which posts it as an unbudgeted
 * actual line instead of a change request awaiting Finance approval. The
 * permissions that guarded the old approve/reject workflow guard nothing now,
 * so they are revoked rather than left assigned to roles.
 *
 * The `budget_additions` table itself is deliberately left in place — it holds
 * historical records, and dropping it would destroy them.
 */
return new class extends Migration
{
    private const RETIRED = [
        'project.budget_additions.create',
        'finance.budget_additions.read',
        'finance.budget_additions.approve',
        'finance.budget_additions.reject',
        'finance.budget_additions.reverse',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->whereIn('name', self::RETIRED)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::RETIRED as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
