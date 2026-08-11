<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_job_id')->nullable()->constrained('design_jobs')->cascadeOnDelete();
            $table->foreignId('design_item_id')->nullable()->constrained('design_items')->cascadeOnDelete();
            $table->string('document_type')->default('other');
            $table->string('name');
            $table->string('original_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('mime_type');
            $table->integer('version')->default(1);
            $table->enum('status', ['active', 'superseded', 'archived'])->default('active');
            $table->json('metadata')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['design_job_id', 'document_type']);
            $table->index(['design_item_id', 'document_type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_documents');
    }
};
