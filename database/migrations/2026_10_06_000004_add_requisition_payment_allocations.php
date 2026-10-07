<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('petty_cash_requisitions', function (Blueprint $t) { $t->unsignedInteger('disbursement_sequence')->default(0); });
        Schema::table('petty_cash_requisition_items', function (Blueprint $t) {
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            // An external identity is approved for this requisition only, never merged by name.
            $t->string('other_recipient_reference', 100)->nullable();
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->string('requisition_child_reference')->nullable()->unique();
            $t->string('requisition_request_fingerprint', 64)->nullable();
            // SET NULL, as on the requisition's own journal link: the controlled Finance
            // reset (DATA-1) clears the ledger before Payments and must not be blocked.
            $t->foreignId('advance_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $t->timestamp('advance_gl_posting_failed_at')->nullable();
            $t->text('advance_gl_posting_error')->nullable();
        });
        Schema::create('requisition_payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requisition_id')->constrained('petty_cash_requisitions')->restrictOnDelete();
            $t->foreignId('requisition_item_id')->constrained('petty_cash_requisition_items')->restrictOnDelete();
            $t->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $t->string('receiver_type', 20); $t->string('receiver_identity', 150);
            $t->decimal('allocated_amount', 15, 2);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['payment_id', 'requisition_item_id'], 'req_payment_line_unique');
            $t->index(['requisition_id', 'receiver_type', 'receiver_identity'], 'req_receiver_index');
        });
    }
    public function down(): void {
        Schema::dropIfExists('requisition_payment_allocations');
        Schema::table('payments', function (Blueprint $t) { $t->dropForeign(['advance_journal_entry_id']); $t->dropColumn(['requisition_child_reference','requisition_request_fingerprint','advance_journal_entry_id','advance_gl_posting_failed_at','advance_gl_posting_error']); });
        Schema::table('petty_cash_requisition_items', function (Blueprint $t) { $t->dropForeign(['supplier_id']); $t->dropColumn(['supplier_id','other_recipient_reference']); });
        Schema::table('petty_cash_requisitions', fn (Blueprint $t) => $t->dropColumn('disbursement_sequence'));
    }
};
