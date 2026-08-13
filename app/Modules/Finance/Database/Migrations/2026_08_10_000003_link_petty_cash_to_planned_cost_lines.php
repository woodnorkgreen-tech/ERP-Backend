<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_disbursements', function (Blueprint $table) {
            $table->foreignId('planned_cost_line_id')->nullable()
                ->after('budget_category')
                ->constrained('cost_lines')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('petty_cash_disbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('planned_cost_line_id');
        });
    }
};
