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
        Schema::table('hr_actions', function (Blueprint $table) {
            // Update enum if possible, or just change to string for flexibility
            $table->string('status')->default('executed')->change();
            $table->foreignId('approved_by')->nullable()->after('recorded_by')->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable()->after('approved_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_actions', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved_by', 'executed_at']);
            // Reverting to enum might be complex depending on DB, so we leave as string or revert manually
        });
    }
};
