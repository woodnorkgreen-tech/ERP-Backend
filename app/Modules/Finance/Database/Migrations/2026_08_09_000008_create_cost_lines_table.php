<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The project cost account. One row is one cost fact.
 *
 * Budget and spend live in the SAME table, separated by `nature`. That is the
 * central decision of this design: keeping them apart is why variance is a
 * report everywhere else, and why unbudgeted spend is normally discovered at
 * close-out rather than the moment it happens. Here it is one GROUP BY.
 *
 *   planned    the approved budget, projected from task_budget_data
 *   committed  money promised — approved PO, engaged crew (encumbrance, no journal)
 *   accrued    goods received, not yet invoiced (brief §5); reversed when the
 *              actual lands, so the two can never both count
 *   actual     the real cost
 *
 * A row counts toward project cost when `status = verified`. That is the only
 * rule; planned rows are verified on budget completion, producer rows on their
 * own module's approval, captured rows by a human.
 *
 * `consumes_line_id` points an actual at the planned line it fulfils. NULL is
 * the most valuable state in the schema: unbudgeted spend, visible immediately.
 *
 * Append-only. No soft deletes, no updates to posted figures — corrections are
 * reversals, exactly as the petty-cash ledger and the overtime ledger already
 * work in this codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_lines', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 32)->unique();          // JOB-2451-L014, quotable on WhatsApp

            // ── Cost object ────────────────────────────────────────────────
            // All three identity columns are kept, resolved by the existing
            // ProjectIdentityResolver. job_number stays because it is the only
            // project link 1,384 historical disbursements actually carry.
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('project_enquiry_id')->nullable()->constrained('project_enquiries')->nullOnDelete();
            $table->string('job_number', 64)->nullable()->index();

            $table->foreignId('consumes_line_id')->nullable()->constrained('cost_lines')->nullOnDelete();

            // ── Classification: every dimension a foreign key, never a string ──
            $table->foreignId('expense_code_id')->nullable()->constrained('expense_codes')->nullOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained('cost_centres')->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('activities')->nullOnDelete();
            $table->foreignId('cost_cause_id')->nullable()->constrained('cost_causes')->nullOnDelete();

            $table->enum('nature', ['planned', 'committed', 'accrued', 'actual'])->index();
            $table->enum('status', [
                'draft', 'submitted', 'queried', 'verified', 'rejected', 'reversed',
            ])->default('draft')->index();

            // ── Money ──────────────────────────────────────────────────────
            // Decimal throughout, never float. `amount` is gross as it appears on
            // the receipt; the field user enters that and nothing else. Finance
            // sets tax at verification, because recoverability depends on eTIMS
            // validity and the claim window, which no one on site can judge.
            // Project cost is `net_amount`; base_* carries the reporting currency
            // so a project with a foreign purchase still sums correctly.
            $table->string('currency', 3)->default('KES');
            $table->decimal('fx_rate', 18, 8)->default(1);
            $table->decimal('amount', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2);
            $table->decimal('base_net_amount', 14, 2);

            $table->foreignId('vat_treatment_id')->nullable()->constrained('vat_treatments')->nullOnDelete();
            $table->foreignId('wht_category_id')->nullable()->constrained('wht_categories')->nullOnDelete();
            $table->decimal('wht_amount', 14, 2)->default(0);

            // ── Quantity ───────────────────────────────────────────────────
            $table->string('description')->nullable();
            $table->string('unit', 32)->nullable();
            $table->decimal('quantity', 14, 3)->nullable();
            $table->decimal('unit_rate', 14, 4)->nullable();

            // ── Budget snapshot (brief §4) ─────────────────────────────────
            // Recorded at write time, not derived later: what the budget looked
            // like when the decision was taken is itself the audit record.
            $table->decimal('budget_remaining_before', 14, 2)->nullable();
            $table->decimal('budget_remaining_after', 14, 2)->nullable();

            // ── Period ─────────────────────────────────────────────────────
            $table->timestamp('incurred_at')->nullable()->index();
            $table->date('posting_date')->nullable();
            $table->foreignId('accounting_period_id')->nullable()
                ->constrained('accounting_periods')->nullOnDelete();

            // ── Provenance ─────────────────────────────────────────────────
            // The idempotency key. A producer that retries — a GRN sync, a payroll
            // run, a backfill re-run — cannot post the same cost twice.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->foreignId('voucher_id')->nullable()->constrained('spend_vouchers')->nullOnDelete();
            // The advance that funded this cost. Unretired float per person is
            // advance.total_amount minus the verified lines pointing here.
            $table->foreignId('funding_voucher_id')->nullable()->constrained('spend_vouchers')->nullOnDelete();

            // ── Dynamic payload ────────────────────────────────────────────
            // `details` holds the per-code fields declared by the expense code's
            // extra_operational_data; `evidence` the uploads it demands. Neither
            // needs a schema change when Finance adds an expense type.
            $table->json('details')->nullable();
            $table->json('evidence')->nullable();
            $table->json('capture_meta')->nullable();     // device, geo, app version

            // ── People ─────────────────────────────────────────────────────
            // submitted_by_* is who reported it; payee_* is who received the money.
            // They are separate because casual workers and vendors have no ERP
            // account and are captured on their behalf by named staff.
            $table->unsignedBigInteger('submitted_by_user_id')->nullable()->index();
            $table->string('submitted_by_name')->nullable();
            $table->string('submitted_by_phone', 32)->nullable();

            $table->foreignId('payee_type_id')->nullable()->constrained('payee_types')->nullOnDelete();
            $table->unsignedBigInteger('payee_id')->nullable();
            $table->string('payee_name')->nullable();

            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('query_note')->nullable();

            // ── Asset / site ───────────────────────────────────────────────
            $table->unsignedBigInteger('asset_id')->nullable()->index();
            $table->string('site')->nullable();

            // ── Lifecycle ──────────────────────────────────────────────────
            $table->foreignId('reversal_of_id')->nullable()->constrained('cost_lines')->nullOnDelete();

            // WIP relief hook (brief NE-023). Nothing writes these until revenue
            // recognition exists, but the columns mean that transfer is a state
            // change rather than a schema migration later.
            $table->timestamp('cos_transferred_at')->nullable();
            $table->string('cos_transfer_ref', 64)->nullable();

            // GL hook. Null until the journal phase; the dimensions above are
            // captured from day one precisely so that backfill is a batch job.
            $table->unsignedBigInteger('journal_entry_id')->nullable()->index();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->unique(['source_type', 'source_id'], 'cost_lines_source_unique');
            $table->index(['project_id', 'nature', 'status'], 'cost_lines_project_rollup_idx');
            $table->index(['project_enquiry_id', 'nature', 'status'], 'cost_lines_enquiry_rollup_idx');
            $table->index(['nature', 'status', 'posting_date'], 'cost_lines_period_rollup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_lines');
    }
};
