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
        Schema::create('hr_candidate_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('hr_candidates')->cascadeOnDelete();
            
            $table->string('employer');
            $table->string('job_title');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('industry')->nullable();
            $table->string('employment_type')->nullable(); // Full-time, Internship, etc.
            $table->longText('responsibilities')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_candidate_experiences');
    }
};
