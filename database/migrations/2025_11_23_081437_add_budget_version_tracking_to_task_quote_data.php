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
        Schema::table('task_quote_data', function (Blueprint $table) {
            // Timestamp when budget data was imported to quote
            $table->timestamp('budget_imported_at')->nullable()->after('budget_imported');
            
            // Timestamp of the budget's last update at import time
            $table->timestamp('budget_updated_at')->nullable()->after('budget_imported_at');
            
            // Version identifier for the budget snapshot
            $table->string('budget_version', 50)->nullable()->after('budget_updated_at');
            
            // Store user's custom margin adjustments (JSON: {material_id: percentage})
            $table->json('custom_margins')->nullable()->after('margins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_quote_data', function (Blueprint $table) {
            $table->dropColumn([
                'budget_imported_at',
                'budget_updated_at',
                'budget_version',
                'custom_margins'
            ]);
        });
    }
};
