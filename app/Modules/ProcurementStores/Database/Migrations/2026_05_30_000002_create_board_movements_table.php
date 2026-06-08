<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only event log — no updates ever
        Schema::create('board_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('boards')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('job_ref')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            // ts is the immutable timestamp — no updated_at
            $table->timestamp('ts')->useCurrent();

            $table->index('board_id');
            $table->index('to_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_movements');
    }
};
