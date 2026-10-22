<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report 76A: the durable record behind every cost-chain posting that used to
 * be a queued listener.
 *
 * One row per (posting type, subject): the purchase order whose commitment is
 * owed, the goods receipt whose accrual is owed, the payment whose cost or
 * reversal is owed. It is written in the business transaction, so it exists
 * exactly when the business event does, and it carries the outcome — which is
 * what makes a posting that did not happen something a person can see and
 * retry, rather than a job that never ran on a worker nobody started.
 *
 * `subject_id` is deliberately not a foreign key: the subject is a different
 * table per type, and the controlled Finance reset must never be blocked by
 * this log.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('finance_event_postings', function (Blueprint $t) {
            $t->id();
            $t->string('posting_type', 48);
            $t->unsignedBigInteger('subject_id');
            $t->json('payload')->nullable();
            // pending → processing → posted | failed
            $t->string('status', 16)->default('pending');
            $t->string('outcome', 191)->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('requested_at')->nullable();
            $t->timestamp('last_attempt_at')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->foreignId('last_retried_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['posting_type', 'subject_id'], 'fin_event_posting_unique');
            $t->index(['status', 'updated_at'], 'fin_event_posting_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_event_postings');
    }
};
