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
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->json('selected_workflow_tasks')->nullable()->after('site_survey_skip_reason');
            $table->string('workflow_preset_type')->nullable()->after('selected_workflow_tasks');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->dropColumn(['selected_workflow_tasks', 'workflow_preset_type']);
        });
    }
};
