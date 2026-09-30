<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Critical Risk C7 (finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md):
 * payroll's cash-out previously created a JournalEntry and four reference
 * columns on the run itself, but no independent Payment row — so payroll,
 * potentially WNG's largest recurring cash outflow, was invisible to every
 * Payment-based control (fund custody, reversal, bank reconciliation).
 * This column is populated going forward only (see
 * PayrollFinancePostingService::postPayment()); historical runs are left
 * null pending the separate backfill decision in STAB-6/W10-1
 * (finance-redesign/phase-2/03_WNG_FINANCE_DECISION_REGISTER.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->foreignId('payment_id')
                ->nullable()
                ->after('payment_journal_entry_id')
                ->constrained('payments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropColumn('payment_id');
        });
    }
};
