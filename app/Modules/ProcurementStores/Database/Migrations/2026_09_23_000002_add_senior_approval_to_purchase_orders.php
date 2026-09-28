<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W2-1 (confirmed 2026-09-23, Option C): a senior-approval tier above a
 * WNG-defined high-value threshold, layered on top of — not replacing —
 * PurchaseApprovalPolicy's existing cover/ceiling logic. The threshold
 * itself reuses FinanceSetting::approvedValue() (the same effective-dated,
 * accountant-signed-off mechanism the existing optional Finance ceiling
 * already uses) rather than a new settings table — no threshold row is
 * seeded here, so the gate stays inactive until Finance signs one off.
 *
 * Return/rejection of a senior approval reuses the W2-6 Return for
 * Correction columns/action rather than a second set of them — a senior
 * approver sending the order back is the same action a regular approver
 * returning it is, just gated by a different permission; the returned
 * columns don't need to know which permission the returner held.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->boolean('senior_approval_required')->default(false)->after('resubmitted_at');
            $table->unsignedBigInteger('senior_approved_by')->nullable()->after('senior_approval_required');
            $table->timestamp('senior_approved_at')->nullable()->after('senior_approved_by');

            $table->foreign('senior_approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['senior_approved_by']);
            $table->dropColumn(['senior_approval_required', 'senior_approved_by', 'senior_approved_at']);
        });
    }
};
