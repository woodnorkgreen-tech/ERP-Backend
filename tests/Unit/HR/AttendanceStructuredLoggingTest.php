<?php

namespace Tests\Unit\HR;

use App\Modules\HR\Data\AttendanceProcessingResult;
use App\Modules\HR\Models\AttendanceDeviceRawEvent;
use App\Modules\HR\Services\AttendanceProcessingService;
use App\Modules\HR\Services\HikvisionSyncService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AttendanceStructuredLoggingTest extends TestCase
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

        config()->set('hikvision.host', '192.168.1.200');
        config()->set('hikvision.port', 80);
        config()->set('hikvision.username', 'admin');
        config()->set('hikvision.password', 'super-secret-password');
        config()->set('hikvision.device_id', 'device-1');
        config()->set('hikvision.device_name', 'Main Entrance');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_successful_sync_logs_structured_operational_context_without_credentials(): void
    {
        Carbon::setTestNow('2026-06-11 19:00:00');
        Log::spy();
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
            Carbon::parse('2026-06-10 00:00:00'),
            Carbon::parse('2026-06-11 19:00:00')
        );

        Log::shouldHaveReceived('info')
            ->with('attendance.sync.started', Mockery::on(function (array $context) {
                return $context['source'] === AttendanceDeviceRawEvent::SOURCE_API_SYNC
                    && $context['range_from'] === '2026-06-10T00:00:00+03:00'
                    && !array_key_exists('username', $context)
                    && !array_key_exists('password', $context);
            }))
            ->once();

        Log::shouldHaveReceived('info')
            ->with('attendance.sync.completed', Mockery::on(function (array $context) {
                return $context['events_fetched'] === 0
                    && $context['records_imported'] === 0
                    && $context['records_processed'] === 0;
            }))
            ->once();
    }

    public function test_failed_sync_logs_exception_type_without_exception_message_or_credentials(): void
    {
        Log::spy();
        Http::fake(fn () => throw new \RuntimeException(
            'Connection failed using super-secret-password for employee 1001'
        ));

        $processingService = Mockery::mock(AttendanceProcessingService::class);
        $processingService->shouldNotReceive('processRawEventsDetailed');
        $processingService->shouldNotReceive('repairIncompleteHistoricalRecordsDetailed');

        (new HikvisionSyncService($processingService))->sync(
            Carbon::parse('2026-06-10 00:00:00'),
            Carbon::parse('2026-06-11 19:00:00')
        );

        Log::shouldHaveReceived('error')
            ->with('attendance.sync.failed', Mockery::on(function (array $context) {
                $encoded = json_encode($context);

                return $context['failure_type'] === 'runtime_exception'
                    && $context['exception_class'] === \RuntimeException::class
                    && !str_contains($encoded, 'super-secret-password')
                    && !str_contains($encoded, '1001');
            }))
            ->once();
    }
}
