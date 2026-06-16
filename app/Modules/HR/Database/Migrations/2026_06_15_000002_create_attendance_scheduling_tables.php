<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('shift_start');
            $table->time('shift_end');
            $table->unsignedSmallInteger('grace_minutes')->default(10);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->time('earliest_clock_in')->default('05:00:00');
            $table->time('latest_clock_out')->default('23:59:59');
            $table->unsignedSmallInteger('half_day_minutes')->default(240);
            $table->json('working_days');
            $table->boolean('is_overnight')->default(false);
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('attendance_schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_schedule_id')->constrained('attendance_work_schedules')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(
                ['employee_id', 'effective_from', 'effective_to'],
                'attendance_assignment_employee_dates_idx'
            );
            $table->index(
                ['department_id', 'effective_from', 'effective_to'],
                'attendance_assignment_department_dates_idx'
            );
        });

        Schema::create('attendance_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('work_schedule_id')->nullable()->after('employee_id')
                ->constrained('attendance_work_schedules')->nullOnDelete();
        });

        DB::table('attendance_work_schedules')->insert([
            'name' => 'Standard Nairobi Workday',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
            'grace_minutes' => 10,
            'break_minutes' => 0,
            'earliest_clock_in' => '05:00:00',
            'latest_clock_out' => '23:59:59',
            'half_day_minutes' => 240,
            'working_days' => json_encode([1, 2, 3, 4, 5, 6]),
            'is_overnight' => false,
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_schedule_id');
        });
        Schema::dropIfExists('attendance_holidays');
        Schema::dropIfExists('attendance_schedule_assignments');
        Schema::dropIfExists('attendance_work_schedules');
    }
};
