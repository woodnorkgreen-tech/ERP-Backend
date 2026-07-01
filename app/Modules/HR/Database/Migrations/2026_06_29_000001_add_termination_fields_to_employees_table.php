<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds structured termination metadata to the employees table.
 *
 * Previously the only evidence of termination was:
 *   • status = 'terminated'
 *   • deleted_at set (soft delete)
 * Now we persist WHY and HOW the employee was separated, and the effective date,
 * so HR can produce accurate offboarding reports, certificate-of-service letters,
 * and final settlement calculations without losing information.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Free-text reason captured at the time of termination.
            $table->text('termination_reason')->nullable()->after('profile_photo_path');

            // Enumerated termination type for reporting/classification.
            $table->string('termination_type', 50)->nullable()->after('termination_reason');

            // Effective date of separation (defaults to the day destroy() was called).
            $table->date('termination_date')->nullable()->after('termination_type');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['termination_reason', 'termination_type', 'termination_date']);
        });
    }
};
