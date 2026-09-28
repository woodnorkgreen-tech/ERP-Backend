<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Critical Risk C5 (finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md):
 * a failed advance-journal posting used to be visible nowhere but a server
 * log. These two columns make "disbursed, but the ledger doesn't know yet"
 * a queryable, persistent fact on the requisition itself, cleared the moment
 * a (first or retried) posting attempt actually succeeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->timestamp('advance_gl_posting_failed_at')->nullable()->after('advance_journal_entry_id');
            $table->text('advance_gl_posting_error')->nullable()->after('advance_gl_posting_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->dropColumn(['advance_gl_posting_failed_at', 'advance_gl_posting_error']);
        });
    }
};
