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
        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('job_number')->nullable()->after('requisition_number');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->unsignedBigInteger('material_id')->nullable()->change();
            $table->string('custom_description')->nullable()->after('material_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn('job_number');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->unsignedBigInteger('material_id')->nullable(false)->change();
            $table->dropColumn('custom_description');
        });
    }
};
