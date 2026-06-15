<?php

namespace Tests\Unit\HR;

use App\Modules\HR\Data\AttendanceProcessingResult;
use App\Modules\HR\Models\AttendanceDeviceRawEvent;
use App\Modules\HR\Models\AttendanceDeviceSyncLog;
use App\Modules\HR\Services\AttendanceProcessingService;
use App\Modules\HR\Services\HikvisionSyncService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class AttendanceSyncMergeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('hikvision_id')->nullable()->unique();
            $table->string('id_number')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_device_raw_events', function (Blueprint $table) {
            $table->id();
            $table->string('person_id');
            $table->string('person_name')->default('');
            $table->dateTime('event_datetime');
            $table->string('check_point')->nullable();
            $table->string('department')->nullable();
            $table->string('source')->default('api_sync');
            $table->unsignedBigInteger('sync_log_id')->nullable();
            $table->timestamps();
            $table->unique(['person_id', 'event_datetime']);
        });

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

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('date');
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->time('device_clock_in')->nullable();
            $table->time('device_clock_out')->nullable();
            $table->string('status')->default('present');
            $table->decimal('work_hours', 4, 2)->nullable();
            $table->decimal('overtime_hours', 4, 2)->default(0);
            $table->boolean('is_manual')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['employee_id', 'date']);
        });

        config()->set('hikvision.shift_start', '08:00');
        config()->set('hikvision.shift_end', '17:00');
        config()->set('hikvision.overtime_start', '18:00');
        config()->set('hikvision.late_threshold_minutes', 10);
        config()->set('hikvision.sync_overlap_hours', 24);
        config()->set('hikvision.device_timezone', 'Africa/Nairobi');
        config()->set('hikvision.storage_timezone', 'Africa/Nairobi');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_default_sync_requests_the_configured_thirty_day_lookback(): void
    {
        Carbon::setTestNow('2026-06-11 10:00:00');
        config()->set('hikvision.sync_lookback_days', 30);
        config()->set('hikvision.host', '192.168.1.200');
        config()->set('hikvision.port', 80);
        config()->set('hikvision.username', 'admin');
        config()->set('hikvision.password', 'secret');
        config()->set('hikvision.device_id', 'device-1');
        config()->set('hikvision.device_name', 'Main Entrance');

        Http::fake([
            '*' => Http::response([
                'AcsEvent' => [
                    'InfoList' => [],
                    'numOfMatches' => 0,
                    'responseStatusStrg' => 'NO MORE',
                ],
            ]),
        ]);

        $processingService = Mockery::mock(AttendanceProcessingService::class);
        $processingService->shouldReceive('processRawEventsDetailed')
            ->once()
            ->andReturn(new AttendanceProcessingResult());
        $processingService->shouldReceive('repairIncompleteHistoricalRecordsDetailed')
            ->once()
            ->andReturn(new AttendanceProcessingResult());

        (new HikvisionSyncService($processingService))->sync();

        Http::assertSent(function ($request) {
            return $request['AcsEventCond']['startTime'] === '2026-05-12T10:00:00'
                && $request['AcsEventCond']['endTime'] === '2026-06-11T10:00:00';
        });
    }

    public function test_later_sync_starts_from_the_last_successful_sync_with_overlap(): void
    {
        Carbon::setTestNow('2026-06-11 10:00:00');
        config()->set('hikvision.sync_lookback_days', 30);
        config()->set('hikvision.sync_overlap_hours', 24);
        config()->set('hikvision.host', '192.168.1.200');
        config()->set('hikvision.port', 80);
        config()->set('hikvision.username', 'admin');
        config()->set('hikvision.password', 'secret');
        config()->set('hikvision.device_id', 'device-1');
        config()->set('hikvision.device_name', 'Main Entrance');

        DB::table('attendance_device_sync_logs')->insert([
            'device_id' => 'device-1',
            'device_name' => 'Main Entrance',
            'synced_at' => '2026-06-08 10:00:00',
            'status' => 'success',
            'created_at' => '2026-06-08 10:00:00',
            'updated_at' => '2026-06-08 10:00:00',
        ]);

        Http::fake([
            '*' => Http::response([
                'AcsEvent' => [
                    'InfoList' => [],
                    'numOfMatches' => 0,
                    'responseStatusStrg' => 'NO MORE',
                ],
            ]),
        ]);

        $processingService = Mockery::mock(AttendanceProcessingService::class);
        $processingService->shouldReceive('processRawEventsDetailed')
            ->once()
            ->andReturn(new AttendanceProcessingResult());
        $processingService->shouldReceive('repairIncompleteHistoricalRecordsDetailed')
            ->once()
            ->andReturn(new AttendanceProcessingResult());

        (new HikvisionSyncService($processingService))->sync();

        Http::assertSent(function ($request) {
            return $request['AcsEventCond']['startTime'] === '2026-06-07T10:00:00'
                && $request['AcsEventCond']['endTime'] === '2026-06-11T10:00:00';
        });
    }

    public function test_missing_device_configuration_creates_a_clear_failed_sync_without_network_access(): void
    {
        config()->set('hikvision.host', '');
        config()->set('hikvision.username', '');
        config()->set('hikvision.password', '');
        config()->set('hikvision.device_id', '');
        config()->set('hikvision.device_name', '');
        config()->set('hikvision.port', 0);

        Http::fake();

        $processingService = Mockery::mock(AttendanceProcessingService::class);
        $processingService->shouldNotReceive('processRawEventsDetailed');
        $processingService->shouldNotReceive('repairIncompleteHistoricalRecordsDetailed');

        $log = (new HikvisionSyncService($processingService))->sync();

        $this->assertSame(AttendanceDeviceSyncLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('HIKVISION_HOST', $log->error);
        $this->assertStringContainsString('HIKVISION_PASSWORD', $log->error);
        $this->assertStringContainsString('HIKVISION_PORT', $log->error);
        Http::assertNothingSent();
    }

    public function test_sync_reloads_complete_days_when_the_requested_window_starts_midday(): void
    {
        $this->insertRawEvent('1001', '2026-06-10 08:00:00');
        $this->insertRawEvent('1001', '2026-06-10 17:05:00');
        $this->insertRawEvent('1001', '2026-06-11 08:02:00');

        $service = new HikvisionSyncService(Mockery::mock(AttendanceProcessingService::class));
        $method = new ReflectionMethod($service, 'loadCompleteEventDays');
        $method->setAccessible(true);

        $eventsVisibleToRollingWindow = [
            [
                'person_id' => '1001',
                'event_datetime' => '2026-06-10 17:05:00',
            ],
            [
                'person_id' => '1001',
                'event_datetime' => '2026-06-11 08:02:00',
            ],
        ];

        $events = $method->invoke($service, $eventsVisibleToRollingWindow);

        $this->assertCount(3, $events);
        $this->assertSame(
            ['2026-06-10 08:00:00', '2026-06-10 17:05:00', '2026-06-11 08:02:00'],
            $events->sortBy('event_datetime')
                ->pluck('event_datetime')
                ->map->format('Y-m-d H:i:s')
                ->values()
                ->all()
        );
    }

    public function test_device_query_range_is_sent_in_the_device_timezone(): void
    {
        config()->set('hikvision.host', '192.168.1.200');
        config()->set('hikvision.port', 80);
        config()->set('hikvision.username', 'admin');
        config()->set('hikvision.password', 'secret');
        config()->set('hikvision.device_id', 'device-1');
        config()->set('hikvision.device_name', 'Main Entrance');

        Http::fake([
            '*' => Http::response([
                'AcsEvent' => [
                    'InfoList' => [],
                    'numOfMatches' => 0,
                    'responseStatusStrg' => 'NO MORE',
                ],
            ]),
        ]);

        $processingService = Mockery::mock(AttendanceProcessingService::class);
        $processingService->shouldReceive('processRawEventsDetailed')
            ->once()
            ->andReturn(new AttendanceProcessingResult());
        $processingService->shouldReceive('repairIncompleteHistoricalRecordsDetailed')
            ->once()
            ->andReturn(new AttendanceProcessingResult());

        (new HikvisionSyncService($processingService))->sync(
            Carbon::parse('2026-06-11 05:00:00', 'UTC'),
            Carbon::parse('2026-06-11 14:00:00', 'UTC')
        );

        Http::assertSent(function ($request) {
            return $request['AcsEventCond']['startTime'] === '2026-06-11T08:00:00'
                && $request['AcsEventCond']['endTime'] === '2026-06-11T17:00:00';
        });
    }

    public function test_offset_device_timestamp_is_converted_to_nairobi_storage_time(): void
    {
        $service = new HikvisionSyncService(Mockery::mock(AttendanceProcessingService::class));
        $method = new ReflectionMethod($service, 'normalizeDeviceTimestamp');
        $method->setAccessible(true);

        $this->assertSame(
            '2026-06-11 00:30:00',
            $method->invoke($service, '2026-06-10T21:30:00Z')
        );
    }

    public function test_plain_device_timestamp_is_interpreted_in_the_device_timezone(): void
    {
        config()->set('hikvision.device_timezone', 'Africa/Nairobi');
        config()->set('hikvision.storage_timezone', 'UTC');

        $service = new HikvisionSyncService(Mockery::mock(AttendanceProcessingService::class));
        $method = new ReflectionMethod($service, 'normalizeDeviceTimestamp');
        $method->setAccessible(true);

        $this->assertSame(
            '2026-06-11 05:00:00',
            $method->invoke($service, '2026-06-11T08:00:00')
        );
    }

    public function test_sync_is_partial_when_device_events_contain_an_unmapped_person(): void
    {
        config()->set('hikvision.host', '192.168.1.200');
        config()->set('hikvision.port', 80);
        config()->set('hikvision.username', 'admin');
        config()->set('hikvision.password', 'secret');
        config()->set('hikvision.device_id', 'device-1');
        config()->set('hikvision.device_name', 'Main Entrance');

        Http::fake([
            '*' => Http::response([
                'AcsEvent' => [
                    'InfoList' => [[
                        'employeeNoString' => 'unmapped-1001',
                        'name' => 'Unmapped Person',
                        'time' => '2026-06-11T08:00:00',
                    ]],
                    'numOfMatches' => 1,
                    'responseStatusStrg' => 'NO MORE',
                ],
            ]),
        ]);

        $log = (new HikvisionSyncService(new AttendanceProcessingService()))->sync(
            Carbon::parse('2026-06-11 00:00:00'),
            Carbon::parse('2026-06-11 23:59:59')
        );

        $this->assertSame(AttendanceDeviceSyncLog::STATUS_PARTIAL, $log->status);
        $this->assertSame(1, $log->records_imported);
        $this->assertSame(0, $log->records_processed);
        $this->assertSame(
            'Partial sync: 1 unmapped person(s); 0 employee-day(s) failed processing.',
            $log->error
        );
    }

    public function test_repeated_device_event_reports_zero_new_imports(): void
    {
        config()->set('hikvision.host', '192.168.1.200');
        config()->set('hikvision.port', 80);
        config()->set('hikvision.username', 'admin');
        config()->set('hikvision.password', 'secret');
        config()->set('hikvision.device_id', 'device-1');
        config()->set('hikvision.device_name', 'Main Entrance');

        $this->insertRawEvent('1001', '2026-06-11 08:00:00');

        Http::fake([
            '*' => Http::response([
                'AcsEvent' => [
                    'InfoList' => [[
                        'employeeNoString' => '1001',
                        'name' => 'Jane Doe',
                        'time' => '2026-06-11T08:00:00',
                    ]],
                    'numOfMatches' => 1,
                    'responseStatusStrg' => 'NO MORE',
                ],
            ]),
        ]);

        $processingService = Mockery::mock(AttendanceProcessingService::class);
        $processingService->shouldReceive('processRawEventsDetailed')
            ->once()
            ->andReturn(new AttendanceProcessingResult());
        $processingService->shouldReceive('repairIncompleteHistoricalRecordsDetailed')
            ->once()
            ->andReturn(new AttendanceProcessingResult());

        $log = (new HikvisionSyncService($processingService))->sync(
            Carbon::parse('2026-06-11 00:00:00'),
            Carbon::parse('2026-06-11 23:59:59')
        );

        $this->assertSame(AttendanceDeviceSyncLog::STATUS_SUCCESS, $log->status);
        $this->assertSame(0, $log->records_imported);
        $this->assertDatabaseCount('attendance_device_raw_events', 1);
        $this->assertSame(1, $log->records_fetched);
        $this->assertSame(1, $log->records_duplicate);
    }

    public function test_malformed_device_response_fails_without_persisting_events(): void
    {
        $this->configureDevice();
        Http::fake(['*' => Http::response(['unexpected' => []])]);

        $log = (new HikvisionSyncService(Mockery::mock(AttendanceProcessingService::class)))->sync(
            Carbon::parse('2026-06-11 00:00:00'),
            Carbon::parse('2026-06-11 23:59:59')
        );

        $this->assertSame(AttendanceDeviceSyncLog::STATUS_FAILED, $log->status);
        $this->assertDatabaseCount('attendance_device_raw_events', 0);
    }

    public function test_transient_device_failure_is_retried(): void
    {
        $this->configureDevice();
        config()->set('hikvision.retry_sleep_ms', 0);
        Http::fakeSequence()
            ->push([], 503)
            ->push([
                'AcsEvent' => [
                    'InfoList' => [],
                    'responseStatusStrg' => 'NO MORE',
                ],
            ], 200);

        $processingService = Mockery::mock(AttendanceProcessingService::class);
        $processingService->shouldReceive('processRawEventsDetailed')->once()->andReturn(new AttendanceProcessingResult());
        $processingService->shouldReceive('repairIncompleteHistoricalRecordsDetailed')->once()->andReturn(new AttendanceProcessingResult());

        $log = (new HikvisionSyncService($processingService))->sync(
            Carbon::parse('2026-06-11 00:00:00'),
            Carbon::parse('2026-06-11 23:59:59')
        );

        $this->assertSame(AttendanceDeviceSyncLog::STATUS_SUCCESS, $log->status);
        Http::assertSentCount(2);
    }

    public function test_sync_is_partial_when_an_employee_day_fails_processing(): void
    {
        config()->set('hikvision.host', '192.168.1.200');
        config()->set('hikvision.port', 80);
        config()->set('hikvision.username', 'admin');
        config()->set('hikvision.password', 'secret');
        config()->set('hikvision.device_id', 'device-1');
        config()->set('hikvision.device_name', 'Main Entrance');

        Http::fake([
            '*' => Http::response([
                'AcsEvent' => [
                    'InfoList' => [],
                    'numOfMatches' => 0,
                    'responseStatusStrg' => 'NO MORE',
                ],
            ]),
        ]);

        $processingService = Mockery::mock(AttendanceProcessingService::class);
        $processingService->shouldReceive('processRawEventsDetailed')
            ->once()
            ->andReturn(new AttendanceProcessingResult(4, 0, 1));
        $processingService->shouldReceive('repairIncompleteHistoricalRecordsDetailed')
            ->once()
            ->andReturn(new AttendanceProcessingResult());

        $log = (new HikvisionSyncService($processingService))->sync(
            Carbon::parse('2026-06-11 00:00:00'),
            Carbon::parse('2026-06-11 23:59:59')
        );

        $this->assertSame(AttendanceDeviceSyncLog::STATUS_PARTIAL, $log->status);
        $this->assertSame(4, $log->records_processed);
        $this->assertStringContainsString('1 employee-day(s)', $log->error);
    }

    public function test_incomplete_historical_record_is_rebuilt_from_the_full_day(): void
    {
        $employeeId = $this->insertEmployee('1001');
        $this->insertRawEvent('1001', '2026-06-10 08:00:00');
        $this->insertRawEvent('1001', '2026-06-10 17:05:00');

        DB::table('attendance_records')->insert([
            'employee_id' => $employeeId,
            'date' => '2026-06-10',
            'clock_in' => '17:05:00',
            'clock_out' => null,
            'device_clock_in' => '17:05:00',
            'device_clock_out' => null,
            'status' => 'missing_clock_out',
            'is_manual' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $processed = (new AttendanceProcessingService())->processRawEvents(
            AttendanceDeviceRawEvent::all()
        );

        $record = DB::table('attendance_records')->first();

        $this->assertSame(1, $processed);
        $this->assertSame('08:00:00', $record->clock_in);
        $this->assertSame('17:05:00', $record->clock_out);
        $this->assertSame('present', $record->status);
    }

    public function test_complete_historical_record_is_not_overwritten_by_later_syncs(): void
    {
        $employeeId = $this->insertEmployee('1001');
        $this->insertRawEvent('1001', '2026-06-10 07:55:00');
        $this->insertRawEvent('1001', '2026-06-10 18:00:00');

        DB::table('attendance_records')->insert([
            'employee_id' => $employeeId,
            'date' => '2026-06-10',
            'clock_in' => '08:00:00',
            'clock_out' => '17:05:00',
            'device_clock_in' => '08:00:00',
            'device_clock_out' => '17:05:00',
            'status' => 'present',
            'work_hours' => 9.08,
            'overtime_hours' => 0,
            'is_manual' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $processed = (new AttendanceProcessingService())->processRawEvents(
            AttendanceDeviceRawEvent::all()
        );

        $record = DB::table('attendance_records')->first();

        $this->assertSame(0, $processed);
        $this->assertSame('08:00:00', $record->clock_in);
        $this->assertSame('17:05:00', $record->clock_out);
        $this->assertSame('08:00:00', $record->device_clock_in);
        $this->assertSame('17:05:00', $record->device_clock_out);
    }

    public function test_sync_reconciliation_repairs_older_incomplete_records_from_saved_raw_events(): void
    {
        $employeeId = $this->insertEmployee('1001');
        $this->insertRawEvent('1001', '2026-06-08 08:03:00');
        $this->insertRawEvent('1001', '2026-06-08 17:10:00');

        DB::table('attendance_records')->insert([
            'employee_id' => $employeeId,
            'date' => '2026-06-08',
            'clock_in' => '17:10:00',
            'clock_out' => null,
            'device_clock_in' => '17:10:00',
            'device_clock_out' => null,
            'status' => 'missing_clock_out',
            'is_manual' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $processed = (new AttendanceProcessingService())
            ->repairIncompleteHistoricalRecords();

        $record = DB::table('attendance_records')->first();

        $this->assertSame(1, $processed);
        $this->assertSame('08:03:00', $record->clock_in);
        $this->assertSame('17:10:00', $record->clock_out);
        $this->assertSame('present', $record->status);
    }

    private function insertEmployee(string $hikvisionId): int
    {
        return DB::table('employees')->insertGetId([
            'hikvision_id' => $hikvisionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function configureDevice(): void
    {
        config()->set('hikvision.host', '192.168.1.200');
        config()->set('hikvision.port', 80);
        config()->set('hikvision.username', 'admin');
        config()->set('hikvision.password', 'secret');
        config()->set('hikvision.device_id', 'device-1');
        config()->set('hikvision.device_name', 'Main Entrance');
    }

    private function insertRawEvent(string $personId, string $eventDatetime): void
    {
        DB::table('attendance_device_raw_events')->insert([
            'person_id' => $personId,
            'event_datetime' => $eventDatetime,
            'source' => 'api_sync',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
