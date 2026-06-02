<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the existing foreign key
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        // Re-add it referencing the correct table
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->foreign('project_id')
                  ->references('id')
                  ->on('project_enquiries')
                  ->onDelete('SET NULL');
        });
    }

    public function down(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->foreign('project_id')
                  ->references('id')
                  ->on('projects')
                  ->onDelete('SET NULL');
        });
    }
};