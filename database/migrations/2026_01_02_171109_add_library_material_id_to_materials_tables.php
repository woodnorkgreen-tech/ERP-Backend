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
        Schema::table('element_materials', function (Blueprint $table) {
            $table->foreignId('library_material_id')->nullable()->after('id')->constrained('library_materials')->nullOnDelete();
        });

        Schema::table('element_template_materials', function (Blueprint $table) {
            $table->foreignId('library_material_id')->nullable()->after('id')->constrained('library_materials')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('element_materials', function (Blueprint $table) {
            $table->dropForeign(['library_material_id']);
            $table->dropColumn('library_material_id');
        });

        Schema::table('element_template_materials', function (Blueprint $table) {
            $table->dropForeign(['library_material_id']);
            $table->dropColumn('library_material_id');
        });
    }
};
