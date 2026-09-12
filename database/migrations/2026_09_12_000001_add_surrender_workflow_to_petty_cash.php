<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Extend the status enum on petty_cash_requisitions to include 'surrender_pending' and 'surrendered'
        DB::statement("ALTER TABLE petty_cash_requisitions MODIFY COLUMN status ENUM(
            'pending',
            'approved',
            'rejected',
            'disbursed',
            'received',
            'surrender_pending',
            'surrendered'
        ) NOT NULL DEFAULT 'pending'");

        // 2. Add surrender tracking columns to petty_cash_requisitions
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->timestamp('surrendered_at')->nullable()->after('received_at');
            $table->foreignId('surrendered_by')->nullable()->after('surrendered_at')->constrained('users')->nullOnDelete();
            $table->decimal('actual_spent_amount', 12, 2)->nullable()->after('surrendered_by');
            $table->decimal('cash_returned_amount', 12, 2)->nullable()->after('actual_spent_amount');
            $table->text('surrender_notes')->nullable()->after('cash_returned_amount');
            $table->timestamp('surrender_reconciled_at')->nullable()->after('surrender_notes');
            $table->foreignId('surrender_reconciled_by')->nullable()->after('surrender_reconciled_at')->constrained('users')->nullOnDelete();
            $table->foreignId('advance_journal_entry_id')->nullable()->after('surrender_reconciled_by')->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('surrender_journal_entry_id')->nullable()->after('advance_journal_entry_id')->constrained('journal_entries')->nullOnDelete();
        });

        // 3. Create petty_cash_surrender_items table
        Schema::create('petty_cash_surrender_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('petty_cash_requisitions')->cascadeOnDelete();
            $table->foreignId('expense_code_id')->constrained('expense_codes');
            $table->decimal('amount', 12, 2); // Gross amount of receipt
            $table->decimal('net_amount', 12, 2); // Net expense
            $table->decimal('tax_amount', 12, 2)->default(0.00); // Input VAT
            $table->enum('receipt_type', ['etr', 'non_etr', 'none'])->default('none');
            $table->string('receipt_number', 100)->nullable();
            $table->string('supplier_kra_pin', 32)->nullable();
            $table->string('supplier_name', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('receipt_path', 500)->nullable();
            $table->foreignId('cost_line_id')->nullable()->constrained('cost_lines')->nullOnDelete();
            $table->timestamps();

            $table->index('requisition_id');
            $table->index('expense_code_id');
            $table->index('receipt_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('petty_cash_surrender_items');

        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->dropForeign(['surrendered_by']);
            $table->dropForeign(['surrender_reconciled_by']);
            $table->dropForeign(['advance_journal_entry_id']);
            $table->dropForeign(['surrender_journal_entry_id']);
            $table->dropColumn([
                'surrendered_at',
                'surrendered_by',
                'actual_spent_amount',
                'cash_returned_amount',
                'surrender_notes',
                'surrender_reconciled_at',
                'surrender_reconciled_by',
                'advance_journal_entry_id',
                'surrender_journal_entry_id',
            ]);
        });

        DB::statement("ALTER TABLE petty_cash_requisitions MODIFY COLUMN status ENUM(
            'pending',
            'approved',
            'rejected',
            'disbursed',
            'received'
        ) NOT NULL DEFAULT 'pending'");
    }
};
