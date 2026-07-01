<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks when a specialist is promoted onto the staff roster. Without this link the
 * promotion is invisible on the registry side, so the same specialist could be promoted
 * repeatedly — minting duplicate employees (email, the only dedupe guard, is nullable).
 *
 * - employee_id : the staff record this specialist became (null = not yet promoted).
 * - promoted_at : when the promotion happened (drives the idempotency guard + audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_labours', function (Blueprint $table) {
            $table->foreignId('employee_id')
                ->nullable()
                ->after('id')
                ->constrained('employees')
                ->nullOnDelete(); // if the staff record is deleted, the registry link clears

            $table->timestamp('promoted_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('technical_labours', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn('promoted_at');
        });
    }
};
