<?php

namespace Tests\Unit\HR;

use App\Modules\HR\Exceptions\AttendanceRecordConflictException;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Services\AttendanceService;
use App\Modules\HR\Services\HikvisionSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AttendanceCorrectionAuditTest extends TestCase
{
    private AttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('date');
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->time('device_clock_in')->nullable();
            $table->time('device_clock_out')->nullable();
            $table->string('status');
            $table->decimal('work_hours', 5, 2)->nullable();
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->boolean('is_manual')->default(false);
            $table->text('correction_reason')->nullable();
            $table->unsignedBigInteger('corrected_by')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['employee_id', 'date']);
        });

        Schema::create('hr_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('action');
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });

        DB::table('employees')->insert([
            'id' => 1,
            'first_name' => 'Amina',
            'last_name' => 'Otieno',
        ]);
        DB::table('users')->insert(['id' => 7, 'name' => 'HR User']);

        $this->service = new AttendanceService(Mockery::mock(HikvisionSyncService::class));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('hr_audit_logs');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('employees');

        parent::tearDown();
    }

    public function test_manual_creation_records_reason_actor_and_audit_snapshot(): void
    {
        $record = $this->service->createRecord([
            'employee_id' => 1,
            'date' => '2026-06-15',
            'clock_in' => '08:00',
            'clock_out' => '17:00',
            'status' => AttendanceRecord::STATUS_PRESENT,
            'correction_reason' => 'Device was offline',
        ], 7, '127.0.0.1');

        $this->assertTrue($record->is_manual);
        $this->assertSame(7, $record->corrected_by);
        $this->assertSame('Device was offline', $record->correction_reason);
        $this->assertDatabaseHas('hr_audit_logs', [
            'action' => 'attendance_created_manually',
            'model_id' => $record->id,
            'user_id' => 7,
            'message' => 'Device was offline',
            'ip_address' => '127.0.0.1',
        ]);
    }

    public function test_manual_values_are_calculated_by_the_backend_policy(): void
    {
        $record = $this->service->createRecord([
            'employee_id' => 1,
            'date' => '2026-06-15',
            'clock_in' => '09:00',
            'clock_out' => '18:30',
            'status' => AttendanceRecord::STATUS_PRESENT,
            'correction_reason' => 'Device was offline',
        ], 7);

        $this->assertSame(AttendanceRecord::STATUS_LATE, $record->status);
        $this->assertSame(9.5, $record->work_hours);
        $this->assertSame(0.5, $record->overtime_hours);
    }

    public function test_correction_marks_device_record_manual_without_changing_device_evidence(): void
    {
        $record = AttendanceRecord::create([
            'employee_id' => 1,
            'date' => '2026-06-15',
            'clock_in' => '08:30',
            'clock_out' => '17:00',
            'device_clock_in' => '08:30',
            'device_clock_out' => '17:00',
            'status' => AttendanceRecord::STATUS_LATE,
        ]);

        $updated = $this->service->updateRecord($record->id, [
            'date' => '2026-06-16',
            'clock_in' => '08:00',
            'status' => AttendanceRecord::STATUS_PRESENT,
            'correction_reason' => 'Approved field assignment',
        ], 7);

        $this->assertTrue($updated->is_manual);
        $this->assertSame('2026-06-16', $updated->date->toDateString());
        $this->assertSame('08:00', substr($updated->clock_in, 0, 5));
        $this->assertSame('08:30', substr($updated->device_clock_in, 0, 5));
        $this->assertDatabaseHas('hr_audit_logs', [
            'action' => 'attendance_corrected',
            'model_id' => $record->id,
            'message' => 'Approved field assignment',
        ]);
    }

    public function test_duplicate_employee_day_is_rejected_before_write(): void
    {
        AttendanceRecord::create([
            'employee_id' => 1,
            'date' => '2026-06-15',
            'status' => AttendanceRecord::STATUS_PRESENT,
        ]);

        $this->expectException(AttendanceRecordConflictException::class);

        $this->service->createRecord([
            'employee_id' => 1,
            'date' => '2026-06-15',
            'status' => AttendanceRecord::STATUS_PRESENT,
            'correction_reason' => 'Duplicate attempt',
        ], 7);
    }

    public function test_delete_and_restore_are_reasoned_and_audited(): void
    {
        $record = AttendanceRecord::create([
            'employee_id' => 1,
            'date' => '2026-06-15',
            'status' => AttendanceRecord::STATUS_PRESENT,
        ]);

        $this->service->deleteRecord($record->id, 'Entered for wrong employee', 7);
        $this->assertSoftDeleted('attendance_records', ['id' => $record->id]);

        $restored = $this->service->restoreRecord($record->id, 'Deletion was mistaken', 7);

        $this->assertFalse($restored->trashed());
        $this->assertTrue($restored->is_manual);
        $this->assertSame('Deletion was mistaken', $restored->correction_reason);
        $this->assertDatabaseHas('hr_audit_logs', [
            'action' => 'attendance_deleted',
            'model_id' => $record->id,
        ]);
        $this->assertDatabaseHas('hr_audit_logs', [
            'action' => 'attendance_restored',
            'model_id' => $record->id,
        ]);
    }
}
