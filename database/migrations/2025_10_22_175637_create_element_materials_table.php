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
        Schema::create('element_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_element_id')->constrained()->onDelete('cascade');
            $table->string('description', 500);
            $table->string('unit_of_measurement', 50);
            $table->decimal('quantity', 10, 2);
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
        Schema::dropIfExists('element_materials');
    }
};
