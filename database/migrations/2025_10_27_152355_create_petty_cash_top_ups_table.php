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
        Schema::create('petty_cash_top_ups', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 10, 2);
            $table->enum('payment_method', ['cash', 'mpesa', 'bank_transfer', 'other']);
            $table->string('transaction_code')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            
            // Indexes for performance optimization
            $table->index('created_at');
            $table->index('payment_method');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('petty_cash_top_ups');
    }
};
