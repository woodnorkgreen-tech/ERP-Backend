<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_source_id')->constrained('payment_sources')->restrictOnDelete();
            $table->decimal('received_amount', 15, 2);
            $table->date('payment_date');
            $table->string('payment_method', 32);
            $table->string('transaction_reference', 100);
            $table->string('evidence_path')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['payment_source_id', 'transaction_reference'], 'client_receipts_source_reference_unique');
        });

        Schema::table('enquiry_payments', function (Blueprint $table) {
            $table->foreignId('client_receipt_id')->nullable()->after('project_enquiry_id')
                ->constrained('client_receipts')->nullOnDelete();
            $table->index(['client_receipt_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('enquiry_payments', function (Blueprint $table) {
            $table->dropIndex(['client_receipt_id', 'status']);
            $table->dropConstrainedForeignId('client_receipt_id');
        });
        Schema::dropIfExists('client_receipts');
    }
};
