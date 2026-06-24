<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A credited overtime entry (or a used compensation) is settled on the tamper-evident
 * ledger and must never be deleted or mutated. To unwind it we post a *compensating*
 * ledger entry (the opposite kind) that chains onto the head like any other.
 *
 * reverses_ledger_id links that compensating entry back to the original it cancels.
 * The UNIQUE constraint makes a double-reversal impossible at the database level —
 * multiple NULLs are allowed, so original (non-reversal) rows are unconstrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreignId('reverses_ledger_id')
                ->nullable()
                ->after('compensation_id')
                ->constrained('ledger_entries')
                ->nullOnDelete();

            $table->unique('reverses_ledger_id');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['reverses_ledger_id']);
            $table->dropConstrainedForeignId('reverses_ledger_id');
        });
    }
};
