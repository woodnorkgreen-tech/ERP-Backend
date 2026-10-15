<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report 76A-3 — bill lines, receipt allocations, supplier credits, tax basis.
 *
 * Additive throughout. No existing amount is rewritten and nothing is
 * backfilled: a bill with no lines, and an order with no stated price basis,
 * are read exactly as they were recorded.
 *
 *  bill_lines / bill_line_receipt_allocations
 *      What a supplier invoice charges, line by line, and the specific accepted
 *      goods-receipt quantities each line covers. The receipt value a bill
 *      clears is the sum of its allocations — computed here, never typed.
 *
 *  supplier_credit_notes / supplier_credit_note_lines
 *      A supplier's credit against a verified bill, or the bill's reversal.
 *      Always a new document with its own journal.
 *
 *  receipt_accrual_closeouts
 *      Received value Finance has confirmed will never be invoiced, released
 *      from the receipt accrual. One per receipt line.
 *
 *  purchase_orders.price_basis / purchase_order_items quote columns
 *      'net' when the order was priced net of VAT under this rule. NULL on
 *      every earlier order: its basis was never recorded and is not assumed.
 *
 * Depends on: 2026_10_08_000001 (bills.receipt_value_covered,
 * goods_receipt_note_items.reversed_at) and 2026_10_07_000001
 * (finance_event_postings).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('bill_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bill_id')->constrained('bills', 'id', 'bill_lines_bill_fk')->cascadeOnDelete();
            $t->unsignedInteger('line_no');
            $t->foreignId('purchase_order_item_id')->constrained('purchase_order_items', 'id', 'bill_lines_po_item_fk');
            $t->string('description', 500)->nullable();
            $t->decimal('quantity', 18, 6);
            $t->decimal('unit_price_net', 15, 4);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('net_amount', 15, 2);
            $t->foreignId('vat_treatment_id')->nullable()->constrained('vat_treatments', 'id', 'bill_lines_vat_fk')->nullOnDelete();
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('gross_amount', 15, 2);
            // Server-computed from the allocations below.
            $t->decimal('allocated_quantity', 18, 6)->default(0);
            $t->decimal('receipt_value_covered', 15, 2)->default(0);
            // net (+ VAT that cannot be recovered) − receipt value covered.
            $t->decimal('cost_difference', 15, 2)->default(0);
            $t->string('variance_reason', 1000)->nullable();
            // Where a posted difference went: revalued stock still on hand, and the rest.
            $t->decimal('posted_to_inventory', 15, 2)->default(0);
            $t->decimal('posted_to_variance', 15, 2)->default(0);
            // 'stated' by the preparer; 'derived' for a summary bill with no line detail.
            $t->string('allocation_basis', 12)->default('stated');
            $t->decimal('credited_quantity', 18, 6)->default(0);
            $t->decimal('credited_net', 15, 2)->default(0);
            $t->string('status', 20)->default('open');
            $t->timestamps();
            $t->unique(['bill_id', 'line_no'], 'bill_lines_bill_line_unique');
            $t->index('purchase_order_item_id', 'bill_lines_po_item_idx');
        });

        Schema::create('bill_line_receipt_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bill_line_id')->constrained('bill_lines', 'id', 'bill_alloc_line_fk')->cascadeOnDelete();
            $t->foreignId('goods_receipt_note_item_id')->constrained('goods_receipt_note_items', 'id', 'bill_alloc_grn_item_fk');
            $t->decimal('quantity', 18, 6);
            $t->decimal('receipt_value', 15, 2);
            // Given back by a quantity credit or a bill reversal; the row is kept.
            $t->decimal('released_quantity', 18, 6)->default(0);
            $t->decimal('released_value', 15, 2)->default(0);
            $t->timestamps();
            // One line covers one receipt line once: a repeat is the same allocation.
            $t->unique(['bill_line_id', 'goods_receipt_note_item_id'], 'bill_alloc_line_grn_unique');
            $t->index('goods_receipt_note_item_id', 'bill_alloc_grn_item_idx');
        });

        Schema::create('supplier_credit_notes', function (Blueprint $t) {
            $t->id();
            $t->string('credit_number', 40)->unique('supplier_credit_number_unique');
            $t->foreignId('bill_id')->constrained('bills', 'id', 'supplier_credit_bill_fk');
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers', 'id', 'supplier_credit_supplier_fk')->nullOnDelete();
            // 'credit' — the supplier's credit note; 'reversal' — the bill withdrawn in full.
            $t->string('kind', 12);
            // The bill id for a reversal, NULL for a credit: a bill is reversed once.
            $t->unsignedBigInteger('reversal_of_bill_id')->nullable()->unique('supplier_credit_one_reversal_unique');
            $t->string('supplier_reference', 120)->nullable();
            $t->date('credit_date');
            $t->string('reason', 1000);
            $t->decimal('net_amount', 15, 2);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('wht_amount', 15, 2)->default(0);
            $t->decimal('amount', 15, 2);
            $t->decimal('receipt_value_released', 15, 2)->default(0);
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries', 'id', 'supplier_credit_journal_fk')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users', 'id', 'supplier_credit_created_by_fk')->nullOnDelete();
            $t->timestamps();
            // A supplier's credit note number is recorded against a bill once.
            $t->unique(['bill_id', 'supplier_reference'], 'supplier_credit_bill_reference_unique');
        });

        Schema::create('supplier_credit_note_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_credit_note_id')->constrained('supplier_credit_notes', 'id', 'supplier_credit_line_note_fk')->cascadeOnDelete();
            $t->foreignId('bill_line_id')->nullable()->constrained('bill_lines', 'id', 'supplier_credit_line_bill_line_fk')->nullOnDelete();
            // 0 for a price-only credit: nothing is un-billed, only the price falls.
            $t->decimal('quantity', 18, 6)->default(0);
            $t->decimal('net_amount', 15, 2);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('receipt_value_released', 15, 2)->default(0);
            $t->decimal('posted_to_inventory', 15, 2)->default(0);
            $t->decimal('posted_to_variance', 15, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('receipt_accrual_closeouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('goods_receipt_note_item_id')->constrained('goods_receipt_note_items', 'id', 'receipt_closeout_grn_item_fk');
            $t->decimal('quantity', 18, 6);
            $t->decimal('value', 15, 2);
            $t->string('reason', 1000);
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries', 'id', 'receipt_closeout_journal_fk')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users', 'id', 'receipt_closeout_created_by_fk')->nullOnDelete();
            $t->timestamps();
            $t->unique('goods_receipt_note_item_id', 'receipt_closeout_one_per_line_unique');
        });

        Schema::table('bills', function (Blueprint $t) {
            $t->decimal('credited_amount', 15, 2)->default(0);
            $t->timestamp('reversed_at')->nullable();
            $t->foreignId('reversed_by')->nullable()->constrained('users', 'id', 'bills_reversed_by_fk')->nullOnDelete();
            $t->string('reversal_reason', 1000)->nullable();
        });

        // A bill cleared by a supplier's credit and never paid is not "paid".
        // The list only grows; no existing value changes.
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE bills MODIFY status ENUM('pending','paid','partial','overdue','cancelled','credited') NULL DEFAULT 'pending'");

        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->string('price_basis', 12)->nullable();
        });
        Schema::table('purchase_order_items', function (Blueprint $t) {
            // What the supplier quoted, exactly as quoted, before normalising to net.
            $t->decimal('quoted_unit_price', 15, 4)->nullable();
            $t->boolean('quoted_price_includes_tax')->nullable();
            $t->foreignId('vat_treatment_id')->nullable()->constrained('vat_treatments', 'id', 'po_items_vat_fk')->nullOnDelete();
            $t->decimal('tax_rate_percent', 7, 3)->nullable();
            $t->decimal('net_amount', 15, 2)->nullable();
            $t->decimal('tax_amount', 15, 2)->nullable();
            $t->decimal('gross_amount', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $t) {
            $t->dropForeign('po_items_vat_fk');
            $t->dropColumn(['quoted_unit_price', 'quoted_price_includes_tax', 'vat_treatment_id', 'tax_rate_percent', 'net_amount', 'tax_amount', 'gross_amount']);
        });
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn('price_basis'));
        // Before the value leaves the list: a credited bill has nothing owing,
        // which is what `paid` meant to every reader before this migration.
        \Illuminate\Support\Facades\DB::table('bills')->where('status', 'credited')->update(['status' => 'paid']);
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE bills MODIFY status ENUM('pending','paid','partial','overdue','cancelled') NULL DEFAULT 'pending'");
        Schema::table('bills', function (Blueprint $t) {
            $t->dropForeign('bills_reversed_by_fk');
            $t->dropColumn(['credited_amount', 'reversed_at', 'reversed_by', 'reversal_reason']);
        });
        Schema::dropIfExists('receipt_accrual_closeouts');
        Schema::dropIfExists('supplier_credit_note_lines');
        Schema::dropIfExists('supplier_credit_notes');
        Schema::dropIfExists('bill_line_receipt_allocations');
        Schema::dropIfExists('bill_lines');
    }
};
