<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A credit note is a `project_invoices` row like any other, with
 * `credits_invoice_id` pointing at the invoice it corrects. It is never a
 * mutation of the original — the original stays exactly as issued, which is
 * the same audit-safety rule every other correction in this ledger follows.
 *
 * Its money columns (and its lines') are stored NEGATIVE. That single
 * convention is what makes every existing `SUM(total_amount)` across an
 * enquiry's invoices — the over-billing cap in `createProjectInvoice()`,
 * `WorkInProgressReleaseService::billedFraction()` — net a credit note
 * against its invoice automatically, with no call site needing to know
 * credit notes exist. Per-invoice balance reads (the invoice list, ageing,
 * the allocation cap) are the exception: they key off one specific invoice
 * row, not an enquiry-wide sum, so they are updated separately to look up
 * this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_invoices', function (Blueprint $table) {
            $table->foreignId('credits_invoice_id')->nullable()->after('project_enquiry_id')
                ->constrained('project_invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credits_invoice_id');
        });
    }
};
