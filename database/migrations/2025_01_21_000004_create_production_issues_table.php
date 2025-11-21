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
        Schema::create('production_issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('production_data_id');
            $table->string('title');
            $table->text('description');
            $table->enum('category', ['materials', 'equipment', 'quality', 'timeline', 'safety', 'other']);
            $table->enum('status', ['open', 'in_progress', 'resolved'])->default('open');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->string('reported_by');
            $table->timestamp('reported_date');
            $table->timestamp('resolved_date')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('production_data_id')
                  ->references('id')
                  ->on('task_production_data')
                  ->onDelete('cascade');

            // Indexes
            $table->index('status');
            $table->index('priority');
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_issues');
    }
};
