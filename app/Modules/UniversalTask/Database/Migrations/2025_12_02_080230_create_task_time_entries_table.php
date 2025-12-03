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
        Schema::create('task_time_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('user_id');
            $table->decimal('hours', 8, 2); // Hours spent (e.g., 2.5 for 2 hours 30 minutes)
            $table->date('date_worked'); // Date when the work was performed
            $table->text('description')->nullable(); // Description of work performed
            $table->timestamp('started_at')->nullable(); // When the work session started
            $table->timestamp('ended_at')->nullable(); // When the work session ended
            $table->boolean('is_billable')->default(true); // Whether this time is billable
            $table->json('metadata')->nullable(); // Additional metadata
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['task_id', 'user_id']);
            $table->index(['user_id', 'date_worked']);
            $table->index('date_worked');
            $table->index('is_billable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_time_entries');
    }
};