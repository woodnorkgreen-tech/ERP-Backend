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
        Schema::create('production_elements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('production_data_id');
            $table->string('material_id')->nullable()->comment('Reference to materials task item');
            $table->string('category', 100);
            $table->string('name');
            $table->decimal('quantity', 10, 2)->default(0);
            $table->string('unit', 50)->default('pcs');
            $table->text('specifications')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('production_data_id')
                  ->references('id')
                  ->on('task_production_data')
                  ->onDelete('cascade');

            // Indexes for better query performance
            $table->index('category');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_elements');
    }
};
