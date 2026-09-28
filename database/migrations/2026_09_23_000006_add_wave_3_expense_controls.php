<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spend_vouchers', function (Blueprint $table) {
            $table->string('review_state', 32)->default('submitted')->after('status')->index();
            $table->unsignedBigInteger('returned_by')->nullable()->after('approved_at');
            $table->timestamp('returned_at')->nullable()->after('returned_by');
            $table->text('return_reason')->nullable()->after('returned_at');
            $table->unsignedBigInteger('resubmitted_by')->nullable()->after('return_reason');
            $table->timestamp('resubmitted_at')->nullable()->after('resubmitted_by');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('resubmitted_at');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->unsignedBigInteger('senior_approved_by')->nullable()->after('rejection_reason');
            $table->timestamp('senior_approved_at')->nullable()->after('senior_approved_by');
        });
        DB::table('spend_vouchers')->whereIn('status', ['approved', 'paid', 'posted', 'reversed'])
            ->update(['review_state' => 'approved']);
        DB::table('spend_vouchers')->where('status', 'rejected')->update(['review_state' => 'rejected']);

        Schema::create('spend_voucher_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spend_voucher_id')->constrained('spend_vouchers')->cascadeOnDelete();
            $table->string('action', 32);
            $table->unsignedBigInteger('actor_user_id');
            $table->text('reason')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->index(['spend_voucher_id', 'created_at']);
        });

        // W3-6: a returned surrender is its own state — never 'rejected'.
        DB::statement("ALTER TABLE petty_cash_requisitions MODIFY COLUMN status ENUM(
            'pending', 'approved', 'rejected', 'disbursed', 'received',
            'surrender_pending', 'surrender_returned', 'surrendered'
        ) NOT NULL DEFAULT 'pending'");

        // W3-1: what kind of expense process a payment was — deliberately NOT
        // payments.classification, which is the client-segment enum
        // (agencies, corporates, ...) and says nothing about the process. A
        // walk-in cash purchase is a direct disbursement with no requisition,
        // so the flag lives on the payment, not on a requisition.
        Schema::table('payments', function (Blueprint $table) {
            $table->string('transaction_classification', 32)->nullable()->after('classification')->index();
        });

        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->date('surrender_due_at')->nullable()->after('surrendered_at')->index();
            $table->unsignedBigInteger('surrender_returned_by')->nullable()->after('surrender_due_at');
            $table->timestamp('surrender_returned_at')->nullable()->after('surrender_returned_by');
            $table->text('surrender_return_reason')->nullable()->after('surrender_returned_at');
            $table->unsignedBigInteger('surrender_resubmitted_by')->nullable()->after('surrender_return_reason');
            $table->timestamp('surrender_resubmitted_at')->nullable()->after('surrender_resubmitted_by');
            // W3-7: controlled post-posting reversal. The generation numbers the
            // surrender's clearing journal, so a corrected surrender posts a
            // fresh entry instead of being short-circuited by the reversed one.
            $table->unsignedBigInteger('surrender_reversed_by')->nullable()->after('surrender_resubmitted_at');
            $table->timestamp('surrender_reversed_at')->nullable()->after('surrender_reversed_by');
            $table->text('surrender_reversal_reason')->nullable()->after('surrender_reversed_at');
            $table->unsignedSmallInteger('surrender_posting_generation')->default(0)->after('surrender_reversal_reason');
            // W5-9: the authorised exception to the overdue-advance guard.
            $table->json('outstanding_advance_exception')->nullable()->after('surrender_posting_generation');
        });

        Schema::create('petty_cash_surrender_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petty_cash_requisition_id')->constrained('petty_cash_requisitions')->cascadeOnDelete();
            $table->string('action', 32);
            $table->unsignedBigInteger('actor_user_id');
            $table->text('reason')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });

        Schema::table('petty_cash_surrender_items', function (Blueprint $table) {
            // Named explicitly: the generated name is 65 characters, one over
            // MariaDB's identifier limit, and the migration failed outright.
            $table->unsignedBigInteger('duplicate_of_surrender_item_id')->nullable()->after('receipt_path');
            $table->foreign('duplicate_of_surrender_item_id', 'pcsi_duplicate_of_fk')
                ->references('id')->on('petty_cash_surrender_items')->nullOnDelete();
            // A receipt already paid as a direct disbursement (e.g. a W3-1 cash purchase).
            $table->unsignedBigInteger('duplicate_of_payment_id')->nullable()->after('duplicate_of_surrender_item_id');
            $table->text('duplicate_override_reason')->nullable()->after('duplicate_of_payment_id');
            $table->unsignedBigInteger('duplicate_overridden_by')->nullable()->after('duplicate_override_reason');
            $table->timestamp('duplicate_overridden_at')->nullable()->after('duplicate_overridden_by');
            // W3-7: a reconciled item replaced by a correction is retired, never
            // deleted — its reversed cost line still points at it.
            $table->timestamp('superseded_at')->nullable()->after('duplicate_overridden_at')->index();
        });

        Schema::table('salary_advance_requests', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('ledger_id')->constrained('payments')->nullOnDelete();
            $table->decimal('amount_recovered', 15, 2)->default(0)->after('payment_id');
            $table->timestamp('paid_at')->nullable()->after('amount_recovered');
            $table->timestamp('fully_recovered_at')->nullable()->after('paid_at');
        });

        Schema::table('payroll_ledgers', function (Blueprint $table) {
            $table->foreignId('salary_advance_request_id')->nullable()->after('employee_id')
                ->constrained('salary_advance_requests')->nullOnDelete();
        });

        Schema::create('salary_advance_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_advance_request_id')->constrained('salary_advance_requests')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('recovered_on');
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('recorded_by');
            $table->timestamps();
            // One recovery per advance per payroll run — recording the same
            // run twice would overstate what has been recovered.
            $table->unique(['salary_advance_request_id', 'payroll_run_id'], 'sa_recovery_run_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_advance_recoveries');
        Schema::table('payroll_ledgers', fn (Blueprint $table) => $table->dropConstrainedForeignId('salary_advance_request_id'));
        Schema::table('salary_advance_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn(['amount_recovered', 'paid_at', 'fully_recovered_at']);
        });
        Schema::dropIfExists('petty_cash_surrender_reviews');
        Schema::table('petty_cash_surrender_items', function (Blueprint $table) {
            $table->dropForeign('pcsi_duplicate_of_fk');
            $table->dropColumn('duplicate_of_surrender_item_id');
            $table->dropColumn(['duplicate_of_payment_id', 'duplicate_override_reason', 'duplicate_overridden_by', 'duplicate_overridden_at', 'superseded_at']);
        });
        Schema::table('petty_cash_requisitions', fn (Blueprint $table) => $table->dropColumn([
            'surrender_due_at', 'surrender_returned_by', 'surrender_returned_at',
            'surrender_return_reason', 'surrender_resubmitted_by', 'surrender_resubmitted_at',
            'surrender_reversed_by', 'surrender_reversed_at', 'surrender_reversal_reason',
            'surrender_posting_generation', 'outstanding_advance_exception',
        ]));
        // A returned surrender goes back to review rather than blocking the narrower enum.
        DB::table('petty_cash_requisitions')->where('status', 'surrender_returned')->update(['status' => 'surrender_pending']);
        DB::statement("ALTER TABLE petty_cash_requisitions MODIFY COLUMN status ENUM(
            'pending', 'approved', 'rejected', 'disbursed', 'received', 'surrender_pending', 'surrendered'
        ) NOT NULL DEFAULT 'pending'");
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('transaction_classification'));
        Schema::dropIfExists('spend_voucher_reviews');
        Schema::table('spend_vouchers', fn (Blueprint $table) => $table->dropColumn([
            'review_state', 'returned_by', 'returned_at', 'return_reason', 'resubmitted_by', 'resubmitted_at',
            'rejected_by', 'rejected_at', 'rejection_reason', 'senior_approved_by', 'senior_approved_at',
        ]));
    }
};
