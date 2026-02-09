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
            $table->foreignId('requisition_id')->nullable()->after('id')->constrained('petty_cash_requisitions')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('petty_cash_top_ups', function (Blueprint $table) {
            $table->dropForeign(['requisition_id']);
            $table->dropColumn('requisition_id');
        });
    }
};
