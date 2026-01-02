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
            $table->decimal('unit_cost', 15, 2)->nullable()->after('quantity');
        });

        Schema::table('element_template_materials', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 2)->nullable()->after('default_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('element_materials', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });

        Schema::table('element_template_materials', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
