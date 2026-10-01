<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->foreignId('reversal_of_log_id')
                ->nullable()
                ->after('original_issue_log_id')
                ->constrained('inventory_logs')
                ->restrictOnDelete();
            $table->unique('reversal_of_log_id', 'inventory_logs_one_reversal_per_movement');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropUnique('inventory_logs_one_reversal_per_movement');
            $table->dropConstrainedForeignId('reversal_of_log_id');
        });
    }
};
