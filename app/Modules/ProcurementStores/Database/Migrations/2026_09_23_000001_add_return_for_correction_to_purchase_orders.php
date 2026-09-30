<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W2-6 (confirmed 2026-09-23): a PO stuck in `pending_approval` with an error
 * currently has no correction path — the STAB-3 guard blocks direct edits
 * outside `pending`, and no reject()/return-to-pending action exists. This
 * is a status string on the same nullable-columns pattern as the Wave 1
 * invoice Return for Correction (project_invoices.returned_at/etc.) — no
 * enum to extend, since `purchase_orders.status` is already a plain
 * VARCHAR(50) (migration 2026_01_20_073637 converted it from ENUM).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('returned_by')->nullable()->after('approved_by');
            $table->timestamp('returned_at')->nullable()->after('returned_by');
            $table->text('return_reason')->nullable()->after('returned_at');
            $table->timestamp('resubmitted_at')->nullable()->after('return_reason');

            $table->foreign('returned_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['returned_by']);
            $table->dropColumn(['returned_by', 'returned_at', 'return_reason', 'resubmitted_at']);
        });
    }
};
