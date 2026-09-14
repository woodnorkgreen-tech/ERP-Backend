<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_work_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('work_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->unique(['work_type', 'source_id']);
            $table->index(['assigned_to', 'assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_work_assignments');
    }
};
