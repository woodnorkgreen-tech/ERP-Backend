<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_requests', function (Blueprint $table) {
            $table->id();

            // Which job is requesting boards
            $table->string('job_ref')->index();

            // What material and how many
            $table->foreignId('material_id')->constrained('library_materials')->cascadeOnDelete();
            $table->unsignedInteger('qty_requested');
            $table->unsignedInteger('qty_fulfilled')->default(0);

            // Workflow state
            $table->enum('status', ['pending', 'partial', 'fulfilled', 'cancelled'])->default('pending')->index();

            // Who and when
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_requests');
    }
};
