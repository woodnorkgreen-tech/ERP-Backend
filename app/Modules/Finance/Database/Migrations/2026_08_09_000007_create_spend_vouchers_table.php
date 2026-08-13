<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payment document: one payment, one payee, one set of tax facts.
 *
 * A voucher groups cost lines because a single payment routinely splits across
 * several jobs, and because payee, supplier invoice, VAT and WHT belong to the
 * payment rather than to each line. Cost lines exist without a voucher (a budget
 * line, or a cost reported before anyone decided how it gets paid), so the link
 * is optional in that direction.
 *
 * This is what replaces `petty_cash_requisitions` and `petty_cash_requisition_items`:
 * an advance IS a voucher of type `advance`, and its items are cost lines. The
 * payee acknowledgement those items carried (`digital_signature`, `received_at`)
 * is preserved here deliberately — it is a real control, not incidental.
 *
 * Cash movement stays in the petty-cash ledger, untouched. `petty_cash_*_id`
 * points at the entry that moved the money; this table never computes a balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spend_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_no', 32)->unique();

            $table->enum('type', [
                'payment', 'advance', 'retirement', 'reimbursement', 'refund', 'top_up', 'reversal',
            ])->index();

            $table->enum('status', [
                'draft', 'pending_approval', 'approved', 'paid', 'posted', 'rejected', 'reversed',
            ])->default('draft')->index();

            // Two dates, deliberately: when the money moved, and which period the
            // accounting lands in. They diverge whenever a late voucher arrives
            // after its month has been locked.
            $table->timestamp('transacted_at');
            $table->date('posting_date');
            $table->foreignId('accounting_period_id')->nullable()
                ->constrained('accounting_periods')->nullOnDelete();

            $table->foreignId('payment_source_id')->nullable()
                ->constrained('payment_sources')->nullOnDelete();
            $table->unsignedBigInteger('custodian_user_id')->nullable()->index();
            $table->unsignedBigInteger('requester_user_id')->nullable()->index();

            $table->foreignId('payee_type_id')->nullable()->constrained('payee_types')->nullOnDelete();
            $table->unsignedBigInteger('payee_id')->nullable();
            $table->string('payee_name')->nullable();
            $table->string('payee_phone', 32)->nullable();
            $table->string('payee_kra_pin', 32)->nullable();

            $table->string('payment_method', 32)->nullable();
            $table->string('payment_reference', 64)->nullable();

            $table->string('currency', 3)->default('KES');
            $table->decimal('fx_rate', 18, 8)->default(1);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('base_total_amount', 14, 2)->default(0);

            // Supplier and tax block — brief §4. Nullable throughout because a
            // casual-labour payment has none of it.
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->string('supplier_invoice_no', 64)->nullable();
            $table->date('supplier_invoice_date')->nullable();
            $table->string('etims_invoice_no', 64)->nullable();
            $table->string('etims_cuin', 64)->nullable();
            $table->boolean('buyer_pin_captured')->default(false);
            $table->decimal('net_amount', 14, 2)->nullable();
            $table->decimal('vat_amount', 14, 2)->nullable();
            $table->decimal('wht_amount', 14, 2)->nullable();
            $table->decimal('net_cash_paid', 14, 2)->nullable();
            $table->date('tax_due_date')->nullable();

            // The cash side. Populated by VoucherService, which is the only class
            // that touches both this table and the petty-cash ledger.
            $table->unsignedBigInteger('petty_cash_disbursement_id')->nullable()->index();
            $table->unsignedBigInteger('petty_cash_top_up_id')->nullable()->index();

            // Segregation of duties: requester, approver and poster are separate
            // columns because the brief requires them to be separate people, and a
            // control you cannot query is not a control.
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();

            // Payee acknowledgement, carried over from petty_cash_requisition_items.
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('digital_signature')->nullable();

            $table->foreignId('reversal_of_id')->nullable()
                ->constrained('spend_vouchers')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('posting_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spend_vouchers');
    }
};
