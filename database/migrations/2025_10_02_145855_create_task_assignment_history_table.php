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
        Schema::create('task_assignment_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_task_id')->constrained('enquiry_tasks')->onDelete('cascade');
            $table->foreignId('assigned_to')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('assigned_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['enquiry_task_id']);
            $table->index(['assigned_to']);
            $table->index(['assigned_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_assignment_history');
    }
};
