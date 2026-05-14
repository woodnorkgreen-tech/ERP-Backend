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
        // Update project_elements table
        Schema::table('project_elements', function (Blueprint $table) {
            $table->string('name', 255)->nullable()->change();
        });

        // Update library_materials table
        Schema::table('library_materials', function (Blueprint $table) {
            $table->string('material_name')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse project_elements table
        Schema::table('project_elements', function (Blueprint $table) {
            $table->string('name', 255)->nullable(false)->change();
        });

        // Reverse library_materials table
        Schema::table('library_materials', function (Blueprint $table) {
            $table->string('material_name')->nullable(false)->change();
        });
    }
};
