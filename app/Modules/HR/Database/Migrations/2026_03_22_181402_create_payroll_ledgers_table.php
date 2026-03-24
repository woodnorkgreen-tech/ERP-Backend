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
        Schema::create('payroll_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('ledger_month'); // Format: YYYY-MM
            $table->enum('type', ['addition', 'deduction']);
            $table->enum('amount_type', ['fixed', 'percentage_of_basic']);
            $table->decimal('amount_value', 12, 2);
            $table->string('name'); // e.g., 'Performance Bonus', 'Salary Advance'
            $table->text('description')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->timestamps();
            
            $table->index(['employee_id', 'ledger_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_ledgers');
    }
};
