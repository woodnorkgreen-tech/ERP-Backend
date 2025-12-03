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
        Schema::table('transport_items', function (Blueprint $table) {
            $table->enum('main_category', ['PRODUCTION', 'TOOLS_EQUIPMENTS', 'STORES', 'ELECTRICALS'])->nullable()->after('category');
            $table->string('element_category')->nullable()->after('main_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transport_items', function (Blueprint $table) {
            $table->dropColumn(['main_category', 'element_category']);
        });
    }
};
