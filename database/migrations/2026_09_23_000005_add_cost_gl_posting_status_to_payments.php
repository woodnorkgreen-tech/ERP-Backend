<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 1 Closure Gate, §3.D: a direct petty-cash disbursement's cost/GL entry
 * is posted a moment later by a QUEUED listener (RecordPettyCashCost), after
 * the cash has already left the float. If that posting throws — including,
 * but not limited to, the expense-code/payment-source GL mapping guard in
 * JournalPostingService::postDirectPayment() — the failure was previously
 * only a log line, with no Finance-visible flag and no controlled retry,
 * unlike the equivalent, already-solved problem for requisition advances
 * (STAB-4's advance_gl_posting_failed_at/advance_gl_posting_error on
 * petty_cash_requisitions). These two columns are that same pattern, reused
 * rather than reinvented, for the direct-disbursement posting path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('cost_gl_posting_failed_at')->nullable()->after('planned_cost_line_id');
            $table->text('cost_gl_posting_error')->nullable()->after('cost_gl_posting_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['cost_gl_posting_failed_at', 'cost_gl_posting_error']);
        });
    }
};
