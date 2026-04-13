<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('hr_candidates')->cascadeOnDelete();
            $table->foreignId('job_posting_id')->constrained('hr_job_postings')->cascadeOnDelete();
            $table->string('interview_type'); // Phone Screen, Video Call, In-Person, Technical, Panel
            $table->dateTime('scheduled_at');
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->string('location')->nullable();
            $table->string('meeting_link')->nullable();
            $table->json('interviewer_ids')->nullable(); // array of user IDs
            $table->string('status')->default('Scheduled'); // Scheduled, Completed, Cancelled, No-Show
            $table->text('notes')->nullable();
            $table->text('feedback')->nullable();
            $table->string('outcome')->nullable(); // Pass, Fail, Hold
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_interviews');
    }
};
