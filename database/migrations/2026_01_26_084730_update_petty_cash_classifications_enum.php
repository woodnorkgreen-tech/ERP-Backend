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
        Schema::table('petty_cash_disbursements', function (Blueprint $table) {
            DB::statement("ALTER TABLE petty_cash_disbursements MODIFY COLUMN classification ENUM('agencies', 'admin', 'operations', 'event_planners', 'corporates', 'crs', 'other') NOT NULL");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('petty_cash_disbursements', function (Blueprint $table) {
            DB::statement("ALTER TABLE petty_cash_disbursements MODIFY COLUMN classification ENUM('agencies', 'admin', 'operations', 'other') NOT NULL");
        });
    }
};
