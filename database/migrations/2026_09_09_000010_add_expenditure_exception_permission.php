<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the "authorize spending beyond an approved project budget" permission.
 *
 * Granted to NO role here, deliberately, exactly as APPROVALS_SELF_APPROVE was.
 * A budget limit that any approver can set aside is not a limit, so who may
 * carry an overrun is a decision for whoever administers roles, made in the UI
 * against a named person, rather than something a migration hands out quietly to
 * everyone who happens to approve requisitions today.
 *
 * Super Admin reaches it through the global Gate::before bypass in
 * AppServiceProvider and never needs it granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(Permissions::FINANCE_EXPENDITURE_EXCEPTION_APPROVE, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', Permissions::FINANCE_EXPENDITURE_EXCEPTION_APPROVE)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
