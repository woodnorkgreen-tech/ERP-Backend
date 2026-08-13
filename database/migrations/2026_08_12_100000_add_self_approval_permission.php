<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the cross-cutting "approve your own submissions" permission.
 *
 * Deliberately granted to NO role here — not even Super Admin, who already
 * passes through the global Gate::before bypass in AppServiceProvider without
 * holding it. Separation of duties is the control that stops one person
 * raising and approving their own payment, so who may set it aside is a
 * decision for whoever administers roles, made deliberately in the UI, rather
 * than something a migration hands out quietly.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(Permissions::APPROVALS_SELF_APPROVE, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', Permissions::APPROVALS_SELF_APPROVE)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
