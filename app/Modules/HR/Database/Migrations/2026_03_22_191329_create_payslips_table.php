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
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->string('payroll_month'); // YYYY-MM
            $table->decimal('basic_salary', 15, 2);
            $table->decimal('gross_pay', 15, 2);
            $table->decimal('net_pay', 15, 2);
            $table->json('tax_breakdown'); // PAYE, SHIF, NSSF, Housing Levy
            $table->json('ledger_breakdown'); // Custom additions/deductions
            $table->string('status')->default('draft'); // draft, published, paid
            $table->date('payment_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
