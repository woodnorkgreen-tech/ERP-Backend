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
        Schema::create('workflow_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->onDelete('cascade');
            $table->foreignId('workflow_template_task_id')->constrained('workflow_template_tasks')->onDelete('cascade');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'skipped', 'overdue'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['workflow_instance_id']);
            $table->index(['assigned_user_id']);
            $table->index(['status']);
            $table->index(['due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_tasks');
    }
};
