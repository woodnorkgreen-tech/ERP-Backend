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
        Schema::create('task_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('issue_type', ['bug', 'feature_request', 'improvement', 'question', 'security', 'performance', 'documentation', 'enhancement', 'support', 'incident', 'change_request', 'maintenance', 'training', 'compliance', 'blocker', 'other'])->default('bug');
            $table->enum('severity', ['critical', 'high', 'medium', 'low'])->default('medium');
            $table->enum('status', ['open', 'in_progress', 'resolved', 'closed'])->default('open');
            $table->foreignId('reported_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reported_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            // Indexes for frequently queried fields
            $table->index('task_id');
            $table->index('issue_type');
            $table->index('severity');
            $table->index('status');
            $table->index('reported_by');
            $table->index('assigned_to');
            $table->index('reported_at');
            $table->index(['task_id', 'status']);
            $table->index(['severity', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_issues');
    }
};
