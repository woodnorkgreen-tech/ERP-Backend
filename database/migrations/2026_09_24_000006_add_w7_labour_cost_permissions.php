<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * W7 Labour Cost — confirmed-subset permissions.
 *
 * VIEW:           View project labour actuals and budget-vs-actual.
 * RECORD:         Record actual labour usage (Site Captain / Production Lead / authorised lead).
 * PO_VERIFY:      Project Officer operational verification of labour attribution.
 * FINANCE_VERIFY: Finance verification of monetary labour cost → posts analytical CostLine.
 * CORRECT:        Correct / reclassify a verified labour actual (Finance).
 *
 * REOPEN is intentionally NOT redefined here — W6's finance.costs.reopen covers late-cost
 * re-opening of a financially closed project for all cost types including labour.
 */
return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'finance.labour.view',
            'finance.labour.record',
            'finance.labour.po_verify',
            'finance.labour.finance_verify',
            'finance.labour.correct',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Accounts role: Finance verification authority and full access.
        $accounts = Role::where('name', 'Accounts')->first();
        if ($accounts) {
            $accounts->givePermissionTo([
                'finance.labour.view',
                'finance.labour.record',
                'finance.labour.finance_verify',
                'finance.labour.correct',
            ]);
        }

        // Admin / Super Admin: full access.
        foreach (['Admin', 'Super Admin'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo($permissions);
            }
        }

        // Project Manager / Operations Lead: can record and PO-verify.
        foreach (['Project Manager', 'Operations', 'Costing'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo([
                    'finance.labour.view',
                    'finance.labour.record',
                    'finance.labour.po_verify',
                ]);
            }
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $names = [
            'finance.labour.view',
            'finance.labour.record',
            'finance.labour.po_verify',
            'finance.labour.finance_verify',
            'finance.labour.correct',
        ];

        foreach ($names as $name) {
            Permission::findByName($name, 'web')?->delete();
        }
    }
};
