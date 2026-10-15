<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report 76A Part 2 — the columns behind WNG's decisions A–D.
 *
 *  bills.receipt_value_covered / price_variance_reason
 *      Decision A. An invoice states the value of received goods it covers (at
 *      the order's prices). What it charges beyond or below that is a
 *      purchase-price variance, named and explained, and posted to its own
 *      governed account. NULL means "covers its own net value" — every bill
 *      recorded before this, and every bill with no price difference.
 *
 *  goods_receipt_note_items.reversed_*
 *      A receipt is reversed, never deleted or rewritten.
 *
 *  inventory_logs.movement_reason / cost_centre_id
 *      Decisions C and D. Why stock moved when it was not a project issue or a
 *      purchase receipt — discovered stock, internal consumption, damage,
 *      waste — and who it was for.
 *
 *  stores_finance_postings.journal_entry_id
 *      A movement with no project has a journal and no cost line. The link
 *      lives here, on the Finance side, and not on inventory_logs: the stock
 *      ledger survives a Finance reset, and must not point into tables one
 *      clears (FinanceResetBoundary).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $t) {
            $t->decimal('receipt_value_covered', 15, 2)->nullable();
            $t->string('price_variance_reason', 1000)->nullable();
        });
        Schema::table('goods_receipt_note_items', function (Blueprint $t) {
            $t->timestamp('reversed_at')->nullable();
            $t->foreignId('reversed_by')->nullable()->constrained('users', 'id', 'grn_item_reversed_by_fk')->nullOnDelete();
            $t->string('reversal_reason', 1000)->nullable();
        });
        Schema::table('inventory_logs', function (Blueprint $t) {
            $t->string('movement_reason', 40)->nullable()->index('inv_log_reason_idx');
            $t->foreignId('cost_centre_id')->nullable()->constrained('cost_centres', 'id', 'inv_log_cost_centre_fk')->nullOnDelete();
        });
        Schema::table('stores_finance_postings', function (Blueprint $t) {
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries', 'id', 'stores_fin_post_journal_fk')->nullOnDelete();
        });
        // 'internal_consumption' is longer than the original 24 allowed nothing of.
        Schema::table('stores_finance_postings', function (Blueprint $t) {
            $t->string('posting_type', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('stores_finance_postings', function (Blueprint $t) {
            $t->dropForeign('stores_fin_post_journal_fk'); $t->dropColumn('journal_entry_id');
        });
        Schema::table('inventory_logs', function (Blueprint $t) {
            $t->dropForeign('inv_log_cost_centre_fk');
            $t->dropIndex('inv_log_reason_idx');
            $t->dropColumn(['movement_reason', 'cost_centre_id']);
        });
        Schema::table('goods_receipt_note_items', function (Blueprint $t) {
            $t->dropForeign('grn_item_reversed_by_fk');
            $t->dropColumn(['reversed_at', 'reversed_by', 'reversal_reason']);
        });
        Schema::table('bills', fn (Blueprint $t) => $t->dropColumn(['receipt_value_covered', 'price_variance_reason']));
    }
};
