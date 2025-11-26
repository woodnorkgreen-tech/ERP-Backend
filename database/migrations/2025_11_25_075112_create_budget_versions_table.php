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
        Schema::create('budget_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_budget_data_id');
            $table->integer('version_number');
            $table->string('label')->nullable();
            $table->json('data'); // Full snapshot: all budget data + additions
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('materials_version_id')->nullable(); // Link to material version at time of creation
            $table->timestamp('source_updated_at')->nullable(); // Track when source was last updated
            $table->timestamps();

            $table->foreign('task_budget_data_id')->references('id')->on('task_budget_data')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('materials_version_id')->references('id')->on('material_versions')->onDelete('set null');
            $table->index(['task_budget_data_id', 'version_number'], 'idx_budget_versions_task_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_versions');
    }
};
