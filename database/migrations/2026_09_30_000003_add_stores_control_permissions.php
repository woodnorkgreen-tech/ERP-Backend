<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Report 69: Stores Control Foundation permissions.
 * Replaces hardcoded role checks with granular permissions:
 * - stores.board.manage: Board lifecycle transitions, reservations, fulfilment
 * - stores.receipt.inspect: Goods receipt inspection workflow
 * - stores.movement.reverse: Non-destructive stock movement reversal
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'stores.board.manage',
        'stores.receipt.inspect',
        'stores.movement.reverse',
    ];

    private const GRANTS = [
        'Super Admin' => [
            'stores.board.manage',
            'stores.receipt.inspect',
            'stores.movement.reverse',
        ],
        'Admin' => [
            'stores.board.manage',
            'stores.receipt.inspect',
            'stores.movement.reverse',
        ],
        'Manager' => [
            'stores.board.manage',
            'stores.receipt.inspect',
            'stores.movement.reverse',
        ],
        'Stores' => [
            'stores.board.manage',
            'stores.receipt.inspect',
            'stores.movement.reverse',
        ],
        'Production' => [
            'stores.board.manage',
        ],
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (self::GRANTS as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                echo "WARNING: '{$roleName}' role not found — stores control permissions not granted to it.\n";

                continue;
            }
            $role->givePermissionTo($permissions);
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::where('name', $name)->where('guard_name', 'web')->first()?->delete();
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
