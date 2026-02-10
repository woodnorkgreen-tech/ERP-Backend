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
        Schema::create('petty_cash_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('requisition_number')->unique();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('department_id')->constrained('departments')->onDelete('cascade');
            $table->string('category'); // e.g., Travel, Subsistence, Office Supplies, Maintenance, etc.
            $table->text('purpose');
            $table->decimal('total_amount', 12, 2);
            $table->enum('status', ['pending', 'approved', 'rejected', 'disbursed', 'received'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            
            // For closure/receipt confirmation
            $table->longText('digital_signature')->nullable(); // Base64 signature
            $table->timestamp('received_at')->nullable();
            
            $table->timestamps();

            $table->index('status');
            $table->index('user_id');
            $table->index('department_id');
            $table->index('requisition_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('petty_cash_requisitions');
    }
};
