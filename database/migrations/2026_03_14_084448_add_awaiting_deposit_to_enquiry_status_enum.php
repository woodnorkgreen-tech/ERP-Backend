<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE project_enquiries MODIFY COLUMN status ENUM(
            'client_registered',
            'enquiry_logged',
            'site_survey_completed',
            'design_completed',
            'design_approved',
            'materials_specified',
            'budget_created',
            'quote_prepared',
            'quote_approved',
            'awaiting_deposit',
            'converted_to_project',
            'planning',
            'in_progress',
            'completed',
            'cancelled'
        ) NOT NULL DEFAULT 'enquiry_logged'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE project_enquiries MODIFY COLUMN status ENUM(
            'client_registered',
            'enquiry_logged',
            'site_survey_completed',
            'design_completed',
            'design_approved',
            'materials_specified',
            'budget_created',
            'quote_prepared',
            'quote_approved',
            'converted_to_project',
            'planning',
            'in_progress',
            'completed',
            'cancelled'
        ) NOT NULL DEFAULT 'enquiry_logged'");
    }
};
