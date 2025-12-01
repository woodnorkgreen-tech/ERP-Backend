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
        if (!Schema::hasTable('setdown_tasks')) {
            Schema::create('setdown_tasks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained('enquiry_tasks')->onDelete('cascade');
                $table->foreignId('project_id')->constrained('project_enquiries')->onDelete('cascade');
                $table->text('setdown_notes')->nullable();
                $table->text('completion_notes')->nullable();
                $table->foreignId('created_by')->constrained('users');
                $table->foreignId('updated_by')->nullable()->constrained('users');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('setdown_task_photos')) {
            Schema::create('setdown_task_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('setdown_task_id')->constrained('setdown_tasks')->onDelete('cascade');
                $table->string('filename');
                $table->string('original_filename');
                $table->string('path');
                $table->string('description')->nullable();
                $table->foreignId('uploaded_by')->constrained('users');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('setdown_task_issues')) {
            Schema::create('setdown_task_issues', function (Blueprint $table) {
                $table->id();
                $table->foreignId('setdown_task_id')->constrained('setdown_tasks')->onDelete('cascade');
                $table->string('title');
                $table->text('description');
                $table->enum('category', ['equipment', 'venue', 'team', 'safety', 'other']);
                $table->enum('priority', ['low', 'medium', 'high', 'critical']);
                $table->enum('status', ['open', 'in_progress', 'resolved'])->default('open');
                $table->foreignId('reported_by')->constrained('users');
                $table->foreignId('assigned_to')->nullable()->constrained('users');
                $table->timestamp('resolved_at')->nullable();
                $table->text('resolution')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('setdown_task_issues');
        Schema::dropIfExists('setdown_task_photos');
        Schema::dropIfExists('setdown_tasks');
    }
};
