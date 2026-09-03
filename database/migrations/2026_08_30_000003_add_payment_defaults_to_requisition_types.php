<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Close the gap between approving a requisition and paying it.
 *
 * A disbursement requires an expense code and a payment source, and neither is
 * derivable from the requisition today — so after approving "Fuel & Lubricants"
 * the payer still hunts one of 100 expense codes and one of 6 payment sources,
 * classifying a payment the requisition already classified.
 *
 * The type is where that answer belongs: it is decided once, by Finance, for
 * every requisition of that kind. Nullable, because a type without a default
 * simply behaves as it does today — the payer chooses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_requisition_types', function (Blueprint $table) {
            $table->foreignId('default_expense_code_id')->nullable()->after('requires_project')
                ->constrained('expense_codes')->nullOnDelete();
            $table->foreignId('default_payment_source_id')->nullable()->after('default_expense_code_id')
                ->constrained('payment_sources')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('petty_cash_requisition_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_expense_code_id');
            $table->dropConstrainedForeignId('default_payment_source_id');
        });
    }
};
