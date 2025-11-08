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
        Schema::table('enquiry_tasks', function (Blueprint $table) {
            // Add departmental task fields (skip department_id as it already exists)
            if (!Schema::hasColumn('enquiry_tasks', 'task_description')) {
                $table->string('task_description')->nullable();
            }
            if (!Schema::hasColumn('enquiry_tasks', 'assigned_user_id')) {
                $table->foreignId('assigned_user_id')->nullable()->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('enquiry_tasks', 'priority')) {
                $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            }
            if (!Schema::hasColumn('enquiry_tasks', 'estimated_hours')) {
                $table->decimal('estimated_hours', 8, 2)->nullable();
            }
            if (!Schema::hasColumn('enquiry_tasks', 'actual_hours')) {
                $table->decimal('actual_hours', 8, 2)->nullable();
            }
            if (!Schema::hasColumn('enquiry_tasks', 'due_date')) {
                $table->date('due_date')->nullable();
            }
            if (!Schema::hasColumn('enquiry_tasks', 'started_at')) {
                $table->timestamp('started_at')->nullable();
            }
            if (!Schema::hasColumn('enquiry_tasks', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }
            if (!Schema::hasColumn('enquiry_tasks', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (!Schema::hasColumn('enquiry_tasks', 'notes')) {
                $table->text('notes')->nullable();
            }
            if (!Schema::hasColumn('enquiry_tasks', 'task_order')) {
                $table->integer('task_order')->default(0);
            }

            // Indexes
            $table->index(['assigned_user_id']);
            $table->index(['priority']);
            $table->index(['due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enquiry_tasks', function (Blueprint $table) {
            // Drop added columns (skip department_id as it was added in a different migration)
            if (Schema::hasColumn('enquiry_tasks', 'task_description')) {
                $table->dropColumn('task_description');
            }
            if (Schema::hasColumn('enquiry_tasks', 'assigned_user_id')) {
                $table->dropForeign(['assigned_user_id']);
                $table->dropColumn('assigned_user_id');
            }
            if (Schema::hasColumn('enquiry_tasks', 'priority')) {
                $table->dropColumn('priority');
            }
            if (Schema::hasColumn('enquiry_tasks', 'estimated_hours')) {
                $table->dropColumn('estimated_hours');
            }
            if (Schema::hasColumn('enquiry_tasks', 'actual_hours')) {
                $table->dropColumn('actual_hours');
            }
            if (Schema::hasColumn('enquiry_tasks', 'due_date')) {
                $table->dropColumn('due_date');
            }
            if (Schema::hasColumn('enquiry_tasks', 'started_at')) {
                $table->dropColumn('started_at');
            }
            if (Schema::hasColumn('enquiry_tasks', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
            if (Schema::hasColumn('enquiry_tasks', 'submitted_at')) {
                $table->dropColumn('submitted_at');
            }
            if (Schema::hasColumn('enquiry_tasks', 'notes')) {
                $table->dropColumn('notes');
            }
            if (Schema::hasColumn('enquiry_tasks', 'task_order')) {
                $table->dropColumn('task_order');
            }
        });
    }
};
