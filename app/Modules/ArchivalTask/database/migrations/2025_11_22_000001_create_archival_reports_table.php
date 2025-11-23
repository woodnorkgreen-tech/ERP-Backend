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
        Schema::create('archival_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_task_id')->constrained('enquiry_tasks')->onDelete('cascade');
            
            // Section 1: Project Information
            $table->string('client_name')->nullable();
            $table->string('project_code', 100)->nullable();
            $table->string('project_officer')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('site_location')->nullable();
            
            // Section 2: Project Scope Summary
            $table->text('project_scope')->nullable();
            
            // Section 3: Procurement & Inventory
            $table->boolean('materials_mrf_attached')->default(false);
            $table->text('items_sourced_externally')->nullable();
            $table->text('procurement_challenges')->nullable();
            
            // Section 4: Fabrication & Quality Control
            $table->date('production_start_date')->nullable();
            $table->string('packaging_labeling_status', 100)->nullable();
            $table->text('materials_used_in_production')->nullable();
            
            // Section 5.1: Team Allocation
            $table->string('team_captain')->nullable();
            $table->text('setup_team_assigned')->nullable();
            $table->text('branding_team_assigned')->nullable();
            
            // Section 5.3: Setup Schedule
            $table->boolean('all_deliverables_available')->nullable();
            $table->boolean('setup_aligned_to_schedule')->nullable();
            $table->boolean('delays_occurred')->nullable();
            $table->text('delay_reasons')->nullable();
            $table->boolean('deliverables_checked')->nullable();
            $table->enum('site_organization', ['excellent', 'good', 'fair', 'poor'])->nullable();
            $table->enum('cleanliness_rating', ['excellent', 'good', 'fair', 'poor'])->nullable();
            
            // Section 5.5 & 5.6: General Findings
            $table->text('general_findings')->nullable();
            $table->text('site_readiness_notes')->nullable();
            
            // Section 6: Client Handover
            $table->date('handover_date')->nullable();
            $table->string('client_rating', 50)->nullable();
            $table->text('client_remarks')->nullable();
            $table->enum('print_clarity_rating', ['good', 'fair', 'poor', 'n/a'])->nullable();
            $table->enum('printworks_accuracy_rating', ['good', 'fair', 'poor', 'n/a'])->nullable();
            $table->text('installation_precision_comments')->nullable();
            
            // Section 6.4: Work Process Efficiency
            $table->enum('setup_speed_flow', ['good', 'fair', 'poor'])->nullable();
            $table->enum('team_coordination', ['good', 'fair', 'poor'])->nullable();
            $table->text('efficiency_remarks')->nullable();
            
            // Section 6.5: Client Experience
            $table->boolean('client_kept_informed')->nullable();
            $table->enum('client_satisfaction', ['satisfied', 'unsatisfied', 'n/a'])->nullable();
            $table->text('communication_comments')->nullable();
            
            // Section 6.6: Delivery & Logistics
            $table->boolean('delivered_on_schedule')->nullable();
            $table->enum('delivery_condition', ['good', 'fair', 'poor'])->nullable();
            $table->boolean('delivery_issues')->nullable();
            $table->text('delivery_notes')->nullable();
            
            // Section 6.7: Execution & Professionalism
            $table->enum('team_professionalism', ['good', 'fair', 'poor'])->nullable();
            $table->boolean('client_confidence')->nullable();
            $table->text('professionalism_feedback')->nullable();
            
            // Section 6.8: Final Summary
            $table->text('recommendations_action_points')->nullable();
            
            // Section 7: Set-Down & Debrief
            $table->date('setdown_date')->nullable();
            $table->text('items_condition_returned')->nullable();
            $table->string('site_clearance_status', 100)->nullable();
            $table->text('outstanding_items')->nullable();
            
            // Section 8: Attachments (stored as JSON array of file references)
            $table->json('attachments')->nullable();
            
            // Section 9: Confirmation & Signatures
            $table->string('project_officer_signature')->nullable();
            $table->date('project_officer_sign_date')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->date('reviewer_sign_date')->nullable();
            
            // Status tracking
            $table->enum('status', ['draft', 'submitted', 'approved'])->default('draft');
            
            // Audit fields
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            
            // Indexes for performance
            $table->index('enquiry_task_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archival_reports');
    }
};
