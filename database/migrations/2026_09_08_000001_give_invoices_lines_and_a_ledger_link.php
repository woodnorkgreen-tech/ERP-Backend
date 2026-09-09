<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 1 of the general ledger plan: what WNG earns becomes recordable.
 *
 * `project_invoices` has been a header since it was created — one `subtotal`,
 * one `tax_amount`, one `total_amount`, all typed by a person. Two consequences,
 * and the second is the expensive one:
 *
 * 1. The Value Added Tax on an invoice was whatever somebody entered. There was
 *    no rate behind it, no treatment, and nothing to check it against, while the
 *    cost side has priced tax to the cent off effective-dated `vat_treatments`
 *    rows since the tax masters were seeded.
 * 2. Issuing an invoice posted nothing to the ledger, so tax CHARGED to clients
 *    existed nowhere. A Value Added Tax return is tax charged minus tax paid;
 *    WNG's system recorded only the second half, which is why no return could be
 *    produced from it.
 *
 * Lines fix the first and make the second possible: revenue and tax are summed
 * from priced lines rather than asserted, so the journal entry has something
 * defensible to post.
 *
 * The header columns stay and are now DERIVED from the lines rather than being
 * an independent claim. Keeping them avoids rewriting every reader — the
 * receivables screen, the billing-basis cap, the payment allocation balance —
 * to aggregate, and keeps existing invoices readable. They are recomputed on
 * every line change so the two cannot drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_invoice_id')->constrained('project_invoices')->cascadeOnDelete();
            $table->string('description', 500);

            // Quantity carries three decimals because event work is billed in
            // part-days, part-square-metres and part-loads; two would round a
            // half-day of labour into a figure that does not reproduce the price.
            $table->decimal('quantity', 15, 3)->default(1);
            $table->decimal('unit_price', 15, 2);

            /*
             * The treatment, not the rate.
             *
             * Storing a rate would freeze today's 16% into every historical
             * invoice and silently misstate one raised either side of a rate
             * change. `vat_treatments` is effective-dated for exactly this, and
             * the resolved rate is recorded in `tax_amount` below — so the
             * invoice keeps the money it was raised with while the treatment
             * stays a reference to a rule.
             *
             * Nullable because zero-rated, exempt and out-of-scope revenue are
             * all real: a line with no treatment carries no tax.
             */
            $table->foreignId('vat_treatment_id')->nullable()
                ->constrained('vat_treatments')->nullOnDelete();

            /*
             * Which revenue account this line earns into.
             *
             * The chart separates project revenue from hire and rental revenue,
             * and an events business genuinely bills both on one invoice — a
             * stand built and a set of lightboxes hired. Left null the posting
             * falls back to project revenue, which is the ordinary case.
             */
            $table->foreignId('revenue_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();

            $table->decimal('net_amount', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_invoice_id', 'sort_order']);
        });

        Schema::table('project_invoices', function (Blueprint $table) {
            /*
             * The journal entry raised when this invoice was issued.
             *
             * A nullable link rather than a required one: every invoice that
             * exists today was issued without a journal, and back-filling one
             * would invent revenue in a month that has already been reported on.
             * Null therefore means "issued before the ledger recorded revenue",
             * which is a fact worth being able to see rather than paper over.
             */
            $table->foreignId('journal_entry_id')->nullable()->after('status')
                ->constrained('journal_entries')->nullOnDelete();

            // Which month the revenue belongs to. Resolved from the invoice date
            // at issue, the same way a cost line resolves its own.
            $table->foreignId('accounting_period_id')->nullable()->after('journal_entry_id')
                ->constrained('accounting_periods')->nullOnDelete();
        });

        Schema::table('enquiry_payments', function (Blueprint $table) {
            // The journal entry raised when this receipt was verified: the cash
            // arriving. Null on every existing row for the same reason as above.
            $table->foreignId('journal_entry_id')->nullable()->after('status')
                ->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('project_invoice_allocations', function (Blueprint $table) {
            // The journal entry that moved this money from a client deposit to
            // settling the invoice.
            $table->foreignId('journal_entry_id')->nullable()->after('amount')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_invoice_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });

        Schema::table('enquiry_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });

        Schema::table('project_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accounting_period_id');
            $table->dropConstrainedForeignId('journal_entry_id');
        });

        Schema::dropIfExists('project_invoice_lines');
    }
};
