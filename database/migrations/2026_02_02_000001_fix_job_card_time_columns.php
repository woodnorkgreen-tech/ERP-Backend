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
        // Change job_cards time columns from datetime to time
        Schema::table('job_cards', function (Blueprint $table) {
            $table->time('clock_in_time')->change();
            $table->time('clock_out_time')->change();
        });

        // Change daily_tasks time columns from datetime to time
        Schema::table('daily_tasks', function (Blueprint $table) {
            $table->time('start_time')->change();
            $table->time('end_time')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert job_cards time columns back to datetime
        Schema::table('job_cards', function (Blueprint $table) {
            $table->dateTime('clock_in_time')->change();
            $table->dateTime('clock_out_time')->change();
        });

        // Revert daily_tasks time columns back to datetime
        Schema::table('daily_tasks', function (Blueprint $table) {
            $table->dateTime('start_time')->change();
            $table->dateTime('end_time')->change();
        });
    }
};