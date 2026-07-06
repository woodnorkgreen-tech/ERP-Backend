<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_offboarding_final_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('offboarding_case_id')->unique();
            $table->decimal('accrued_leave_days', 6, 2)->nullable();
            $table->decimal('leave_payout_amount', 12, 2)->nullable();
            $table->decimal('outstanding_salary', 12, 2)->nullable();
            $table->decimal('other_dues', 12, 2)->nullable();
            $table->decimal('deductions', 12, 2)->nullable();
            $table->decimal('net_amount', 12, 2)->nullable();
            $table->enum('status', ['pending', 'calculated', 'approved', 'paid'])->default('pending');
            $table->unsignedBigInteger('calculated_by')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offboarding_final_settlements');
    }
};
