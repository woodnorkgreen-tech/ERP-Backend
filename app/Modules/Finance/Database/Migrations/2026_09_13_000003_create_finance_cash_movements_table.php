<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_source_id')->constrained('payment_sources')->restrictOnDelete();
            $table->date('transaction_date');
            $table->enum('direction', ['in', 'out']);
            $table->string('transaction_type');
            $table->string('reference')->nullable();
            $table->text('description');
            $table->string('counterparty')->nullable();
            $table->decimal('amount', 18, 2);
            $table->foreignId('offset_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->enum('status', ['posted', 'voided'])->default('posted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['payment_source_id', 'transaction_date']);
            $table->index(['payment_source_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cash_movements');
    }
};