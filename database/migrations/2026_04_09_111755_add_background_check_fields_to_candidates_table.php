<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            // Update status enum to include Background Check
            $table->enum('status', ['New', 'Shortlisted', 'Interviewing', 'Background Check', 'Offered', 'Hired', 'Rejected'])->default('New')->change();
            
            // Add background check fields
            $table->enum('background_check_status', ['Pending', 'Initiated', 'Completed', 'Passed', 'Failed'])->nullable();
            $table->longText('background_check_notes')->nullable();
            $table->json('background_check_documents')->nullable();
            $table->timestamp('background_check_completed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropColumn(['background_check_status', 'background_check_notes', 'background_check_documents', 'background_check_completed_at']);
            // Revert status enum
            $table->enum('status', ['New', 'Shortlisted', 'Interviewing', 'Offered', 'Hired', 'Rejected'])->default('New')->change();
        });
    }
};
