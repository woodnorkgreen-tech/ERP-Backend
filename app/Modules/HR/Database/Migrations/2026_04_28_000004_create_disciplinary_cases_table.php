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
        Schema::create('disciplinary_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('reported_by')->constrained('users')->onDelete('cascade');
            $table->text('allegations');
            $table->enum('offense_category', ['minor', 'gross_misconduct']);
            $table->timestamp('date_reported');
            $table->enum('status', [
                'Reported',
                'Show Cause Issued',
                'Investigating',
                'Hearing Scheduled',
                'Hearing Held',
                'Decision Made',
                'Appealed',
                'Final'
            ])->default('Reported');
            $table->boolean('show_cause_issued')->default(false);
            $table->text('show_cause_letter')->nullable();
            $table->text('show_cause_response')->nullable();
            $table->timestamp('show_cause_response_date')->nullable();
            $table->text('investigation_notes')->nullable();
            $table->text('witnesses')->nullable();
            $table->json('attachments')->nullable();
            $table->boolean('hearing_scheduled')->default(false);
            $table->timestamp('hearing_date')->nullable();
            $table->json('hearing_panel')->nullable();
            $table->text('hearing_minutes')->nullable();
            $table->text('hearing_decision')->nullable();
            $table->enum('warning_issued', ['verbal', 'first_written', 'second_written', 'final', 'termination'])->nullable();
            $table->text('warning_letter')->nullable();
            $table->boolean('appeal_submitted')->default(false);
            $table->text('appeal_details')->nullable();
            $table->text('appeal_response')->nullable();
            $table->text('final_decision')->nullable();
            $table->json('suspension_details')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disciplinary_cases');
    }
};