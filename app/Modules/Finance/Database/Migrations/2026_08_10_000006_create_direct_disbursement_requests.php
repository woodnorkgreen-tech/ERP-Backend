<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_disbursement_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->enum('status', ['pending_approval', 'processing', 'approved', 'rejected'])->default('pending_approval')->index();
            $table->json('payload');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('disbursement_id')->nullable()->constrained('petty_cash_disbursements')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_disbursement_requests');
    }
};
