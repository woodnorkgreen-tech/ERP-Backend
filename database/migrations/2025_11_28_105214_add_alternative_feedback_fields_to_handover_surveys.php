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
        Schema::table('handover_surveys', function (Blueprint $table) {
            // Feedback source tracking
            $table->string('feedback_source')->nullable()->after('respondent_info')
                ->comment('Source: survey_link, email, whatsapp, phone_call, in_person, social_media, other');
            
            // Date when feedback was actually received (may differ from submission date)
            $table->timestamp('feedback_received_at')->nullable()->after('feedback_source');
            
            // Evidence and notes
            $table->text('evidence_notes')->nullable()->after('feedback_received_at')
                ->comment('Staff notes about the feedback source and context');
            
            $table->json('evidence_files')->nullable()->after('evidence_notes')
                ->comment('Array of uploaded evidence file paths (screenshots, emails, etc.)');
            
            // Who captured the feedback (for audit trail)
            $table->foreignId('captured_by')->nullable()->after('evidence_files')
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('handover_surveys', function (Blueprint $table) {
            $table->dropForeign(['captured_by']);
            $table->dropColumn([
                'feedback_source',
                'feedback_received_at',
                'evidence_notes',
                'evidence_files',
                'captured_by'
            ]);
        });
    }
};
