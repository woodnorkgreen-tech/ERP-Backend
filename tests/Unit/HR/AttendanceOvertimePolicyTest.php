<?php

namespace Tests\Unit\HR;

use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\AttendanceWorkSchedule;
use App\Modules\HR\Services\AttendanceOvertimeService;
use App\Modules\HR\Services\AttendanceService;
use App\Modules\HR\Services\HikvisionSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AttendanceOvertimePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('hire_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('date');
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->string('status');
            $table->decimal('work_hours', 8, 2)->nullable();
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->decimal('proposed_overtime_hours', 8, 2)->default(0);
            $table->decimal('approved_overtime_hours', 8, 2)->default(0);
            $table->string('overtime_status')->nullable();
            $table->boolean('is_holiday_work')->default(false);
            $table->string('holiday_name')->nullable();
            $table->boolean('is_manual')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ot_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attendance_record_id')->nullable()->unique();
            $table->string('source_type')->default('manual');
            $table->unsignedBigInteger('employee_id');
            $table->date('work_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('hours', 8, 2);
            $table->text('notes')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('supervisor_approved_by')->nullable();
            $table->timestamp('supervisor_approved_at')->nullable();
            $table->unsignedBigInteger('hr_approved_by')->nullable();
            $table->timestamp('hr_approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->unsignedBigInteger('supersedes_entry_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('employees')->insert([
            ['id' => 1, 'employee_id' => 'E001', 'first_name' => 'Amina', 'last_name' => 'Otieno', 'hire_date' => '2026-01-01', 'status' => 'active'],
            ['id' => 2, 'employee_id' => 'E002', 'first_name' => 'Brian', 'last_name' => 'Mwangi', 'hire_date' => '2026-01-01', 'status' => 'active'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ot_entries');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('employees');
        parent::tearDown();
    }

    public function test_sunday_or_holiday_work_proposes_all_net_worked_hours(): void
    {
        $record = AttendanceRecord::create([
            'employee_id' => 1,
            'date' => '2026-06-14',
            'clock_in' => '09:00:00',
            'clock_out' => '17:00:00',
            'status' => 'present',
            'work_hours' => 8,
            'is_holiday_work' => true,
            'holiday_name' => 'Scheduled non-working day',
        ]);

        $entry = (new AttendanceOvertimeService())->syncProposal($record, $this->schedule());

        $this->assertSame('8.00', $entry->hours);
        $this->assertSame('submitted', $entry->status);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $record->id,
            'proposed_overtime_hours' => 8,
            'approved_overtime_hours' => 0,
            'overtime_status' => 'submitted',
        ]);
    }

    public function test_regular_workday_only_proposes_time_after_six_pm(): void
    {
        $record = AttendanceRecord::create([
            'employee_id' => 1,
            'date' => '2026-06-15',
            'clock_in' => '08:00:00',
            'clock_out' => '19:30:00',
            'status' => 'present',
            'work_hours' => 11.5,
        ]);

        $entry = (new AttendanceOvertimeService())->syncProposal($record, $this->schedule());

        $this->assertSame('1.50', $entry->hours);
        $this->assertSame('18:00:00', $entry->start_time);
    }

    public function test_only_approved_entry_populates_approved_hours(): void
    {
        $record = AttendanceRecord::create([
            'employee_id' => 1,
            'date' => '2026-06-14',
            'clock_in' => '09:00:00',
            'clock_out' => '17:00:00',
            'status' => 'present',
            'work_hours' => 8,
            'is_holiday_work' => true,
        ]);
        $service = new AttendanceOvertimeService();
        $entry = $service->syncProposal($record, $this->schedule());

        $this->assertSame(0.0, $record->fresh()->approved_overtime_hours);
        $service->markApproved($entry);

        $this->assertSame(8.0, $record->fresh()->approved_overtime_hours);
        $this->assertSame('done', $record->fresh()->overtime_status);
    }

    public function test_summary_uses_expected_employee_workdays_as_denominator(): void
    {
        AttendanceRecord::create([
            'employee_id' => 1,
            'date' => '2026-06-15',
            'clock_in' => '08:00:00',
            'clock_out' => '17:00:00',
            'status' => 'present',
            'work_hours' => 9,
        ]);

        $service = new AttendanceService(Mockery::mock(HikvisionSyncService::class));
        $summary = $service->getSummary('2026-06-15');

        $this->assertSame(2, $summary['total_employees']);
        $this->assertSame(2, $summary['expected_workdays']);
        $this->assertSame(1, $summary['attended_expected_workdays']);
        $this->assertSame(50.0, $summary['attendance_rate']);
    }

    private function schedule(): AttendanceWorkSchedule
    {
        return new AttendanceWorkSchedule([
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
            'working_days' => [1, 2, 3, 4, 5, 6],
            'break_minutes' => 0,
        ]);
    }
}
