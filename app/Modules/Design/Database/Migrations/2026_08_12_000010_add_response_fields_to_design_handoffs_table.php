<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_handoffs', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('payload_snapshot');
            $table->foreignId('responded_by')->nullable()->after('handed_off_by')->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable()->after('responded_by');
            $table->unique(['design_item_id', 'target_module'], 'design_handoffs_item_target_unique');
        });
    }

    public function down(): void
    {
        Schema::table('design_handoffs', function (Blueprint $table) {
            $table->dropUnique('design_handoffs_item_target_unique');
            $table->dropConstrainedForeignId('responded_by');
            $table->dropColumn(['rejection_reason', 'responded_at']);
        });
    }
};
