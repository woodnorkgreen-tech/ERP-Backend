<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W1-8: explicit, auditable discount — gross, discount, and net must all be
 * distinguishable, rather than a discount hidden inside an already-reduced
 * unit price.
 *
 * `net_amount` keeps its existing meaning (the taxable base every downstream
 * reader already relies on: InvoicePricer::retotal(), the receivables screen,
 * the over-billing cap) — it becomes gross-minus-discount, not a new column.
 * Only `gross_amount` and `discount_amount` are new, purely additive, and
 * default to values that make an un-migrated historical line still correct:
 * gross defaults to the existing net (no discount ever recorded on it) and
 * discount defaults to zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_invoice_lines', function (Blueprint $table) {
            $table->decimal('gross_amount', 15, 2)->nullable()->after('unit_price');
            $table->decimal('discount_amount', 15, 2)->default(0)->after('gross_amount');
        });

        // Backfill existing lines: gross = net (no discount could have existed
        // before this column did), discount = 0. Historical invoices remain
        // correct and visibly "no discount recorded" rather than null/unknown.
        DB::table('project_invoice_lines')->whereNull('gross_amount')
            ->update(['gross_amount' => DB::raw('net_amount'), 'discount_amount' => 0]);
    }

    public function down(): void
    {
        Schema::table('project_invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['gross_amount', 'discount_amount']);
        });
    }
};
