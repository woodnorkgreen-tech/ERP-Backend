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
        Schema::create('task_materials_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_task_id')->constrained('enquiry_tasks')->onDelete('cascade');
            $table->json('project_info');
            $table->timestamps();
            $table->unique('enquiry_task_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_materials_data');
    }
};
