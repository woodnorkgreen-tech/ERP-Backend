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
        Schema::create('task_budget_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_task_id')->constrained('enquiry_tasks')->onDelete('cascade');
            $table->json('project_info');
            $table->json('materials_data');
            $table->json('labour_data')->nullable();
            $table->json('expenses_data')->nullable();
            $table->json('logistics_data')->nullable();
            $table->json('budget_summary');
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'rejected'])->default('draft');
            $table->timestamps();

            $table->unique('enquiry_task_id', 'unique_task_budget');
        });

        Schema::create('budget_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_budget_data_id')->constrained('task_budget_data')->onDelete('cascade');
            $table->foreignId('approved_by')->constrained('users');
            $table->enum('status', ['approved', 'rejected']);
            $table->text('comments')->nullable();
            $table->timestamp('approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_budget_data');
    }
};
