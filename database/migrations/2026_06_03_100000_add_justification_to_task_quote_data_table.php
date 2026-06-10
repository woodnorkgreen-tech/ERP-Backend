<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_quote_data', function (Blueprint $table) {
            // Margin governance: stores the reason when a quote is saved below the 30% threshold
            $table->text('justification')->nullable()->after('viewer_settings');
            // Full audit trail: JSON array of { timestamp, userName, reason, margin }
            $table->json('justification_history')->nullable()->after('justification');
        });
    }

    public function down(): void
    {
        Schema::table('task_quote_data', function (Blueprint $table) {
            $table->dropColumn(['justification', 'justification_history']);
        });
    }
};
