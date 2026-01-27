<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For MySQL, we need to alter the enum by recreating it
        DB::statement("ALTER TABLE petty_cash_top_ups MODIFY COLUMN payment_method ENUM('cash', 'mpesa', 'equity', 'stanbic', 'ncba', 'kcb', 'family', 'bank_transfer', 'other') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE petty_cash_top_ups MODIFY COLUMN payment_method ENUM('cash', 'mpesa', 'bank_transfer', 'other') NOT NULL");
    }
};
