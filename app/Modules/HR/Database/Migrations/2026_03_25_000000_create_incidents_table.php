<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            
            // Basic incident information
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location');
            $table->dateTime('incident_datetime');
            $table->enum('severity', ['Low', 'Medium', 'High', 'Critical'])->default('Medium');
            $table->enum('status', ['Open', 'In Progress', 'Under Review', 'Resolved', 'Closed'])->default('Open');
            
            // Incident categories (JSON for multiple selections)
            $table->json('incident_types')->nullable();
            $table->string('classification_category')->nullable();
            $table->string('classification_subcategory')->nullable();
            $table->text('classification_other_details')->nullable();
            
            // Actions and evidence
            $table->text('immediate_actions_taken')->nullable();
            $table->text('witnesses')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('corrective_actions')->nullable();
            $table->text('preventive_measures')->nullable();
            $table->text('additional_notes')->nullable();
            
            // Attachment paths (JSON for multiple files)
            $table->json('evidence_paths')->nullable();
            
            // Reporter information
            $table->string('reporter_name')->nullable();
            $table->string('reporter_email')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->string('job_title')->nullable();
            $table->string('contact_info')->nullable();
            
            // Review fields
            $table->text('short_term_fixes')->nullable();
            $table->text('long_term_measures')->nullable();
            $table->string('responsible_party')->nullable();
            $table->text('impact_analysis')->nullable();
            $table->text('avoid_recurrence')->nullable();
            $table->text('policy_changes')->nullable();
            $table->text('training_needs')->nullable();
            $table->text('review_notes')->nullable();
            
            // Review tracking
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->dateTime('approved_at')->nullable();
            
            // System fields
            $table->dateTime('date_reported');
            $table->timestamps();
            
            // Indexes
            $table->index('reported_by');
            $table->index('status');
            $table->index('severity');
            $table->index('department_id');
        });
        
        // Create incident comments table
        Schema::create('incident_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('set null');
            $table->text('comment');
            $table->timestamps();
            
            $table->index('incident_id');
        });
        
        // Create incident activity logs table
        Schema::create('incident_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('set null');
            $table->string('action');
            $table->text('details')->nullable();
            $table->timestamps();
            
            $table->index('incident_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_activity_logs');
        Schema::dropIfExists('incident_comments');
        Schema::dropIfExists('incidents');
    }
};

