<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'finance.costs.portfolio',
            'finance.costs.allocate',
            'finance.costs.transfer',
            'finance.costs.close',
            // Created but deliberately NOT assigned to any role — WNG must grant
            // this to a specific person per late-cost exception event (W6-6).
            'finance.costs.reopen',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $accounts = Role::where('name', 'Accounts')->first();
        if (! $accounts) {
            echo "WARNING: 'Accounts' role not found — W6 permissions not assigned to any role.\n";
            return;
        }

        // REOPEN is deliberately excluded: it must not be a permanent grant.
        $accounts->givePermissionTo([
            'finance.costs.portfolio',
            'finance.costs.allocate',
            'finance.costs.transfer',
            'finance.costs.close',
        ]);
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $names = [
            'finance.costs.portfolio',
            'finance.costs.allocate',
            'finance.costs.transfer',
            'finance.costs.close',
            'finance.costs.reopen',
        ];

        foreach ($names as $name) {
            Permission::findByName($name, 'web')?->delete();
        }
    }
};
