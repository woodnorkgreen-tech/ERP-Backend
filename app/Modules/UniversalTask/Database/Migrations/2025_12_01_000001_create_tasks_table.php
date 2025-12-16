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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            
            // Core task attributes
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('task_type')->nullable();
            $table->enum('status', [
                'pending', 
                'in_progress', 
                'blocked', 
                'review', 
                'completed', 
                'cancelled', 
                'overdue'
            ])->default('pending');
            $table->enum('priority', ['low', 'medium', 'high', 'critical', 'urgent'])->default('medium');
            
            // Hierarchical relationship
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->onDelete('cascade');
            
            // Polymorphic association to any entity
            $table->string('taskable_type')->nullable();
            $table->unsignedBigInteger('taskable_id')->nullable();
            
            // Department and user assignments
            $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            
            // Time tracking
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->decimal('actual_hours', 8, 2)->nullable()->default(0);
            
            // Dates
            $table->timestamp('due_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            // Blocking information
            $table->text('blocked_reason')->nullable();
            
            // Flexible data storage
            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();
            
            // Completion tracking
            $table->decimal('completion_percentage', 5, 2)->default(0);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for frequently queried fields
            $table->index('status');
            $table->index('priority');
            $table->index('parent_task_id');
            $table->index(['taskable_type', 'taskable_id']);
            $table->index('department_id');
            $table->index('assigned_user_id');
            $table->index('due_date');
            $table->index('created_at');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
