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
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->unsignedBigInteger('bill_id')->nullable()->after('payee_id');
            
            // Note: We don't add a strict foreign key constraint here if the 'bills' table 
            // is in a different module/schema, but we can do it if they share the same DB.
            // $table->foreign('bill_id')->references('id')->on('bills')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->dropColumn('bill_id');
        });
    }
};
