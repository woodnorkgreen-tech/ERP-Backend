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
        Schema::create('grievances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complainant_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('against_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->text('description');
            $table->timestamp('date_reported');
            $table->enum('status', ['Reported', 'Investigating', 'Resolved', 'Escalated', 'Closed'])->default('Reported');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('resolved_at')->nullable();
            $table->enum('escalated_to', ['hr_officer', 'director'])->nullable();
            $table->text('investigation_notes')->nullable();
            $table->text('witnesses')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grievances');
    }
};