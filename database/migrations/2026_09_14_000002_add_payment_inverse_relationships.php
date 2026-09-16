<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 2 of Finance Architecture Redesign:
     * Add inverse relationships from Payment to Voucher and polymorphic source document link.
     * This makes "Payment owns the voucher" and "What are we paying?" explicit.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Inverse relationship: Payment → Voucher
            // Currently SpendVoucher.petty_cash_disbursement_id → Payment
            // Add Payment.voucher_id → SpendVoucher for bidirectional clarity
            $table->foreignId('voucher_id')
                ->nullable()
                ->after('spend_voucher_id')
                ->comment('Inverse link to payment voucher (spend_voucher)')
                ->constrained('spend_vouchers')
                ->nullOnDelete();

            // Polymorphic source document: What authorization triggered this payment?
            // Could be Bill, CostLine, PettyCashRequisition, AdvanceRequest, etc.
            $table->string('source_document_type')
                ->nullable()
                ->after('voucher_id')
                ->comment('Polymorphic type of source document');

            $table->unsignedBigInteger('source_document_id')
                ->nullable()
                ->after('source_document_type')
                ->comment('Polymorphic ID of source document');

            $table->index(['source_document_type', 'source_document_id'], 'payments_source_document_index');
        });

        // Populate voucher_id from existing spend_voucher_id relationship
        // This establishes the bidirectional link for existing data
        DB::statement('
            UPDATE payments p
            INNER JOIN spend_vouchers sv ON sv.petty_cash_disbursement_id = p.id
            SET p.voucher_id = sv.id
            WHERE p.spend_voucher_id IS NOT NULL
        ');

        echo PHP_EOL . "  ✓ Populated voucher_id for existing payment records" . PHP_EOL;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['voucher_id']);
            $table->dropIndex('payments_source_document_index');
            $table->dropColumn(['voucher_id', 'source_document_type', 'source_document_id']);
        });
    }
};
