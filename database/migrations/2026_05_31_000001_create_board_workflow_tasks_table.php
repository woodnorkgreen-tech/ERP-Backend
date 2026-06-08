<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_workflow_tasks', function (Blueprint $table) {
            $table->id();

            // Context — what job/request this task belongs to
            $table->string('job_ref')->index();
            $table->foreignId('board_request_id')->nullable()->constrained('board_requests')->onDelete('set null');

            // What needs to be done and by whom
            $table->string('task_type'); // request_raised | boards_to_dispatch | boards_to_deliver | offcut_to_return
            $table->string('assigned_role'); // Stores | Logistics | Production
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->onDelete('set null');

            // Status
            $table->enum('status', ['pending', 'in_progress', 'done', 'skipped'])->default('pending')->index();

            // Audit trail — links this task to the one that triggered it
            $table->foreignId('triggered_by_task_id')->nullable()->constrained('board_workflow_tasks')->onDelete('set null');

            // Flexible payload (board IDs, counts, custom notes)
            $table->json('payload')->nullable();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();

            $table->index(['task_type', 'status']);
            $table->index(['assigned_role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_workflow_tasks');
    }
};
