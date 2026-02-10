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
        Schema::table('petty_cash_requisition_items', function (Blueprint $table) {
            $table->foreignId('payee_id')->nullable()->after('amount')->constrained('employees')->onDelete('set null');
            $table->string('payee_name')->nullable()->after('payee_id');
            $table->longText('digital_signature')->nullable()->after('payee_name');
            $table->timestamp('received_at')->nullable()->after('digital_signature');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('petty_cash_requisition_items', function (Blueprint $table) {
            $table->dropForeign(['payee_id']);
            $table->dropColumn(['payee_id', 'payee_name', 'digital_signature', 'received_at']);
        });
    }
};
