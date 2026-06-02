<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('job_posting_id')->nullable();
            $table->enum('started_from', ['recruitment', 'manual'])->default('recruitment');
            $table->enum('status', [
                'pending_start',
                'pre_onboarding',
                'day_1',
                'active_onboarding',
                'hr_approval_pending',
                'hr_handover_completed',
                'post_onboarding_review',
                'completed',
                'cancelled',
            ])->default('pending_start');
            $table->date('start_date')->nullable();
            $table->unsignedBigInteger('hr_owner_id')->nullable();
            $table->unsignedBigInteger('line_manager_id')->nullable();
            $table->enum('employment_type', ['full-time', 'part-time', 'contract', 'intern'])->default('full-time');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('position')->nullable();
            $table->decimal('overall_progress', 5, 2)->default(0);
            $table->timestamp('hr_approved_at')->nullable();
            $table->unsignedBigInteger('hr_approved_by')->nullable();
            $table->timestamp('it_unlocked_at')->nullable();
            $table->timestamp('sops_unlocked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('candidate_id');
            $table->index('employee_id');
            $table->index('status');
            $table->index('hr_owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding_cases');
    }
};
