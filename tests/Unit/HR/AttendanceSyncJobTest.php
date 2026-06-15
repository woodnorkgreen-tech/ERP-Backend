<?php

namespace Tests\Unit\HR;

use App\Modules\HR\Jobs\SyncHikvisionAttendanceJob;
use App\Modules\HR\Models\AttendanceDeviceSyncLog;
use App\Modules\HR\Models\AttendanceSyncRequest;
use App\Modules\HR\Services\HikvisionSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AttendanceSyncJobTest extends TestCase
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

    public function test_job_updates_request_and_links_the_completed_sync_log(): void
    {
        $request = AttendanceSyncRequest::create([
            'status' => AttendanceSyncRequest::STATUS_QUEUED,
        ]);
        $log = AttendanceDeviceSyncLog::create([
            'device_id' => 'device-1',
            'device_name' => 'Main Entrance',
            'synced_at' => now(),
            'records_imported' => 10,
            'records_processed' => 4,
            'status' => AttendanceDeviceSyncLog::STATUS_SUCCESS,
        ]);

        $service = Mockery::mock(HikvisionSyncService::class);
        $service->shouldReceive('sync')->once()->andReturn($log);

        (new SyncHikvisionAttendanceJob($request->id))->handle($service);

        $request->refresh();
        $this->assertSame(AttendanceSyncRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame($log->id, $request->sync_log_id);
        $this->assertNotNull($request->started_at);
        $this->assertNotNull($request->completed_at);
    }
}
