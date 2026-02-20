<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('production_ncrs')) {
            Schema::create('production_ncrs', function (Blueprint $table) {
                $table->id();
                $table->string('ncr_number', 80)->unique();
                $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
                $table->foreignId('work_order_rework_id')->nullable()->constrained('work_order_reworks')->nullOnDelete();
                $table->foreignId('defect_code_id')->nullable()->constrained('production_defect_codes')->nullOnDelete();
                $table->foreignId('root_cause_code_id')->nullable()->constrained('production_root_cause_codes')->nullOnDelete();
                $table->enum('source_type', ['mid_qc', 'final_qc', 'rework_qc', 'manual', 'other'])->default('manual');
                $table->string('source_ref', 160)->nullable();
                $table->string('qc_stage', 50)->nullable();
                $table->string('workstation', 120)->nullable();
                $table->enum('severity', ['minor', 'major', 'critical'])->default('minor');
                $table->enum('status', ['open', 'assigned', 'in_progress', 'pending_reinspection', 'closed', 'cancelled'])->default('open');
                $table->text('description');
                $table->text('containment_action')->nullable();
                $table->text('corrective_action')->nullable();
                $table->date('due_date')->nullable();
                $table->timestamp('detected_at')->nullable();
                $table->foreignId('detected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('is_concession_approved')->default(false);
                $table->text('concession_reason')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['work_order_id', 'status']);
                $table->index(['status', 'severity']);
                $table->index('due_date');
                $table->index('detected_at');
            });
        }

        if (!Schema::hasTable('production_ncr_events')) {
            Schema::create('production_ncr_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ncr_id')->constrained('production_ncrs')->cascadeOnDelete();
                $table->enum('event_type', [
                    'created',
                    'status_changed',
                    'assignment_added',
                    'note_added',
                    'reinspection_requested',
                    'reinspection_passed',
                    'reinspection_failed',
                    'closed',
                    'reopened',
                    'escalated'
                ]);
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40)->nullable();
                $table->text('note')->nullable();
                $table->json('meta')->nullable();
                $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('performed_at')->nullable();
                $table->timestamps();

                $table->index(['ncr_id', 'event_type']);
            });
        }

        if (!Schema::hasTable('production_ncr_assignments')) {
            Schema::create('production_ncr_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ncr_id')->constrained('production_ncrs')->cascadeOnDelete();
                $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('assigned_department', 120)->nullable();
                $table->string('assigned_workstation', 120)->nullable();
                $table->string('assignment_role', 80)->nullable();
                $table->text('notes')->nullable();
                $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');
                $table->date('due_date')->nullable();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['ncr_id', 'status']);
            });
        }

        if (!Schema::hasTable('production_ncr_closures')) {
            Schema::create('production_ncr_closures', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ncr_id')->unique()->constrained('production_ncrs')->cascadeOnDelete();
                $table->enum('closure_type', ['permanent_fix', 'concession', 'containment_only'])->default('permanent_fix');
                $table->enum('verification_result', ['passed', 'failed', 'conditional'])->default('passed');
                $table->text('closure_summary');
                $table->text('effectiveness_note')->nullable();
                $table->boolean('effectiveness_review_required')->default(false);
                $table->date('effectiveness_review_date')->nullable();
                $table->text('lessons_learned')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('production_ncr_closures');
        Schema::dropIfExists('production_ncr_assignments');
        Schema::dropIfExists('production_ncr_events');
        Schema::dropIfExists('production_ncrs');
    }
};
