<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_disbursements', function (Blueprint $table) {
            $table->foreignId('expense_code_id')->nullable()->after('account')
                ->constrained('expense_codes')->nullOnDelete();
            $table->foreignId('payment_source_id')->nullable()->after('payment_method')
                ->constrained('payment_sources')->nullOnDelete();
            $table->enum('receipt_type', ['etr', 'non_etr', 'none'])->default('none')->after('tax');
            $table->string('receipt_number')->nullable()->after('receipt_type');
            $table->decimal('tax_amount', 14, 2)->default(0)->after('receipt_number');
            $table->text('direct_payment_reason')->nullable()->after('requisition_id');
        });
    }

    public function down(): void
    {
        Schema::table('petty_cash_disbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_code_id');
            $table->dropConstrainedForeignId('payment_source_id');
            $table->dropColumn(['receipt_type', 'receipt_number', 'tax_amount', 'direct_payment_reason']);
        });
    }
};
