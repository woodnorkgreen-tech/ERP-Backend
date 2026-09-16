<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What actually led to this requisition being raised, in the requester's own
 * words — distinct from `requested_by_type` (the structured project / office /
 * employee category) and from `requisition_items.reason` (a per-line note).
 * Neither says, at the requisition level, *why*: "Client X event setup —
 * Phase 2" vs "Monthly office restock". This is the one place downstream
 * transaction records (purchase order, GRN, bill, payment) trace back to for
 * that context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->text('trigger_reason')->nullable()->after('requested_by_type');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn('trigger_reason');
        });
    }
};
