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
        Schema::create('project_elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_materials_data_id')->constrained('task_materials_data')->onDelete('cascade');
            $table->string('template_id', 100)->nullable();
            $table->string('element_type', 100);
            $table->string('name', 255);
            $table->enum('category', ['production', 'hire', 'outsourced']);
            $table->json('dimensions')->nullable();
            $table->boolean('is_included')->default(true);
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_elements');
    }
};
