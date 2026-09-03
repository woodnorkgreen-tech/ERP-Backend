<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Bring every environment onto the role matrix.
 *
 * The allocation this reconciles to now lives in App\Constants\RolePermissions,
 * and this is deliberately the last one-off grant migration: from here a
 * permission is added by editing the matrix and running `permissions:sync`,
 * which should be part of deploy. Writing another migration per grant is what
 * produced fifteen of them, nine granting to roles that never existed.
 *
 * Idempotent and additive — the command grants what the matrix declares and
 * revokes nothing without --prune, so replaying this can only ever be a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('permissions:sync');
    }

    /**
     * Not reversible by design. The matrix is a statement of who should hold
     * what; there is no previous state worth restoring, and revoking here would
     * strip authorities that other migrations legitimately granted.
     */
    public function down(): void
    {
        //
    }
};
