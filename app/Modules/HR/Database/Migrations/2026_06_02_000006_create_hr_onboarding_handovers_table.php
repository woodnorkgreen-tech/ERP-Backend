<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_handovers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('onboarding_case_id');
            $table->unsignedBigInteger('handed_over_by');
            $table->unsignedBigInteger('handed_over_to_employee_id')->nullable();
            $table->text('handover_notes')->nullable();
            $table->text('line_manager_notes')->nullable();
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->date('handover_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('onboarding_case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding_handovers');
    }
};
