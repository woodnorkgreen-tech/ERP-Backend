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
        Schema::create('workflow_template_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_template_id')->constrained('workflow_templates')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('sequence');
            $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->string('assigned_role')->nullable();
            $table->integer('estimated_duration_days')->nullable();
            $table->boolean('is_required')->default(true);
            $table->json('dependencies')->nullable(); // array of task ids

            $table->timestamps();

            $table->index(['workflow_template_id']);
            $table->index(['sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_template_tasks');
    }
};
