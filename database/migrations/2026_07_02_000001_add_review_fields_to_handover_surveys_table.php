<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handover_surveys', function (Blueprint $table) {
            // CS Lead review decision
            $table->enum('review_status', ['pending', 'approved_positive', 'ncr_triggered'])
                  ->default('pending')
                  ->after('captured_by');
            $table->text('review_notes')->nullable()->after('review_status');
            $table->foreignId('reviewed_by')->nullable()->after('review_notes')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->index('review_status');
        });
    }

    public function down(): void
    {
        Schema::table('handover_surveys', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex(['review_status']);
            $table->dropColumn(['review_status', 'review_notes', 'reviewed_by', 'reviewed_at']);
        });
    }
};
