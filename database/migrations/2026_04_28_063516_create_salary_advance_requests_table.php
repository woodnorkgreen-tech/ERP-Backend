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
        Schema::create('salary_advance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->string('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'disbursed'])->default('pending');
            $table->text('hr_remarks')->nullable();
            $table->string('target_payroll_month'); // YYYY-MM
            $table->foreignId('ledger_id')->nullable()->constrained('payroll_ledgers')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_advance_requests');
    }
};
