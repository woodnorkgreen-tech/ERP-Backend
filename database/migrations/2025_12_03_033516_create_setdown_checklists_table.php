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
        Schema::create('setdown_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setdown_task_id')->constrained('setdown_tasks')->onDelete('cascade');
            $table->json('checklist_data'); // Stores all checklist items with their completion status
            $table->integer('completed_count')->default(0);
            $table->integer('total_count')->default(14); // 14 default checklist items
            $table->decimal('completion_percentage', 5, 2)->default(0.00);
            $table->timestamp('completed_at')->nullable(); // When all items were completed
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('setdown_checklists');
    }
};
