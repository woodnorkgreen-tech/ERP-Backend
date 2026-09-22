<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_statement_transactions', function (Blueprint $table) {
            $table->text('ignore_reason')->nullable()->after('match_status');
        });
    }

    public function down(): void
    {
        Schema::table('finance_statement_transactions', function (Blueprint $table) {
            $table->dropColumn('ignore_reason');
        });
    }
};
