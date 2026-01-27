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
        Schema::table('petty_cash_top_ups', function (Blueprint $table) {
            $table->date('date_topped_up')->nullable()->after('previous_balance');
        });
        
        // Initialize existing records with created_at date
        DB::statement('UPDATE petty_cash_top_ups SET date_topped_up = DATE(created_at) WHERE date_topped_up IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('petty_cash_top_ups', function (Blueprint $table) {
            $table->dropColumn('date_topped_up');
        });
    }
};
