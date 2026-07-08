<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_offboarding_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->enum('status', [
                'initiated',
                'in_progress',
                'pending_final_approval',
                'completed',
                'cancelled',
            ])->default('initiated');
            $table->enum('termination_type', [
                'resignation', 'dismissal', 'redundancy', 'contract_expiry',
                'retirement', 'mutual_agreement', 'other',
            ])->nullable();
            $table->text('termination_reason')->nullable();
            $table->date('last_working_day')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('position')->nullable();
            $table->decimal('overall_progress', 5, 2)->default(0);
            $table->timestamp('hr_approved_at')->nullable();
            $table->unsignedBigInteger('hr_approved_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('employee_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offboarding_cases');
    }
};
