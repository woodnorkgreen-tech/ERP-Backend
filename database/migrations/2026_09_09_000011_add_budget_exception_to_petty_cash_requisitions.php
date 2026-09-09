<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The overrun a requisition was approved under, carried on the requisition.
 *
 * The authoritative record of the decision is the `expenditure_exception` row in
 * governance_audit_logs — it is written by the same service that writes the
 * block, it cannot be edited from any screen, and it is where an auditor asking
 * "who authorised this" should look. This column is not a second copy of that
 * decision; it is the requisition's own memory of it, kept for two things the
 * log cannot do.
 *
 * First, display: the requisition screen has to be able to say "approved outside
 * budget, here is why" without querying another module's audit table.
 *
 * Second, and the reason it holds the reason text: PettyCashCostProducer reads
 * the requisition when it posts the commitment, and copies `reason` into the
 * cost line's `details.unbudgeted_reason`. That is the field the cost account's
 * Unbudgeted panel already renders. Without it the commitment lands with
 * consumes_line_id NULL and no explanation — which is precisely how unbudgeted
 * spend became a close-out discovery rather than a same-day one.
 *
 * JSON rather than six columns because it records one decision taken at one
 * moment, is never partially updated, and is never a query predicate. Its shape:
 *
 *   reason              why the company will carry this overrun
 *   funding_source      where the money comes from instead
 *   budget              the budget at the moment of approval
 *   exposure_before     committed + accrued + actual, before this commitment
 *   requested           the requisition amount
 *   overage             how far past the budget it goes
 *   budget_source       'cost_ledger' or 'budget_summary' — which figure was used
 *   governance_log_id   the audit row that is the real record
 *   approved_by         user id of whoever authorised the exception
 *   approved_at         when
 *   self_approved       true when the approver was also the requester
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->json('budget_exception')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->dropColumn('budget_exception');
        });
    }
};
