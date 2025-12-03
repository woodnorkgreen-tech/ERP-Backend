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
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')
                ->constrained('tasks')
                ->onDelete('cascade');
            $table->foreignId('depends_on_task_id')
                ->constrained('tasks')
                ->onDelete('cascade');
            $table->enum('dependency_type', [
                'blocks',
                'blocked_by',
                'relates_to',
                'duplicates'
            ])->default('blocks');
            $table->timestamps();

            // Add unique constraint to prevent duplicate dependencies
            $table->unique(['task_id', 'depends_on_task_id', 'dependency_type'], 'task_dependency_unique');

            // Add indexes for performance
            $table->index('task_id');
            $table->index('depends_on_task_id');
            $table->index('dependency_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};
