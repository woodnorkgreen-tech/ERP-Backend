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
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            
            // Template identification
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            
            // Version tracking
            $table->integer('version')->default(1);
            $table->foreignId('previous_version_id')->nullable()->constrained('task_templates')->onDelete('set null');
            $table->boolean('is_active')->default(true);
            
            // Template configuration
            $table->json('template_data'); // Stores task definitions, dependencies, and variables
            $table->json('variables')->nullable(); // Variable definitions for substitution
            
            // Metadata
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->json('tags')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('name');
            $table->index('category');
            $table->index('version');
            $table->index('is_active');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_templates');
    }
};
