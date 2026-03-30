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
        Schema::create('hr_actions', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('employee_id')->constrained()->onDelete('cascade');
            $blueprint->string('action_type'); // promotion, transfer, warning, salary_update, termination
            $blueprint->json('previous_data')->nullable(); // Snapshot of what changed (e.g. {"position": "Junior"})
            $blueprint->json('new_data')->nullable();      // Snapshot of what it became (e.g. {"position": "Senior"})
            $blueprint->date('effective_date');
            $blueprint->text('reason')->nullable();
            $blueprint->foreignId('recorded_by')->constrained('users')->onDelete('cascade');
            $blueprint->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_actions');
    }
};
