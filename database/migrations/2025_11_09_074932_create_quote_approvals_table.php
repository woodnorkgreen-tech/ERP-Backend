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
        Schema::create('quote_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('enquiry_id');
            $table->enum('approval_status', ['approved', 'rejected', 'pending']);
            $table->string('approved_by');
            $table->date('approval_date');
            $table->text('rejection_reason')->nullable();
            $table->text('comments')->nullable();
            $table->decimal('quote_amount', 15, 2);
            $table->json('quote_data');
            $table->timestamps();

            $table->index(['task_id'], 'idx_quote_approvals_task');
            $table->index(['enquiry_id'], 'idx_quote_approvals_enquiry');
            $table->index(['approval_status'], 'idx_quote_approvals_status');
            $table->index(['approved_by'], 'idx_quote_approvals_user');
            $table->index(['approval_date'], 'idx_quote_approvals_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_approvals');
    }
};
