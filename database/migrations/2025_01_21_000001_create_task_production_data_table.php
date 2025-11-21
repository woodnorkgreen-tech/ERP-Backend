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
        Schema::create('task_production_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->boolean('materials_imported')->default(false);
            $table->timestamp('last_materials_import_date')->nullable();
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('task_id')
                  ->references('id')
                  ->on('enquiry_tasks')
                  ->onDelete('cascade');

            // Unique constraint - one production data per task
            $table->unique('task_id', 'unique_task_production');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_production_data');
    }
};
