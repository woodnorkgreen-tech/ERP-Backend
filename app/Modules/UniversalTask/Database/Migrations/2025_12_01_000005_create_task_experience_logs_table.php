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
        Schema::create('task_experience_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('content');
            $table->string('log_type'); // observation, learning, best_practice, recommendation, etc.
            $table->json('tags')->nullable();
            $table->boolean('is_public')->default(true);
            $table->timestamp('logged_at');
            $table->timestamps();

            // Indexes for performance
            $table->index('task_id');
            $table->index('user_id');
            $table->index('log_type');
            $table->index('logged_at');
            $table->index('is_public');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_experience_logs');
    }
};
