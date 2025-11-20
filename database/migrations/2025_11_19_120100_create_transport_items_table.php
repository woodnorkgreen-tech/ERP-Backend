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
        Schema::create('transport_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_task_id')->constrained('logistics_tasks')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('quantity');
            $table->string('unit');
            $table->enum('category', ['production', 'custom']);
            $table->string('source')->nullable();
            $table->string('weight')->nullable();
            $table->text('special_handling')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transport_items');
    }
};
