<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_onboarding_cases', function (Blueprint $table) {
            $table->renameColumn('line_manager_id', 'department_lead_id');
        });
    }

    public function down(): void
    {
        Schema::table('hr_onboarding_cases', function (Blueprint $table) {
            $table->renameColumn('department_lead_id', 'line_manager_id');
        });
    }
};
