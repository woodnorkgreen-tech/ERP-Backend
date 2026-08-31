<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('material_attribute_normalization_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('material_category_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('material_category_id', 'manr_category_fk')->references('id')->on('material_categories')->restrictOnDelete();
            $table->foreign('created_by', 'manr_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->unsignedInteger('materials_changed')->default(0);
            $table->unsignedInteger('materials_skipped')->default(0);
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });
        Schema::create('material_attribute_normalization_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('material_id');
            $table->foreign('run_id', 'mani_run_fk')->references('id')->on('material_attribute_normalization_runs')->cascadeOnDelete();
            $table->foreign('material_id', 'mani_material_fk')->references('id')->on('library_materials')->restrictOnDelete();
            $table->json('before_attributes');
            $table->json('after_attributes');
            $table->unique(['run_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_attribute_normalization_items');
        Schema::dropIfExists('material_attribute_normalization_runs');
    }
};
