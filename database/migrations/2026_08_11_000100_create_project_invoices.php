<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('project_invoices', function (Blueprint $table) {
            $table->id(); $table->string('invoice_number',40)->unique();
            $table->foreignId('project_enquiry_id')->constrained('project_enquiries')->restrictOnDelete();
            $table->date('invoice_date'); $table->date('due_date')->index();
            $table->decimal('subtotal',15,2); $table->decimal('tax_amount',15,2)->default(0); $table->decimal('total_amount',15,2);
            $table->enum('status',['draft','issued','paid','void'])->default('draft')->index(); $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('issued_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('voided_at')->nullable(); $table->text('void_reason')->nullable();
            $table->timestamps(); $table->index(['project_enquiry_id','status']);
        });
        Schema::create('project_invoice_allocations', function (Blueprint $table) {
            $table->id(); $table->foreignId('project_invoice_id')->constrained('project_invoices')->restrictOnDelete();
            $table->foreignId('enquiry_payment_id')->constrained('enquiry_payments')->restrictOnDelete();
            $table->decimal('amount',15,2); $table->foreignId('allocated_by')->constrained('users')->restrictOnDelete(); $table->timestamps();
            $table->unique(['project_invoice_id','enquiry_payment_id'], 'invoice_payment_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('project_invoice_allocations'); Schema::dropIfExists('project_invoices'); }
};
