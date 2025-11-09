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
        Schema::create('budget_additions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_budget_data_id')->constrained('task_budget_data')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('materials')->nullable();
            $table->json('labour')->nullable();
            $table->json('expenses')->nullable();
            $table->json('logistics')->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'rejected'])->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->timestamps();

            $table->index(['task_budget_data_id', 'status']);
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_additions');
    }
};
