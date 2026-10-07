<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Report 75R-B: what happens to a requisition after its receivers are paid.
 *
 * - a receipt confirmation per Payment;
 * - a header for the existing surrender, so it can be made per receiver and in
 *   stages, with its items tied to the requisition line they account for;
 * - the link from each accepted amount or return to the exact funded slice
 *   (line + Payment) it clears;
 * - a recorded release of approved money that will never be paid;
 * - closure of the parent.
 *
 * Nothing existing is rewritten: a requisition paid as one payment keeps using
 * the surrender columns on the requisition itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_requisitions', function (Blueprint $t) {
            $t->unsignedInteger('accountability_sequence')->default(0);
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::create('requisition_receipt_confirmations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requisition_id')->constrained('petty_cash_requisitions')->restrictOnDelete();
            $t->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $t->string('receiver_type', 20);
            $t->string('receiver_identity', 150);
            $t->decimal('amount', 15, 2);
            // 'receiver' when the receiver confirmed for themselves; 'on_behalf' when
            // somebody confirmed for a receiver who has no login.
            $t->string('basis', 20);
            $t->string('represented_name')->nullable();
            $t->string('evidence_reference')->nullable();
            $t->text('note')->nullable();
            $t->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('confirmed_at');
            // A reversed Payment's confirmation is kept and marked, never deleted.
            $t->timestamp('invalidated_at')->nullable();
            $t->string('invalidated_reason')->nullable();
            $t->timestamps();
            $t->index(['requisition_id', 'receiver_type', 'receiver_identity'], 'req_receipt_receiver_index');
        });

        Schema::create('petty_cash_surrenders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requisition_id')->constrained('petty_cash_requisitions')->restrictOnDelete();
            $t->string('reference')->unique();
            $t->string('receiver_type', 20);
            $t->string('receiver_identity', 150);
            $t->string('receiver_name');
            // submitted → reconciled | returned (→ submitted again) ; reconciled → reversed
            $t->string('status', 20)->index();
            $t->decimal('spent_amount', 15, 2)->default(0);
            $t->decimal('returned_amount', 15, 2)->default(0);
            // Claimed above what the receiver holds. Never posted; blocks reconciliation.
            $t->decimal('overspend_amount', 15, 2)->default(0);
            $t->text('notes')->nullable();
            $t->string('idempotency_key')->nullable()->unique();
            $t->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('submitted_at');
            $t->foreignId('returned_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('returned_at')->nullable();
            $t->text('return_reason')->nullable();
            $t->foreignId('reconciled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reconciled_at')->nullable();
            $t->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reversed_at')->nullable();
            $t->text('reversal_reason')->nullable();
            // SET NULL: the controlled Finance reset clears the ledger first.
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $t->timestamps();
            $t->index(['requisition_id', 'receiver_type', 'receiver_identity'], 'pc_surrender_receiver_index');
        });

        Schema::table('petty_cash_surrender_items', function (Blueprint $t) {
            $t->foreignId('surrender_id')->nullable()->constrained('petty_cash_surrenders')->restrictOnDelete();
            $t->unsignedBigInteger('requisition_item_id')->nullable();
            $t->foreign('requisition_item_id', 'pc_surrender_item_line_fk')
                ->references('id')->on('petty_cash_requisition_items')->restrictOnDelete();
        });

        Schema::create('petty_cash_surrender_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('surrender_id')->constrained('petty_cash_surrenders')->restrictOnDelete();
            $t->foreignId('requisition_id')->constrained('petty_cash_requisitions')->restrictOnDelete();
            $t->unsignedBigInteger('requisition_payment_allocation_id');
            $t->foreign('requisition_payment_allocation_id', 'pc_surrender_alloc_slice_fk')
                ->references('id')->on('requisition_payment_allocations')->restrictOnDelete();
            $t->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $t->unsignedBigInteger('requisition_item_id');
            $t->foreign('requisition_item_id', 'pc_surrender_alloc_line_fk')
                ->references('id')->on('petty_cash_requisition_items')->restrictOnDelete();
            $t->string('kind', 10);   // spend | return
            $t->decimal('amount', 15, 2);
            // For a return: the account the money came back into, and its money movement.
            $t->foreignId('payment_source_id')->nullable()->constrained('payment_sources')->restrictOnDelete();
            $t->unsignedBigInteger('cash_movement_id')->nullable();
            $t->foreign('cash_movement_id', 'pc_surrender_alloc_movement_fk')
                ->references('id')->on('finance_cash_movements')->nullOnDelete();
            $t->string('reference')->nullable();
            $t->timestamps();
            $t->index(['requisition_id', 'payment_id']);
        });

        Schema::create('requisition_balance_releases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requisition_id')->constrained('petty_cash_requisitions')->restrictOnDelete();
            $t->unsignedBigInteger('requisition_item_id');
            $t->foreign('requisition_item_id', 'req_release_line_fk')
                ->references('id')->on('petty_cash_requisition_items')->restrictOnDelete();
            $t->string('receiver_type', 20);
            $t->string('receiver_identity', 150);
            $t->decimal('amount', 15, 2);
            $t->text('reason');
            // One decision may release several lines of a receiver; they share this key.
            $t->string('request_key', 64);
            $t->foreignId('released_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('released_at');
            $t->timestamps();
            $t->unique(['request_key', 'requisition_item_id'], 'req_release_request_line_unique');
        });

        // Authority to give up approved money is assigned deliberately; no role gets it here.
        Permission::findOrCreate('finance.requisitions.release_unused', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_balance_releases');
        Schema::dropIfExists('petty_cash_surrender_allocations');
        Schema::table('petty_cash_surrender_items', function (Blueprint $t) {
            $t->dropForeign('pc_surrender_item_line_fk');
            $t->dropConstrainedForeignId('surrender_id');
            $t->dropColumn('requisition_item_id');
        });
        Schema::dropIfExists('petty_cash_surrenders');
        Schema::dropIfExists('requisition_receipt_confirmations');
        Schema::table('petty_cash_requisitions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('closed_by');
            $t->dropColumn(['accountability_sequence', 'closed_at']);
        });
        Permission::where('name', 'finance.requisitions.release_unused')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
