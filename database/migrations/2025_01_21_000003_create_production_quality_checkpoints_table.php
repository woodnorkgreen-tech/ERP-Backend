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
        Schema::create('production_quality_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('production_data_id');
            $table->string('category_id', 100);
            $table->string('category_name');
            $table->enum('status', ['pending', 'in_progress', 'passed', 'failed'])->default('pending');
            $table->unsignedTinyInteger('quality_score')->nullable()->comment('0-100');
            $table->string('checked_by')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('production_data_id')
                  ->references('id')
                  ->on('task_production_data')
                  ->onDelete('cascade');

            // Indexes
            $table->index('status');
            $table->index('category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_quality_checkpoints');
    }
};
