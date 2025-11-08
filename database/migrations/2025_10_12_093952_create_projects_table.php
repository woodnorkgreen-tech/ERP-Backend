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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained('project_enquiries')->onDelete('cascade');
            $table->string('project_id')->unique();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('budget', 15, 2)->nullable();
            $table->integer('current_phase')->default(0);
            $table->json('assigned_users')->nullable();
            $table->enum('status', ['planning', 'in_progress', 'completed', 'cancelled'])->default('planning');

            $table->timestamps();

            // Indexes
            $table->index(['enquiry_id']);
            $table->index(['project_id']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
