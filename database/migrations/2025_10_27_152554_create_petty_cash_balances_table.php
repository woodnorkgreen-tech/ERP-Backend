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
        Schema::create('petty_cash_balances', function (Blueprint $table) {
            $table->id();
            $table->decimal('current_balance', 10, 2)->default(0.00);
            $table->unsignedBigInteger('last_transaction_id')->nullable();
            $table->enum('last_transaction_type', ['top_up', 'disbursement'])->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            // Note: We don't add foreign key constraint to last_transaction_id 
            // as it could reference either top_ups or disbursements table
            // This will be handled at the application level
            
            // Index for performance
            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('petty_cash_balances');
    }
};
