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
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->renameColumn('project_start_date', 'set_up_date');
            $table->renameColumn('project_deadline', 'set_down_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->renameColumn('set_up_date', 'project_start_date');
            $table->renameColumn('set_down_date', 'project_deadline');
        });
    }
};