<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_return_batches', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('job_ref', 100)->index();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('status', 30)->default('in_transit')->index();
            $table->unsignedInteger('expected_count')->default(0);
            $table->unsignedInteger('received_count')->default(0);
            $table->unsignedInteger('missing_count')->default(0);
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('initiated_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('board_return_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_return_batch_id')->constrained('board_return_batches')->cascadeOnDelete();
            $table->foreignId('board_id')->constrained('boards')->restrictOnDelete();
            $table->string('status', 30)->default('expected')->index();
            $table->string('condition_grade', 10)->nullable();
            $table->string('outcome', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['board_return_batch_id', 'board_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_return_batch_items');
        Schema::dropIfExists('board_return_batches');
    }
};
