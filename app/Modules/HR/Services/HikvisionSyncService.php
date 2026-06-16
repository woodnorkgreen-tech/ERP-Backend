<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceDeviceRawEvent;
use App\Modules\HR\Models\AttendanceDeviceSyncLog;
use App\Modules\HR\Support\AttendanceTimestampNormalizer;
use App\Modules\HR\Support\AttendancePersonId;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class HikvisionSyncService
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $deviceId;
    private string $deviceName;
    private string $deviceTimezone;
    private string $storageTimezone;

    public function __construct(
        private readonly AttendanceProcessingService $processingService
    ) {
        $this->host = trim((string) config('hikvision.host'));
        $this->port = (int) config('hikvision.port');
        $this->username = trim((string) config('hikvision.username'));
        $this->password = (string) config('hikvision.password');
        $this->deviceId = trim((string) config('hikvision.device_id'));
        $this->deviceName = trim((string) config('hikvision.device_name'));
        $this->deviceTimezone = trim((string) config('hikvision.device_timezone'));
        $this->storageTimezone = trim((string) config('hikvision.storage_timezone'));
    }

    /**
     * Sync attendance events from the Hikvision device for the given time range.
     * Defaults to the configured rolling lookback if no range is provided.
     */
    public function sync(?Carbon $from = null, ?Carbon $to = null): AttendanceDeviceSyncLog
    {
        $to   = $to   ?? now();
        $from = $from ?? $this->defaultSyncStart($to);
        $configurationError = $this->configurationError();

        $syncLog = AttendanceDeviceSyncLog::create([
            'device_id'         => $this->deviceId ?: 'unconfigured',
            'device_name'       => $this->deviceName ?: 'Unconfigured Hikvision Device',
            'synced_at'         => now(),
            'range_from'        => $from,
            'range_to'          => $to,
            'records_fetched'   => 0,
            'records_imported'  => 0,
            'records_processed' => 0,
            'status'            => $configurationError
                ? AttendanceDeviceSyncLog::STATUS_FAILED
                : AttendanceDeviceSyncLog::STATUS_SUCCESS,
            'error'             => $configurationError,
        ]);

        Log::info('attendance.sync.started', [
            'sync_log_id' => $syncLog->id,
            'source' => AttendanceDeviceRawEvent::SOURCE_API_SYNC,
            'device_id' => $syncLog->device_id,
            'range_from' => $from->toIso8601String(),
            'range_to' => $to->toIso8601String(),
        ]);

        if ($configurationError) {
            Log::error('attendance.sync.failed', [
                'sync_log_id' => $syncLog->id,
                'source' => AttendanceDeviceRawEvent::SOURCE_API_SYNC,
                'failure_type' => 'invalid_configuration',
            ]);

            return $syncLog;
        }

        try {
            $events = $this->fetchEventsFromDevice($from, $to);
            [$imported, $processingResult, $status] = DB::transaction(function () use ($events, $syncLog) {
                $imported = $this->insertRawEvents($events, $syncLog->id);
                $processingResult = $this->processingService
                    ->processRawEventsDetailed($this->loadCompleteEventDays($events), $syncLog->id)
                    ->merge($this->processingService->repairIncompleteHistoricalRecordsDetailed($syncLog->id));
                $status = $processingResult->isPartial()
                    ? AttendanceDeviceSyncLog::STATUS_PARTIAL
                    : AttendanceDeviceSyncLog::STATUS_SUCCESS;
                $syncLog->update([
                    'records_fetched' => count($events),
                    'records_imported' => $imported,
                    'records_duplicate' => max(0, count($events) - $imported),
                    'records_processed' => $processingResult->recordsProcessed,
                    'records_unmapped' => $processingResult->unmappedPersonCount,
                    'records_failed' => $processingResult->failedPersonDayCount,
                    'status' => $status,
                    'error' => $processingResult->summary(),
                ]);
                return [$imported, $processingResult, $status];
            });

            Log::info('attendance.sync.completed', [
                'sync_log_id' => $syncLog->id,
                'source' => AttendanceDeviceRawEvent::SOURCE_API_SYNC,
                'events_fetched' => count($events),
                'records_imported' => $imported,
                'records_processed' => $processingResult->recordsProcessed,
                'unmapped_person_count' => $processingResult->unmappedPersonCount,
                'failed_person_day_count' => $processingResult->failedPersonDayCount,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            $syncLog->update([
                'status' => AttendanceDeviceSyncLog::STATUS_FAILED,
                'error'  => $e->getMessage(),
            ]);

            Log::error('attendance.sync.failed', [
                'sync_log_id' => $syncLog->id,
                'source' => AttendanceDeviceRawEvent::SOURCE_API_SYNC,
                'failure_type' => 'runtime_exception',
                'exception_class' => $e::class,
            ]);
        }

        return $syncLog;
    }

    private function configurationError(): ?string
    {
        $missing = [];

        foreach ([
            'HIKVISION_HOST' => $this->host,
            'HIKVISION_USERNAME' => $this->username,
            'HIKVISION_PASSWORD' => $this->password,
            'HIKVISION_DEVICE_ID' => $this->deviceId,
            'HIKVISION_DEVICE_NAME' => $this->deviceName,
            'HIKVISION_DEVICE_TIMEZONE' => $this->deviceTimezone,
            'ATTENDANCE_STORAGE_TIMEZONE' => $this->storageTimezone,
        ] as $key => $value) {
            if (trim($value) === '') {
                $missing[] = $key;
            }
        }

        if ($this->port < 1 || $this->port > 65535) {
            $missing[] = 'HIKVISION_PORT (must be between 1 and 65535)';
        }

        foreach ([
            'HIKVISION_DEVICE_TIMEZONE' => $this->deviceTimezone,
            'ATTENDANCE_STORAGE_TIMEZONE' => $this->storageTimezone,
        ] as $key => $timezone) {
            if ($timezone !== '' && !in_array($timezone, timezone_identifiers_list(), true)) {
                $missing[] = "{$key} (invalid timezone)";
            }
        }

        return $missing === []
            ? null
            : 'Missing or invalid Hikvision configuration: ' . implode(', ', $missing);
    }

    private function defaultSyncStart(Carbon $to): Carbon
    {
        $lookbackDays = max(1, (int) config('hikvision.sync_lookback_days', 30));
        $overlapHours = max(0, (int) config('hikvision.sync_overlap_hours', 24));
        $oldestAllowed = $to->copy()->subDays($lookbackDays);

        $lastSuccessfulSync = AttendanceDeviceSyncLog::query()
            ->where('status', AttendanceDeviceSyncLog::STATUS_SUCCESS)
            ->where('synced_at', '<=', $to)
            ->latest('synced_at')
            ->first();

        if (!$lastSuccessfulSync) {
            return $oldestAllowed;
        }

        $incrementalStart = $lastSuccessfulSync->synced_at
            ->copy()
            ->subHours($overlapHours);

        return $incrementalStart->lt($oldestAllowed)
            ? $oldestAllowed
            : $incrementalStart;
    }

    /**
     * A rolling sync range can cut through a workday. Reload every affected
     * person's complete calendar days so an evening scan is never mistaken for
     * that day's clock-in merely because the morning scan fell outside the
     * requested range.
     */
    private function loadCompleteEventDays(array $events): Collection
    {
        if (empty($events)) {
            return collect();
        }

        $personIds = collect($events)
            ->pluck('person_id')
            ->filter()
            ->unique()
            ->values();

        $eventDates = collect($events)
            ->pluck('event_datetime')
            ->filter()
            ->map(fn ($datetime) => Carbon::parse($datetime)->toDateString())
            ->unique()
            ->sort()
            ->values();

        if ($personIds->isEmpty() || $eventDates->isEmpty()) {
            return collect();
        }

        $from = Carbon::parse($eventDates->first())->startOfDay();
        $to = Carbon::parse($eventDates->last())->endOfDay();

        return AttendanceDeviceRawEvent::query()
            ->whereIn('person_id', $personIds)
            ->whereBetween('event_datetime', [$from, $to])
            ->get();
    }

    /**
     * Fetch access control events from the Hikvision ISAPI.
     * Paginates automatically — the DS-K1T80x series caps at 10 results per page.
     * Returns a flat array of normalised event arrays.
     */
    private function fetchEventsFromDevice(Carbon $from, Carbon $to): array
    {
        $baseUrl  = "http://{$this->host}:{$this->port}";
        $endpoint = '/ISAPI/AccessControl/AcsEvent?format=json';
        $searchId = 'sync_' . time();
        $deviceFrom = $from->copy()->setTimezone($this->deviceTimezone);
        $deviceTo = $to->copy()->setTimezone($this->deviceTimezone);

        $all      = [];
        $position = 0;
        $pageSize = 10;
        $page = 0;
        $maxPages = max(1, (int) config('hikvision.max_pages', 10000));

        do {
            $payload = [
                'AcsEventCond' => [
                    'searchID'             => $searchId,
                    'searchResultPosition' => $position,
                    'maxResults'           => $pageSize,
                    'major'                => 0,
                    'minor'                => 0,
                    'startTime'            => $deviceFrom->format('Y-m-d\TH:i:s'),
                    'endTime'              => $deviceTo->format('Y-m-d\TH:i:s'),
                ],
            ];

            if (++$page > $maxPages) {
                throw new \RuntimeException('Hikvision pagination exceeded the configured page limit.');
            }

            $response = Http::withDigestAuth($this->username, $this->password)
                ->timeout(max(1, (int) config('hikvision.request_timeout_seconds', 30)))
                ->retry(
                    max(1, (int) config('hikvision.retry_times', 3)),
                    max(0, (int) config('hikvision.retry_sleep_ms', 500)),
                    fn ($exception) => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && in_array($exception->response->status(), [408, 425, 429, 500, 502, 503, 504], true)),
                    false
                )
                ->post($baseUrl . $endpoint, $payload);

            if ($response->failed()) {
                throw new \RuntimeException(
                    "Hikvision API request failed: HTTP {$response->status()}"
                );
            }

            $body = $response->json();
            if (!is_array($body) || !isset($body['AcsEvent']) || !is_array($body['AcsEvent'])) {
                throw new \RuntimeException('Hikvision response is missing AcsEvent.');
            }
            $event = $body['AcsEvent'];
            $records = $event['InfoList'] ?? null;
            $status = strtoupper(trim((string) ($event['responseStatusStrg'] ?? '')));
            if (!is_array($records) || !in_array($status, ['MORE', 'NO MORE'], true)) {
                throw new \RuntimeException('Hikvision response has an invalid event list or pagination status.');
            }

            foreach ($records as $item) {
                if (!is_array($item)) {
                    throw new \RuntimeException('Hikvision response contains a malformed event.');
                }
                $personId = AttendancePersonId::normalize($item['employeeNoString'] ?? $item['employeeNo'] ?? '');
                $timestamp = trim((string) ($item['time'] ?? ''));
                if ($personId === '' || $timestamp === '') {
                    throw new \RuntimeException('Hikvision event is missing person ID or time.');
                }
                $all[] = [
                    'person_id'      => $personId,
                    'person_name'    => (string) ($item['name'] ?? ''),
                    'event_datetime' => $this->normalizeDeviceTimestamp(
                        $timestamp
                    ),
                    'check_point'    => $item['name'] ?? null,
                    'department'     => null,
                ];
            }

            $count = count($records);
            if ($status === 'MORE' && $count === 0) {
                throw new \RuntimeException('Hikvision pagination stalled on an empty page.');
            }
            $position += $count;
        } while ($status === 'MORE');

        return $all;
    }

    private function normalizeDeviceTimestamp(string $timestamp): string
    {
        return AttendanceTimestampNormalizer::deviceToStorage(
            $timestamp,
            $this->deviceTimezone,
            $this->storageTimezone
        );
    }

    private function insertRawEvents(array $events, int $syncLogId): int
    {
        if (empty($events)) {
            return 0;
        }

        $now = now()->toDateTimeString();
        $rows = array_map(fn ($e) => array_merge($e, [
            'source'      => AttendanceDeviceRawEvent::SOURCE_API_SYNC,
            'sync_log_id' => $syncLogId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]), $events);

        $chunks = array_chunk($rows, 500);
        $inserted = 0;
        foreach ($chunks as $chunk) {
            $inserted += DB::table('attendance_device_raw_events')
                ->insertOrIgnore($chunk);
        }

        return $inserted;
    }
}
