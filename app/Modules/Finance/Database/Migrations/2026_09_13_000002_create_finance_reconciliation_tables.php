<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_reconciliation_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_source_id')->constrained('payment_sources')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_balance', 18, 2);
            $table->decimal('closing_balance', 18, 2);
            $table->char('currency', 3)->default('KES');
            $table->enum('status', ['draft', 'reconciled', 'reopened'])->default('draft');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();

            $table->unique(['payment_source_id', 'period_start', 'period_end'], 'finance_recon_source_period_unique');
            $table->index(['payment_source_id', 'status'], 'finance_recon_source_status_index');
        });

        Schema::create('finance_statement_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_id')->constrained('finance_reconciliation_statements')->cascadeOnDelete();
            $table->date('transaction_date');
            $table->string('external_reference')->nullable();
            $table->text('description')->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->decimal('statement_balance', 18, 2)->nullable();
            $table->string('fingerprint', 64);
            $table->enum('match_status', ['unmatched', 'matched', 'ignored'])->default('unmatched');
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['statement_id', 'fingerprint'], 'finance_statement_fingerprint_unique');
            $table->index(['statement_id', 'match_status'], 'finance_statement_status_index');
            $table->index(['transaction_date', 'external_reference'], 'finance_statement_date_ref_index');
        });

        Schema::create('finance_statement_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_transaction_id')->constrained('finance_statement_transactions')->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->enum('match_type', ['automatic', 'manual', 'adjustment']);
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('statement_transaction_id', 'finance_match_transaction_index');
            $table->index(['journal_entry_id', 'payment_id'], 'finance_match_journal_payment_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_statement_matches');
        Schema::dropIfExists('finance_statement_transactions');
        Schema::dropIfExists('finance_reconciliation_statements');
    }
};