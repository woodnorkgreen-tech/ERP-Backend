<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_work_assignment_events', function (Blueprint $table) {
            $table->id();
            $table->string('work_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->enum('event', ['claimed', 'released', 'reassigned', 'completed']);
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['work_type', 'source_id', 'created_at'], 'finance_assignment_event_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_work_assignment_events');
    }
};
