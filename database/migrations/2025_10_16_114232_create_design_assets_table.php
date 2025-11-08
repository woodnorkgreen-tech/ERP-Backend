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
        Schema::create('design_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_task_id')->constrained('enquiry_tasks')->onDelete('cascade');
            $table->string('name');
            $table->string('original_name');
            $table->string('file_path');
            $table->bigInteger('file_size');
            $table->string('mime_type');
            $table->enum('category', ['concept', 'mockup', 'artwork', 'logo', 'ui-ux', 'illustration', 'prototype', 'presentation', 'other'])->default('other');
            $table->enum('status', ['pending', 'approved', 'rejected', 'revision', 'archived'])->default('pending');
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->integer('version')->default(1);
            $table->foreignId('parent_asset_id')->nullable()->constrained('design_assets')->onDelete('set null');
            $table->json('metadata')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['enquiry_task_id']);
            $table->index(['category']);
            $table->index(['status']);
            $table->index(['uploaded_by']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('design_assets');
    }
};
