<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier invoice could not state its own tax.
 *
 * `bills` carried a single `amount` and nothing else, so every purchase made
 * through procurement reached the ledger VAT-free: the goods-receipt accrual
 * passes no tax, the stores issue passes no tax, and no later step ever priced
 * any. WNG is VAT-registered and files to KRA, which makes this unclaimed input
 * tax on every supplier invoice — real money, not a reporting gap. Petty cash
 * and spend vouchers have carried their tax identity since the cost-collector
 * work; the procurement rail is the one that never did.
 *
 * ## What the columns mean
 *
 * `amount` KEEPS its meaning: the gross the supplier billed. Everything already
 * reads it that way and it is what the invoice document says.
 *
 *   net_amount + vat_amount = amount          (the invoice, split)
 *   amount − wht_amount     = what is paid    (withholding is retained for KRA)
 *
 * Backfilled so every existing row states net = amount, no VAT, no withholding —
 * which is exactly what those invoices claimed, and keeps the three-way match
 * and the payment balance arithmetically identical for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            // The split. Nullable net rather than defaulted, so a row that
            // somehow escapes the backfill is visibly unpriced instead of
            // silently claiming to be zero-rated.
            $table->decimal('net_amount', 14, 2)->nullable()->after('amount');
            $table->decimal('vat_amount', 14, 2)->default(0)->after('net_amount');
            $table->decimal('wht_amount', 14, 2)->default(0)->after('vat_amount');

            $table->unsignedBigInteger('vat_treatment_id')->nullable()->after('wht_amount');
            $table->unsignedBigInteger('wht_category_id')->nullable()->after('vat_treatment_id');

            /*
             * The claim evidence, named as `cost_lines` already names it so the
             * VAT schedules can read both rails the same way.
             *
             * `tax_point_date` is separate from `bill_date` for the reason the
             * cost ledger keeps them separate: the claim window runs from the
             * supplier's document, and the two can differ.
             */
            $table->string('etims_invoice_no', 64)->nullable()->after('wht_category_id');
            $table->string('supplier_pin', 20)->nullable()->after('etims_invoice_no');
            $table->date('tax_point_date')->nullable()->after('supplier_pin');

            $table->foreign('vat_treatment_id')->references('id')->on('vat_treatments')->nullOnDelete();
            $table->foreign('wht_category_id')->references('id')->on('wht_categories')->nullOnDelete();
        });

        DB::table('bills')->whereNull('net_amount')->update([
            'net_amount' => DB::raw('amount'),
            'vat_amount' => 0,
            'wht_amount' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropForeign(['vat_treatment_id']);
            $table->dropForeign(['wht_category_id']);
            $table->dropColumn([
                'net_amount', 'vat_amount', 'wht_amount',
                'vat_treatment_id', 'wht_category_id',
                'etims_invoice_no', 'supplier_pin', 'tax_point_date',
            ]);
        });
    }
};
