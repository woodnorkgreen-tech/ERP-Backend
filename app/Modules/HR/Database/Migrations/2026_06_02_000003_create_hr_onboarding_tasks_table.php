<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('onboarding_case_id');
            $table->unsignedBigInteger('card_id');
            $table->string('task_code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('phase')->nullable();
            $table->string('assignee_role')->nullable();
            $table->unsignedBigInteger('assignee_user_id')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_active')->default(true);
            $table->enum('status', ['pending', 'in_progress', 'completed', 'overdue', 'skipped'])->default('pending');
            $table->unsignedTinyInteger('sequence_order')->default(0);
            $table->string('depends_on_task_code')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('onboarding_case_id');
            $table->index('card_id');
            $table->index(['onboarding_case_id', 'task_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding_tasks');
    }
};
