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
        Schema::create('element_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., 'stage', 'backdrop'
            $table->string('display_name'); // e.g., 'Stage', 'Backdrop'
            $table->string('category'); // e.g., 'structure', 'decoration', 'flooring'
            $table->boolean('is_predefined')->default(false); // Prevent deletion of predefined types
            $table->integer('order')->default(0); // Sort order for display
            $table->timestamps();
            
            // Add index for faster lookups
            $table->index('category');
            $table->index('is_predefined');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('element_types');
    }
};
