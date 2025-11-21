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
        Schema::create('production_completion_criteria', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('production_data_id');
            $table->text('description');
            $table->enum('category', ['production', 'quality', 'documentation', 'approval']);
            $table->boolean('met')->default(false);
            $table->boolean('is_custom')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('production_data_id')
                  ->references('id')
                  ->on('task_production_data')
                  ->onDelete('cascade');

            // Indexes
            $table->index('category');
            $table->index('met');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_completion_criteria');
    }
};
