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
        Schema::create('team_category_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('team_categories')->onDelete('cascade');
            $table->foreignId('team_type_id')->constrained('team_types')->onDelete('cascade');
            $table->boolean('is_available')->default(true);
            $table->boolean('required')->default(false);
            $table->integer('min_members')->nullable();
            $table->integer('max_members')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'team_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_category_types');
    }
};
