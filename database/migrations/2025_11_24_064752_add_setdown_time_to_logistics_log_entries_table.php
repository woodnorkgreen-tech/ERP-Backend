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
            $table->dateTime('setdown_time')->nullable()->after('departure');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('logistics_log_entries', function (Blueprint $table) {
            $table->dropColumn('setdown_time');
        });
    }
};
