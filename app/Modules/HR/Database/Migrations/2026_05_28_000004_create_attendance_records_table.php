<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->date('date');
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->time('device_clock_in')->nullable();
            $table->time('device_clock_out')->nullable();
            $table->enum('status', [
                'present', 'absent', 'late', 'early_departure',
                'half_day', 'missing_clock_out', 'on_leave', 'holiday',
            ])->default('present');
            $table->decimal('work_hours', 4, 2)->nullable();
            $table->decimal('overtime_hours', 4, 2)->nullable()->default(0);
            $table->boolean('is_manual')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_id', 'date']);
            $table->index('date');
            $table->index('employee_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
