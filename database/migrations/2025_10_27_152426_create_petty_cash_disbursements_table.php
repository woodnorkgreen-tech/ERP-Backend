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
        Schema::create('petty_cash_disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('top_up_id')->constrained('petty_cash_top_ups');
            $table->string('receiver');
            $table->string('account');
            $table->decimal('amount', 10, 2);
            $table->text('description');
            $table->string('project_name')->nullable();
            $table->enum('classification', ['agencies', 'admin', 'operations', 'other']);
            $table->string('job_number')->nullable();
            $table->enum('payment_method', ['cash', 'mpesa', 'bank_transfer', 'other']);
            $table->string('transaction_code')->nullable();
            $table->enum('status', ['active', 'voided'])->default('active');
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            
            // Indexes for performance optimization
            $table->index('top_up_id');
            $table->index('created_at');
            $table->index('status');
            $table->index('classification');
            $table->index('payment_method');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('petty_cash_disbursements');
    }
};
