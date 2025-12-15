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
        Schema::table('logistics_log_entries', function (Blueprint $table) {
            $table->string('driver')->nullable()->after('vehicle_allocated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('logistics_log_entries', function (Blueprint $table) {
            $table->dropColumn('driver');
        });
    }
};
