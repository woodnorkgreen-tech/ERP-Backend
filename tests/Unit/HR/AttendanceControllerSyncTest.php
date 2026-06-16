<?php

namespace Tests\Unit\HR;

use App\Modules\HR\Http\Controllers\AttendanceController;
use App\Modules\HR\Jobs\SyncHikvisionAttendanceJob;
use App\Modules\HR\Models\AttendanceDeviceSyncLog;
use App\Modules\HR\Models\AttendanceSyncRequest;
use App\Modules\HR\Services\AttendanceCsvImportService;
use App\Modules\HR\Services\AttendanceExceptionService;
use App\Modules\HR\Services\AttendanceReprocessingService;
use App\Modules\HR\Services\AttendanceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AttendanceControllerSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('attendance_device_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('device_id');
            $table->string('device_name');
            $table->timestamp('synced_at');
            $table->dateTime('range_from')->nullable();
            $table->dateTime('range_to')->nullable();
            $table->integer('records_fetched')->default(0);
            $table->integer('records_imported')->default(0);
            $table->integer('records_duplicate')->default(0);
            $table->integer('records_processed')->default(0);
            $table->integer('records_unmapped')->default(0);
            $table->integer('records_failed')->default(0);
            $table->string('status')->default('success');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_sync_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status')->default('queued');
            $table->unsignedBigInteger('sync_log_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function test_manual_sync_queues_a_job_and_returns_accepted_request(): void
    {
        Queue::fake();

        $response = $this->controller()->sync();
        $payload = $response->getData(true);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame(AttendanceSyncRequest::STATUS_QUEUED, $payload['data']['status']);
        $this->assertDatabaseHas('attendance_sync_requests', [
            'id' => $payload['data']['id'],
            'status' => AttendanceSyncRequest::STATUS_QUEUED,
        ]);
        Queue::assertPushed(
            SyncHikvisionAttendanceJob::class,
            fn ($job) => $job->syncRequestId === $payload['data']['id']
        );
    }

    public function test_manual_sync_reuses_an_active_request(): void
    {
        Queue::fake();
        $request = AttendanceSyncRequest::create([
            'status' => AttendanceSyncRequest::STATUS_RUNNING,
        ]);

        $response = $this->controller()->sync();
        $payload = $response->getData(true);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame($request->id, $payload['data']['id']);
        $this->assertDatabaseCount('attendance_sync_requests', 1);
        Queue::assertNothingPushed();
    }

    public function test_sync_status_returns_the_final_device_log(): void
    {
        $log = AttendanceDeviceSyncLog::create([
            'device_id' => 'device-1',
            'device_name' => 'Main Entrance',
            'synced_at' => now(),
            'records_imported' => 12,
            'records_processed' => 5,
            'status' => AttendanceDeviceSyncLog::STATUS_PARTIAL,
            'error' => 'Partial sync',
        ]);
        $request = AttendanceSyncRequest::create([
            'status' => AttendanceSyncRequest::STATUS_COMPLETED,
            'sync_log_id' => $log->id,
            'completed_at' => now(),
        ]);

        $response = $this->controller()->syncStatus($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(AttendanceSyncRequest::STATUS_COMPLETED, $payload['data']['status']);
        $this->assertSame($log->id, $payload['data']['sync_log']['id']);
        $this->assertSame(AttendanceDeviceSyncLog::STATUS_PARTIAL, $payload['data']['sync_log']['status']);
    }

    private function controller(): AttendanceController
    {
        return new AttendanceController(
            Mockery::mock(AttendanceService::class),
            Mockery::mock(AttendanceCsvImportService::class),
            Mockery::mock(AttendanceExceptionService::class),
            Mockery::mock(AttendanceReprocessingService::class)
        );
    }
}
