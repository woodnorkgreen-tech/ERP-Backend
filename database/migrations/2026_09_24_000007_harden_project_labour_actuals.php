<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_labour_actuals', function (Blueprint $table) {
            $table->foreignId('budget_id')->nullable()->after('budget_line_id')->constrained('task_budget_data')->nullOnDelete();
            $table->string('rate_resolution_status')->default('resolved')->after('unit_rate')->index();
            $table->json('rate_source')->nullable()->after('rate_resolution_status');
            $table->unique('cost_line_id', 'project_labour_actuals_cost_line_unique');
            $table->unique('reversal_of_id', 'project_labour_actuals_single_correction_unique');
        });
    }

    public function down(): void
    {
        Schema::table('project_labour_actuals', function (Blueprint $table) {
            $table->dropUnique('project_labour_actuals_single_correction_unique');
            $table->dropUnique('project_labour_actuals_cost_line_unique');
            $table->dropColumn(['rate_source', 'rate_resolution_status']);
            $table->dropConstrainedForeignId('budget_id');
        });
    }
};
