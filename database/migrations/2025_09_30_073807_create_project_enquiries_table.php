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
        Schema::create('project_enquiries', function (Blueprint $table) {
            $table->id();
            $table->date('date_received');
            $table->date('expected_delivery_date')->nullable();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('project_scope')->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', [
                'client_registered',
                'enquiry_logged',
                'site_survey_completed',
                'design_completed',
                'design_approved',
                'materials_specified',
                'budget_created',
                'quote_prepared',
                'quote_approved',
                'converted_to_project',
                'planning',
                'in_progress',
                'completed',
                'cancelled'
            ])->default('client_registered');
            $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->string('assigned_department')->nullable();
            $table->decimal('estimated_budget', 15, 2)->nullable();
            $table->text('project_deliverables')->nullable();
            $table->string('contact_person');
            $table->string('assigned_po')->nullable();
            $table->text('follow_up_notes')->nullable();
            $table->string('enquiry_number')->unique();
            $table->unsignedBigInteger('converted_to_project_id')->nullable();
            $table->string('venue')->nullable();
            $table->boolean('site_survey_skipped')->default(false);
            $table->text('site_survey_skip_reason')->nullable();
            $table->boolean('quote_approved')->default(false);
            $table->timestamp('quote_approved_at')->nullable();
            $table->foreignId('quote_approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');

            // Project fields
            $table->string('project_id')->nullable()->unique();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('budget', 15, 2)->nullable();
            $table->integer('current_phase')->default(0);
            $table->json('assigned_users')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['client_id']);
            $table->index(['department_id']);
            $table->index(['status']);
            $table->index(['priority']);
            $table->index(['project_id']);
            $table->index(['enquiry_number']);
            $table->index(['created_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_enquiries');
    }
};
