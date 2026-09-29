<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W1-1 (preparer/checker/issuer separation), W1-2 (no-quote exception), and
 * the Return-for-Correction pattern shared by W1-1 (invoices) and W1-6
 * (credit notes, which are `project_invoices` rows with `credits_invoice_id`
 * set — no second table needed for either).
 *
 * All columns are nullable and additive: a historical row simply has none of
 * them set, which correctly reads as "issued before this control existed"
 * rather than fabricating a reviewer or a check that never happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_invoices', function (Blueprint $table) {
            // W1-1: review/check, separate from creation and from issuing.
            $table->foreignId('checked_by')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable()->after('checked_by');

            // Return for Correction: shared shape for invoices and credit notes.
            $table->foreignId('returned_by')->nullable()->after('checked_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('returned_by');
            $table->text('return_reason')->nullable()->after('returned_at');
            $table->timestamp('resubmitted_at')->nullable()->after('return_reason');

            // W1-2: the controlled exception for issuing without an approved
            // quote/commercial basis. Nullable throughout — the normal path
            // (an approved basis exists) never touches these.
            $table->text('no_quote_exception_reason')->nullable()->after('resubmitted_at');
            $table->foreignId('no_quote_exception_requested_by')->nullable()
                ->after('no_quote_exception_reason')->constrained('users')->nullOnDelete();
            $table->foreignId('no_quote_exception_approved_by')->nullable()
                ->after('no_quote_exception_requested_by')->constrained('users')->nullOnDelete();
            $table->timestamp('no_quote_exception_approved_at')->nullable()
                ->after('no_quote_exception_approved_by');
            // A reference into the generic finance_attachments mechanism, or a
            // plain text reference where no file exists — evidence is enabled
            // here, not mandated; W3-4's evidence matrix decides when it must
            // actually be supplied.
            $table->string('no_quote_exception_evidence_reference', 255)->nullable()
                ->after('no_quote_exception_approved_at');

            // W1-7: optional link to a configured payment-term template. The
            // due_date column is unchanged and remains the source of truth —
            // this only records which template (if any) produced it, and
            // still allows a fully custom, manually-typed due date.
            $table->foreignId('payment_term_id')->nullable()->after('due_date')
                ->constrained('payment_terms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_term_id');
            $table->dropColumn('no_quote_exception_evidence_reference');
            $table->dropConstrainedForeignId('no_quote_exception_approved_by');
            $table->dropColumn('no_quote_exception_approved_at');
            $table->dropConstrainedForeignId('no_quote_exception_requested_by');
            $table->dropColumn('no_quote_exception_reason');
            $table->dropColumn('resubmitted_at');
            $table->dropColumn('return_reason');
            $table->dropColumn('returned_at');
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn('checked_at');
            $table->dropConstrainedForeignId('checked_by');
        });
    }
};
