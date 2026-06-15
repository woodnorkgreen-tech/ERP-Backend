<?php

namespace Tests\Unit\HR;

use App\Modules\HR\Models\AttendanceDeviceRawEvent;
use App\Modules\HR\Models\AttendanceWorkSchedule;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\AttendanceProcessingService;
use App\Modules\HR\Services\AttendanceReconciliationService;
use App\Modules\HR\Services\AttendanceScheduleService;
use App\Modules\HR\Services\AttendanceStatusPolicy;
use App\Modules\HR\Services\KenyaHolidayImportService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AttendanceProcessingPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('hikvision_id')->nullable()->unique();
            $table->string('id_number')->nullable()->index();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->date('hire_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('attendance_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('shift_start');
            $table->time('shift_end');
            $table->integer('grace_minutes')->default(10);
            $table->integer('break_minutes')->default(0);
            $table->time('earliest_clock_in');
            $table->time('latest_clock_out');
            $table->integer('half_day_minutes')->default(240);
            $table->json('working_days');
            $table->boolean('is_overnight')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('attendance_schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_schedule_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
        });
        Schema::create('attendance_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->string('source')->default('manual');
            $table->string('source_reference')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('attendance_device_raw_events', function (Blueprint $table) {
            $table->id();
            $table->string('person_id');
            $table->string('person_name')->default('');
            $table->dateTime('event_datetime');
            $table->string('source')->default('api_sync');
            $table->timestamps();
        });
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('work_schedule_id')->nullable();
            $table->date('date');
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->time('device_clock_in')->nullable();
            $table->time('device_clock_out')->nullable();
            $table->string('status');
            $table->boolean('is_holiday_work')->default(false);
            $table->string('holiday_name')->nullable();
            $table->decimal('work_hours', 5, 2)->nullable();
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->boolean('is_manual')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['employee_id', 'date']);
        });

        DB::table('departments')->insert(['id' => 1, 'name' => 'Operations']);
        $this->createSchedule();
    }

    public function test_status_policy_covers_present_late_early_half_and_missing_clock_out(): void
    {
        $schedule = AttendanceWorkSchedule::first();
        $date = Carbon::parse('2026-06-15');
        $policy = new AttendanceStatusPolicy();

        $this->assertSame('present', $policy->determine(
            Carbon::parse('2026-06-15 08:05'), Carbon::parse('2026-06-15 17:00'), $schedule, $date
        ));
        $this->assertSame('late', $policy->determine(
            Carbon::parse('2026-06-15 08:11'), Carbon::parse('2026-06-15 17:00'), $schedule, $date
        ));
        $this->assertSame('early_departure', $policy->determine(
            Carbon::parse('2026-06-15 08:00'), Carbon::parse('2026-06-15 16:30'), $schedule, $date
        ));
        $this->assertSame('half_day', $policy->determine(
            Carbon::parse('2026-06-15 10:00'), Carbon::parse('2026-06-15 13:00'), $schedule, $date
        ));
        $this->assertSame('missing_clock_out', $policy->determine(
            Carbon::parse('2026-06-15 08:00'), null, $schedule, $date
        ));
    }

    public function test_overnight_scans_are_paired_to_the_shift_start_workday(): void
    {
        $employee = $this->createEmployee('NIGHT-1');
        $night = $this->createSchedule([
            'name' => 'Night',
            'shift_start' => '22:00:00',
            'shift_end' => '06:00:00',
            'earliest_clock_in' => '20:00:00',
            'latest_clock_out' => '08:00:00',
            'is_overnight' => true,
            'is_default' => false,
        ]);
        DB::table('attendance_schedule_assignments')->insert([
            'work_schedule_id' => $night->id,
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->event('NIGHT-1', '2026-06-15 21:55:00');
        $this->event('NIGHT-1', '2026-06-16 06:05:00');

        (new AttendanceProcessingService())->processRawEvents(AttendanceDeviceRawEvent::all());

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'date' => '2026-06-15',
            'clock_in' => '21:55:00',
            'clock_out' => '06:05:00',
            'status' => 'present',
        ]);
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_employee_schedule_override_takes_precedence_over_department_override(): void
    {
        $employee = $this->createEmployee('OVERRIDE-1');
        $departmentSchedule = $this->createSchedule([
            'name' => 'Department',
            'shift_start' => '09:00:00',
            'is_default' => false,
        ]);
        $employeeSchedule = $this->createSchedule([
            'name' => 'Employee',
            'shift_start' => '10:00:00',
            'is_default' => false,
        ]);
        DB::table('attendance_schedule_assignments')->insert([
            'work_schedule_id' => $departmentSchedule->id,
            'department_id' => 1,
            'effective_from' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('attendance_schedule_assignments')->insert([
            'work_schedule_id' => $employeeSchedule->id,
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolved = (new AttendanceScheduleService())->forEmployee(
            $employee,
            Carbon::parse('2026-06-15')
        );

        $this->assertSame($employeeSchedule->id, $resolved->id);
    }

    public function test_duplicate_scans_are_collapsed_and_out_of_window_scans_are_ignored(): void
    {
        $employee = $this->createEmployee('DAY-1');
        $this->event('DAY-1', '2026-06-15 04:00:00');
        $this->event('DAY-1', '2026-06-15 08:00:00');
        $this->event('DAY-1', '2026-06-15 08:00:20');
        $this->event('DAY-1', '2026-06-15 17:00:00');

        (new AttendanceProcessingService())->processRawEvents(AttendanceDeviceRawEvent::all());

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'clock_in' => '08:00:00',
            'clock_out' => '17:00:00',
            'status' => 'present',
        ]);
    }

    public function test_attendance_on_a_public_holiday_keeps_status_and_is_flagged_for_compensation(): void
    {
        $employee = $this->createEmployee('HOLIDAY-1');
        DB::table('attendance_holidays')->insert([
            'date' => '2026-06-17',
            'name' => 'Test Holiday',
            'source' => 'manual',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->event('HOLIDAY-1', '2026-06-17 08:00:00');
        $this->event('HOLIDAY-1', '2026-06-17 17:00:00');

        (new AttendanceProcessingService())->processRawEvents(AttendanceDeviceRawEvent::all());

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'date' => '2026-06-17',
            'status' => 'present',
            'is_holiday_work' => true,
            'holiday_name' => 'Test Holiday',
        ]);
    }

    public function test_kenyan_holiday_import_downloads_and_upserts_gazetted_overrides(): void
    {
        Http::fake([
            '*' => Http::response([[
                'date' => '2026-01-01',
                'name' => "New Year's Day",
                'localName' => "New Year's Day",
            ]]),
        ]);
        config()->set('attendance.kenya_holiday_overrides.2026', [[
            'date' => '2026-03-20',
            'name' => 'Idd-ul-Fitr',
            'source_reference' => 'Kenya Gazette',
        ]]);

        $count = (new KenyaHolidayImportService())->importYear(2026);

        $this->assertSame(2, $count);
        $this->assertTrue(DB::table('attendance_holidays')
            ->whereDate('date', '2026-01-01')
            ->where('source', 'nager_date')
            ->exists());
        $this->assertTrue(DB::table('attendance_holidays')
            ->whereDate('date', '2026-03-20')
            ->where('source', 'kenya_gazette')
            ->exists());
    }

    public function test_reconciliation_creates_absence_leave_holiday_and_weekend_rows_idempotently(): void
    {
        $employee = $this->createEmployee('DAY-2', '2026-06-15', '2026-06-21');
        DB::table('leave_requests')->insert([
            'employee_id' => $employee->id,
            'start_date' => '2026-06-16',
            'end_date' => '2026-06-16',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('attendance_holidays')->insert([
            'date' => '2026-06-17',
            'name' => 'Test Holiday',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new AttendanceReconciliationService(new AttendanceScheduleService());
        $this->assertSame(7, $service->reconcile(Carbon::parse('2026-06-14'), Carbon::parse('2026-06-22')));
        $this->assertSame(0, $service->reconcile(Carbon::parse('2026-06-14'), Carbon::parse('2026-06-22')));

        $this->assertDatabaseHas('attendance_records', ['date' => '2026-06-15', 'status' => 'absent']);
        $this->assertDatabaseHas('attendance_records', ['date' => '2026-06-16', 'status' => 'on_leave']);
        $this->assertDatabaseHas('attendance_records', ['date' => '2026-06-17', 'status' => 'holiday']);
        $this->assertDatabaseHas('attendance_records', ['date' => '2026-06-20', 'status' => 'absent']);
        $this->assertDatabaseHas('attendance_records', ['date' => '2026-06-21', 'status' => 'holiday']);
        $this->assertDatabaseMissing('attendance_records', ['date' => '2026-06-14']);
        $this->assertDatabaseMissing('attendance_records', ['date' => '2026-06-22']);
    }

    private function createEmployee(
        string $personId,
        string $hireDate = '2026-01-01',
        ?string $contractEnd = null
    ): Employee {
        return Employee::create([
            'hikvision_id' => $personId,
            'department_id' => 1,
            'hire_date' => $hireDate,
            'contract_end_date' => $contractEnd,
            'status' => 'active',
        ]);
    }

    private function createSchedule(array $overrides = []): AttendanceWorkSchedule
    {
        return AttendanceWorkSchedule::create(array_merge([
            'name' => 'Standard',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
            'grace_minutes' => 10,
            'break_minutes' => 0,
            'earliest_clock_in' => '05:00:00',
            'latest_clock_out' => '23:59:59',
            'half_day_minutes' => 240,
            'working_days' => [1, 2, 3, 4, 5, 6],
            'is_overnight' => false,
            'is_default' => true,
            'is_active' => true,
        ], $overrides));
    }

    private function event(string $personId, string $datetime): void
    {
        DB::table('attendance_device_raw_events')->insert([
            'person_id' => $personId,
            'event_datetime' => $datetime,
            'source' => 'api_sync',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
