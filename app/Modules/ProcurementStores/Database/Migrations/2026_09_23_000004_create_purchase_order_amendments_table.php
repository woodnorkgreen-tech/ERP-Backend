<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W2-4 (confirmed 2026-09-23, Option B): a formal amendment/change-order
 * record for a PO that has already left `pending`, which STAB-3 now blocks
 * from direct editing entirely. A separate, additive record — never an edit
 * to the original PO row — so the original approved version stays exactly
 * as it was approved, and every proposed change has its own full history
 * (requester, reason, before/after, approval decision) regardless of
 * whether that particular change turned out to need reapproval.
 *
 * Before/after are stored as snapshots rather than a column-by-column diff:
 * a PO's commercial shape includes its items (quantity/price/material), not
 * just its own row, and a snapshot is the only representation that stays
 * meaningful whichever fields a given amendment happens to touch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_amendments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedInteger('amendment_number');
            $table->text('reason');
            $table->unsignedBigInteger('requested_by');
            $table->timestamp('requested_at');

            // W2-4: distinguishes a change requiring the same reapproval an
            // original approval needed (supplier, quantity, price, scope,
            // value) from an administrative correction (e.g. delivery
            // address) that may proceed on a lighter, non-commercial path.
            // Computed from which fields actually changed — never an
            // invented KES/percentage materiality threshold.
            $table->boolean('is_commercial');
            $table->json('changed_fields');
            $table->json('original_snapshot');
            $table->json('proposed_snapshot');

            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->unique(['purchase_order_id', 'amendment_number'], 'po_amendments_po_id_number_unique');
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->onDelete('restrict');
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('rejected_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_amendments');
    }
};
