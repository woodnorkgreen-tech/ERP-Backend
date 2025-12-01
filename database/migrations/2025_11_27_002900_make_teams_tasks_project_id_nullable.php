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
        Schema::table('teams_tasks', function (Blueprint $table) {
            // Make project_id truly nullable
            $table->unsignedBigInteger('project_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams_tasks', function (Blueprint $table) {
            // Revert to non-nullable (requires data migration first)
            // $table->unsignedBigInteger('project_id')->nullable(false)->change();
        });
    }
};
