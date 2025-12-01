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
        Schema::dropIfExists('handover_surveys');

        Schema::create('handover_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('enquiry_tasks')->onDelete('cascade');
            
            // Ratings (1-5)
            $table->tinyInteger('communication_rating')->nullable();
            $table->tinyInteger('work_quality_rating')->nullable();
            $table->tinyInteger('timeliness_rating')->nullable();
            $table->tinyInteger('professionalism_rating')->nullable();
            $table->tinyInteger('overall_satisfaction_rating')->nullable();
            
            $table->text('comments')->nullable();
            
            // Status
            $table->boolean('submitted')->default(false);
            $table->timestamp('submitted_at')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('handover_surveys');
    }
};
