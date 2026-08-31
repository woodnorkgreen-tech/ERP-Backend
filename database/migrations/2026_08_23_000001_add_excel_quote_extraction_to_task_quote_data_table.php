<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_quote_data', function (Blueprint $table) {
            $table->json('excel_quote_extraction')->nullable()->after('excel_quote_insights');
        });
    }

    public function down(): void
    {
        Schema::table('task_quote_data', function (Blueprint $table) {
            $table->dropColumn('excel_quote_extraction');
        });
    }
};
