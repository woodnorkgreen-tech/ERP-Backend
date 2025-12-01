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
        // Drop old table completely (fresh start)
        Schema::dropIfExists('handover_surveys');

        Schema::create('handover_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('enquiry_tasks')->onDelete('cascade');
            $table->string('access_token', 64)->nullable()->unique();
            
            // Respondent information (optional)
            $table->text('respondent_info')->nullable();
            
            // All survey responses stored as JSON
            $table->json('responses')->nullable();
            
            // Snapshot of question config version used (for historical tracking)
            $table->json('question_config_snapshot')->nullable();
            
            // Status tracking
            $table->boolean('submitted')->default(false);
            $table->timestamp('submitted_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('task_id');
            $table->index('access_token');
            $table->index('submitted');
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
