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
        Schema::create('hr_candidate_education', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('hr_candidates')->cascadeOnDelete();
            
            $table->string('institution');
            $table->string('level_of_study'); // High School, Diploma, Degree, etc.
            $table->string('field_of_study')->nullable();
            $table->string('grade')->nullable(); // KCSE grade, University Class
            $table->year('graduation_year')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_candidate_education');
    }
};
