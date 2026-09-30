<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_labour_actuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_enquiry_id')->constrained('project_enquiries')->cascadeOnDelete();
            
            // Linkage to budget and cost collector
            $table->string('budget_line_id')->nullable()->index();
            $table->foreignId('consumes_cost_line_id')->nullable()->constrained('cost_lines')->nullOnDelete();
            $table->foreignId('cost_line_id')->nullable()->constrained('cost_lines')->nullOnDelete();

            // Labour classification and unit structure inherited from budget
            $table->string('labour_role');
            $table->string('labour_category');
            $table->string('budget_unit')->default('PAX');
            $table->decimal('unit_rate', 15, 2);

            // Actual consumption
            $table->decimal('actual_quantity', 10, 2)->nullable();
            $table->decimal('actual_days', 10, 2)->nullable()->default(1.00);
            $table->decimal('actual_hours', 10, 2)->nullable();
            $table->decimal('calculated_cost', 15, 2);
            $table->date('work_date');

            // Personnel master integration (optional reference to Employee Records)
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            // Unbudgeted & Rework tracking
            $table->boolean('is_unbudgeted')->default(false);
            $table->string('rework_type')->default('none'); // none, client_caused, internal_rework

            // Workflow status lifecycle
            // draft -> recorded -> po_verified -> finance_verified | returned_for_correction | superseded
            $table->string('status')->default('recorded')->index();

            // Stage 1: Operational Recorder
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamp('recorded_at');

            // Stage 2: Project Officer Verification
            $table->foreignId('po_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('po_verified_at')->nullable();
            $table->text('po_notes')->nullable();

            // Stage 3: Finance Verification
            $table->foreignId('finance_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finance_verified_at')->nullable();
            $table->text('finance_notes')->nullable();

            // Return for Correction
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();

            // Unbudgeted Justification
            $table->text('unbudgeted_reason')->nullable();

            // Corrections / Superseding linkage
            $table->foreignId('reversal_of_id')->nullable()->constrained('project_labour_actuals')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('project_labour_actuals')->nullOnDelete();
            $table->text('correction_reason')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_labour_actuals');
    }
};
