<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->foreignId('return_log_id')->nullable()->after('return_received_by')->constrained('inventory_logs')->nullOnDelete();
            $table->string('quarantine_review_status', 30)->nullable()->after('return_log_id')->index();
            $table->decimal('accepted_recoverable_value', 12, 2)->nullable()->after('quarantine_review_status');
            $table->text('quarantine_review_notes')->nullable()->after('accepted_recoverable_value');
            $table->timestamp('quarantine_reviewed_at')->nullable()->after('quarantine_review_notes');
            $table->foreignId('quarantine_reviewed_by')->nullable()->after('quarantine_reviewed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quarantine_reviewed_by');
            $table->dropColumn(['quarantine_reviewed_at', 'quarantine_review_notes', 'accepted_recoverable_value', 'quarantine_review_status']);
            $table->dropConstrainedForeignId('return_log_id');
        });
    }
};
