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
        Schema::table('archival_reports', function (Blueprint $table) {
            // Checklist fields
            $table->boolean('checklist_ppt')->default(false)->after('attachments');
            $table->boolean('checklist_cutlist')->default(false)->after('checklist_ppt');
            $table->boolean('checklist_site_survey_form')->default(false)->after('checklist_cutlist');
            $table->boolean('checklist_project_budget_file')->default(false)->after('checklist_site_survey_form');
            $table->boolean('checklist_material_list')->default(false)->after('checklist_project_budget_file');
            $table->boolean('checklist_qc_checklist')->default(false)->after('checklist_material_list');
            $table->boolean('checklist_setup_setdown')->default(false)->after('checklist_qc_checklist');
            $table->boolean('checklist_client_feedback')->default(false)->after('checklist_setup_setdown');

            // Record Management fields
            $table->string('archive_reference')->nullable()->after('checklist_client_feedback');
            $table->text('archive_location')->nullable()->after('archive_reference');
            $table->string('retention_period')->nullable()->after('archive_location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('archival_reports', function (Blueprint $table) {
            $table->dropColumn([
                'checklist_ppt',
                'checklist_cutlist',
                'checklist_site_survey_form',
                'checklist_project_budget_file',
                'checklist_material_list',
                'checklist_qc_checklist',
                'checklist_setup_setdown',
                'checklist_client_feedback',
                'archive_reference',
                'archive_location',
                'retention_period',
            ]);
        });
    }
};
