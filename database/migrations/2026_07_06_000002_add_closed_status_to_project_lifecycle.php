<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
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
            'closed',
            'cancelled'
        ) NOT NULL DEFAULT 'enquiry_logged'");

        DB::statement("ALTER TABLE projects MODIFY COLUMN status ENUM(
            'planning',
            'in_progress',
            'completed',
            'closed',
            'cancelled'
        ) NOT NULL DEFAULT 'planning'");
    }

    public function down(): void
    {
        DB::statement("UPDATE project_enquiries SET status = 'completed' WHERE status = 'closed'");
        DB::statement("UPDATE projects SET status = 'completed' WHERE status = 'closed'");

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

        DB::statement("ALTER TABLE projects MODIFY COLUMN status ENUM(
            'planning',
            'in_progress',
            'completed',
            'cancelled'
        ) NOT NULL DEFAULT 'planning'");
    }
};
