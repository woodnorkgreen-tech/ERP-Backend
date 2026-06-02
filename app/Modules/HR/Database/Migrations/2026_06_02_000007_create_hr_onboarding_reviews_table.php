<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('onboarding_case_id');
            $table->enum('review_type', ['two_week', 'one_month']);
            $table->date('scheduled_date')->nullable();
            $table->date('conducted_date')->nullable();
            $table->unsignedBigInteger('conducted_by')->nullable();
            $table->unsignedTinyInteger('performance_rating')->nullable();
            $table->text('feedback')->nullable();
            $table->text('improvement_notes')->nullable();
            $table->text('employee_feedback')->nullable();
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled');
            $table->timestamps();

            $table->index('onboarding_case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding_reviews');
    }
};
