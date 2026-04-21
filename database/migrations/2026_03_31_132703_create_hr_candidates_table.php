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
        Schema::create('hr_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained('hr_job_postings')->cascadeOnDelete();
            
            // Bio-Data
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('email');
            $table->string('phone');
            $table->string('gender')->nullable();
            $table->date('dob')->nullable();
            $table->string('nationality')->default('Kenyan');
            $table->string('county_of_residence')->nullable();
            
            // Identification
            $table->string('id_type')->nullable(); // National ID, Passport, Alien Card
            $table->string('id_number')->nullable();
            $table->string('kra_pin')->nullable();
            $table->string('marital_status')->nullable();
            
            // ATS Workflow
            $table->enum('status', ['New', 'Shortlisted', 'Interviewing', 'Offered', 'Hired', 'Rejected'])->default('New');
            $table->integer('rating')->nullable();
            $table->longText('notes')->nullable();
            
            // Tab 4: Skills & Certs stored as JSON for flexibility
            $table->json('skills')->nullable();
            $table->json('software_proficiency')->nullable();
            $table->json('certifications')->nullable();
            
            // Tab 5: Job Specific answers JSON
            $table->json('questionnaire_responses')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_candidates');
    }
};
