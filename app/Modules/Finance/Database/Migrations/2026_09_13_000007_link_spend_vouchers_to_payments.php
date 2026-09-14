<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Close the spend-voucher ↔ payment disconnect.
 *
 * Spend vouchers are the approval workflow; payments are the cash fact.
 * Posting a voucher must mint one Payment (and reduce the float when the
 * paying account is petty cash). The reverse FK lets cost producers skip
 * re-costing a liability that the voucher already settled.
 *
 * Also aligns vocabulary: vouchers awaiting approval are `pending_approval`
 * (same word as requisitions / the work queue), not `draft`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'spend_voucher_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreignId('spend_voucher_id')->nullable()->unique()
                    ->after('requisition_id')
                    ->constrained('spend_vouchers')->nullOnDelete();
            });
        }

        // Legacy rows created before the queue looked for pending_approval.
        DB::table('spend_vouchers')
            ->where('status', 'draft')
            ->update(['status' => 'pending_approval', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('spend_vouchers')
            ->where('status', 'pending_approval')
            ->update(['status' => 'draft', 'updated_at' => now()]);

        if (Schema::hasColumn('payments', 'spend_voucher_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('spend_voucher_id');
            });
        }
    }
};
