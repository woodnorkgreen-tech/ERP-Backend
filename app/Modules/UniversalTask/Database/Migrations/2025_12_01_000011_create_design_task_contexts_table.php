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
        Schema::create('design_task_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->unique()->constrained('tasks')->onDelete('cascade');
            
            // Design-specific fields
            $table->string('design_type')->nullable(); // e.g., 'logo', 'banner', 'layout', 'mockup'
            $table->json('design_assets')->nullable(); // Array of asset file paths
            $table->integer('current_revision')->default(1);
            $table->json('revision_history')->nullable(); // Array of revision records
            $table->string('approval_status')->default('pending'); // 'pending', 'approved', 'rejected', 'needs_revision'
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->dateTime('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->string('design_software')->nullable(); // e.g., 'Figma', 'Adobe XD', 'Photoshop'
            $table->json('design_specifications')->nullable(); // Dimensions, colors, fonts, etc.
            $table->string('file_format')->nullable(); // e.g., 'PSD', 'AI', 'SVG', 'PNG'
            $table->json('color_palette')->nullable(); // Array of hex color codes
            $table->json('fonts')->nullable(); // Array of font names and styles
            $table->string('target_platform')->nullable(); // e.g., 'web', 'mobile', 'print'
            $table->integer('width_px')->nullable();
            $table->integer('height_px')->nullable();
            $table->text('design_brief')->nullable();
            $table->text('client_feedback')->nullable();
            $table->json('reference_links')->nullable(); // Array of inspiration/reference URLs
            $table->boolean('requires_client_approval')->default(true);
            $table->integer('feedback_rounds')->default(0);
            $table->dateTime('final_delivery_date')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('design_type');
            $table->index('approval_status');
            $table->index('current_revision');
            $table->index('approved_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('design_task_contexts');
    }
};
