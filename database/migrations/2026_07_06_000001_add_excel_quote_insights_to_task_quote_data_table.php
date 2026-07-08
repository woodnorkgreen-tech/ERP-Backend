<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_quote_data', function (Blueprint $table) {
            // Advisory intelligence computed at upload time (detected workbook
            // total, budget comparison, implied margin). Never blocks the flow.
            $table->json('excel_quote_insights')->nullable()->after('excel_quote_uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('task_quote_data', function (Blueprint $table) {
            $table->dropColumn('excel_quote_insights');
        });
    }
};
