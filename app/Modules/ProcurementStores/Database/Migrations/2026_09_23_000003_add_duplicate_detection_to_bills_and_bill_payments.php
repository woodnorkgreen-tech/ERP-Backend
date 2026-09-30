<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W2-5 (confirmed 2026-09-23): duplicate detection on (Supplier + Supplier
 * Invoice Number) for Bills and (payment source/account + reference) for
 * payments, with an authorized, auditable override where legitimate reuse
 * is possible. Both sides carry the same four columns: which existing
 * record it matched, who authorized proceeding anyway, when, and why —
 * never a silent bypass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->unsignedBigInteger('duplicate_of_bill_id')->nullable()->after('supplier_invoice_number');
            $table->text('duplicate_override_reason')->nullable()->after('duplicate_of_bill_id');
            $table->unsignedBigInteger('duplicate_override_by')->nullable()->after('duplicate_override_reason');
            $table->timestamp('duplicate_override_at')->nullable()->after('duplicate_override_by');

            $table->foreign('duplicate_of_bill_id')->references('id')->on('bills')->onDelete('set null');
            $table->foreign('duplicate_override_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('bill_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('duplicate_of_payment_id')->nullable()->after('reference_number');
            $table->text('duplicate_override_reason')->nullable()->after('duplicate_of_payment_id');
            $table->unsignedBigInteger('duplicate_override_by')->nullable()->after('duplicate_override_reason');
            $table->timestamp('duplicate_override_at')->nullable()->after('duplicate_override_by');

            $table->foreign('duplicate_of_payment_id')->references('id')->on('bill_payments')->onDelete('set null');
            $table->foreign('duplicate_override_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of_bill_id']);
            $table->dropForeign(['duplicate_override_by']);
            $table->dropColumn(['duplicate_of_bill_id', 'duplicate_override_reason', 'duplicate_override_by', 'duplicate_override_at']);
        });

        Schema::table('bill_payments', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of_payment_id']);
            $table->dropForeign(['duplicate_override_by']);
            $table->dropColumn(['duplicate_of_payment_id', 'duplicate_override_reason', 'duplicate_override_by', 'duplicate_override_at']);
        });
    }
};
