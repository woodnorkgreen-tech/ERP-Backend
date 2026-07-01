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
        Schema::table('payroll_ledgers', function (Blueprint $table) {
            $table->string('recurring_end_month')->nullable()->after('is_recurring'); // Format: YYYY-MM
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_ledgers', function (Blueprint $table) {
            $table->dropColumn('recurring_end_month');
        });
    }
};
