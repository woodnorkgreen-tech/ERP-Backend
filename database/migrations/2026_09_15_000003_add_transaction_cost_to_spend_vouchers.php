<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spend_vouchers', function (Blueprint $table) {
            $table->decimal('transaction_cost', 14, 2)->default(0)->after('net_cash_paid');
        });
    }

    public function down(): void
    {
        Schema::table('spend_vouchers', function (Blueprint $table) {
            $table->dropColumn('transaction_cost');
        });
    }
};
