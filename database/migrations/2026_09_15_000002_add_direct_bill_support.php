<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "direct bill" — a supplier invoice with no purchase order behind it.
 *
 * Until now, `bills.purchase_order_id` being required meant the only way to
 * record a credit purchase that never went through Requisition→PO→GRN was a
 * Cost Collector cost line with `funding_mode=unpaid_invoice` — a second,
 * separate shape for "we owe a supplier," with weaker tax capture than a real
 * Bill and settled through a different controller entirely
 * (SpendVoucherController rather than BillController). This makes the Bill
 * rail itself able to record one, so every supplier obligation — ordered or
 * not — ends up in one table, taxed and paid the same way.
 *
 * A PO-backed bill's cost attribution and expense classification come from
 * its purchase order's requisition; a direct bill has neither, so it carries
 * its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_id')->nullable()->change();

            $table->foreignId('expense_code_id')->nullable()->after('purchase_order_id')
                ->constrained('expense_codes')->nullOnDelete()
                ->comment('Direct bills only — a PO-backed bill classifies through its purchase order.');
            $table->foreignId('project_id')->nullable()->after('expense_code_id')
                ->constrained('projects')->nullOnDelete();
            $table->foreignId('project_enquiry_id')->nullable()->after('project_id')
                ->constrained('project_enquiries')->nullOnDelete();
            $table->string('job_number')->nullable()->after('project_enquiry_id');
            $table->foreignId('department_id')->nullable()->after('job_number')
                ->constrained('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_code_id');
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('project_enquiry_id');
            $table->dropColumn('job_number');
            $table->dropConstrainedForeignId('department_id');
            $table->unsignedBigInteger('purchase_order_id')->nullable(false)->change();
        });
    }
};
