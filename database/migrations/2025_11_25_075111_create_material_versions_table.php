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
        Schema::create('material_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_materials_data_id');
            $table->integer('version_number');
            $table->string('label')->nullable();
            $table->json('data'); // Full snapshot: project_info + elements + materials
            $table->unsignedBigInteger('created_by');
            $table->timestamp('source_updated_at')->nullable(); // Track when source was last updated
            $table->timestamps();

            $table->foreign('task_materials_data_id')->references('id')->on('task_materials_data')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['task_materials_data_id', 'version_number'], 'idx_material_versions_task_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_versions');
    }
};
