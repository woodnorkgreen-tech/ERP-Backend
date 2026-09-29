<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W2-6 closure-gate §14 fix: Return for Correction (`returnForCorrection()`/
 * `resubmit()`) recorded who returned an order and why, and who resubmitted
 * it — but not what the requester actually changed. Only the final,
 * corrected values survived; the values the approver saw and rejected were
 * simply overwritten with no trace.
 *
 * This is deliberately a separate, smaller table from
 * `purchase_order_amendments` — the same shape (a snapshot before, a
 * snapshot after) answers a different question at a different moment: this
 * one is a `pending_approval` order that was never approved, corrected by
 * whoever raised it; an amendment is an already-approved order being
 * formally changed, gated by its own approval. Reusing one table for both
 * would conflate the two processes the directive keeps explicitly separate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_corrections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedInteger('correction_number');

            $table->unsignedBigInteger('returned_by');
            $table->timestamp('returned_at');
            $table->text('return_reason');
            // What was submitted for approval — captured at the moment of
            // return, before the requester has touched anything.
            $table->json('previous_snapshot');

            $table->unsignedBigInteger('corrected_by')->nullable();
            $table->timestamp('corrected_at')->nullable();
            // What the requester changed it to — captured at resubmission.
            $table->json('corrected_snapshot')->nullable();
            $table->timestamp('resubmitted_at')->nullable();

            $table->timestamps();

            $table->unique(['purchase_order_id', 'correction_number'], 'po_corrections_po_id_number_unique');
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->onDelete('restrict');
            $table->foreign('returned_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('corrected_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_corrections');
    }
};
