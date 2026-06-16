<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE hr_onboarding_cases MODIFY COLUMN status ENUM(
            'pre_onboarding',
            'orientation',
            'department_onboarding',
            'handover',
            'post_onboarding_review',
            'completed',
            'cancelled'
        ) NOT NULL DEFAULT 'pre_onboarding'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE hr_onboarding_cases MODIFY COLUMN status ENUM(
            'pending_start',
            'pre_onboarding',
            'day_1',
            'active_onboarding',
            'hr_approval_pending',
            'hr_handover_completed',
            'post_onboarding_review',
            'completed',
            'cancelled'
        ) NOT NULL DEFAULT 'pending_start'");
    }
};
