<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('finance_task_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->unique()->constrained('tasks')->onDelete('cascade');
            
            // Finance-specific fields
            $table->string('transaction_type')->nullable(); // e.g., 'payment', 'invoice', 'expense', 'reimbursement'
            $table->string('budget_code')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('payment_status')->default('pending'); // 'pending', 'approved', 'paid', 'rejected', 'cancelled'
            $table->string('payment_method')->nullable(); // e.g., 'bank_transfer', 'check', 'cash', 'credit_card'
            $table->string('vendor_name')->nullable();
            $table->string('vendor_account_number')->nullable();
            $table->string('invoice_number')->nullable();
            $table->dateTime('invoice_date')->nullable();
            $table->dateTime('due_date')->nullable();
            $table->dateTime('payment_date')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('payment_description')->nullable();
            $table->json('line_items')->nullable(); // Array of itemized charges
            $table->decimal('tax_amount', 15, 2)->nullable();
            $table->decimal('discount_amount', 15, 2)->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->string('approval_status')->default('pending'); // 'pending', 'approved', 'rejected'
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->dateTime('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->json('supporting_documents')->nullable(); // Array of document file paths
            $table->string('account_code')->nullable(); // General ledger account code
            $table->string('cost_center')->nullable();
            $table->string('project_code')->nullable();
            $table->boolean('requires_receipt')->default(true);
            $table->string('receipt_path')->nullable();
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('transaction_type');
            $table->index('payment_status');
            $table->index('approval_status');
            $table->index('budget_code');
            $table->index('invoice_number');
            $table->index('due_date');
            $table->index('approved_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_task_contexts');
    }
};
