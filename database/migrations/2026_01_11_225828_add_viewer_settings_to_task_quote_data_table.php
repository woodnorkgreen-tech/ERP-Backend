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
        Schema::table('task_quote_data', function (Blueprint $table) {
            $table->json('viewer_settings')->nullable()->after('totals');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_quote_data', function (Blueprint $table) {
            $table->dropColumn('viewer_settings');
        });
    }
};
