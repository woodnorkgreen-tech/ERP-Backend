<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceDeviceRawEvent;
use App\Modules\HR\Models\AttendanceDeviceSyncLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HikvisionSyncService
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $deviceId;
    private string $deviceName;

    public function __construct(
        private readonly AttendanceProcessingService $processingService
    ) {
        $this->host = config('hikvision.host');
        $this->port = (int) config('hikvision.port');
        $this->username = config('hikvision.username');
        $this->password = config('hikvision.password');
        $this->deviceId = config('hikvision.device_id');
        $this->deviceName = config('hikvision.device_name');
    }

    /**
     * Sync attendance events from the Hikvision device for the given time range.
     * Defaults to the last 24 hours if no range is provided.
     */
    public function sync(?Carbon $from = null, ?Carbon $to = null): AttendanceDeviceSyncLog
    {
        $from = $from ?? now()->subDay();
        $to   = $to   ?? now();

        $syncLog = AttendanceDeviceSyncLog::create([
            'device_id'         => $this->deviceId,
            'device_name'       => $this->deviceName,
            'synced_at'         => now(),
            'records_imported'  => 0,
            'records_processed' => 0,
            'status'            => AttendanceDeviceSyncLog::STATUS_SUCCESS,
        ]);

        try {
            $events = $this->fetchEventsFromDevice($from, $to);
            $imported = $this->insertRawEvents($events, $syncLog->id);

            $savedEvents = AttendanceDeviceRawEvent::where('sync_log_id', $syncLog->id)->get();
            $processed = $this->processingService->processRawEvents($savedEvents, $syncLog->id);

            $syncLog->update([
                'records_imported'  => $imported,
                'records_processed' => $processed,
                'status'            => AttendanceDeviceSyncLog::STATUS_SUCCESS,
            ]);
        } catch (\Throwable $e) {
            Log::error('HikvisionSyncService sync failed: ' . $e->getMessage());
            $syncLog->update([
                'status' => AttendanceDeviceSyncLog::STATUS_FAILED,
                'error'  => $e->getMessage(),
            ]);
        }

        return $syncLog;
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

        $all      = [];
        $position = 0;
        $pageSize = 10;

        do {
            $payload = [
                'AcsEventCond' => [
                    'searchID'             => $searchId,
                    'searchResultPosition' => $position,
                    'maxResults'           => $pageSize,
                    'major'                => 0,
                    'minor'                => 0,
                    'startTime'            => $from->format('Y-m-d\TH:i:s'),
                    'endTime'              => $to->format('Y-m-d\TH:i:s'),
                ],
            ];

            $response = Http::withDigestAuth($this->username, $this->password)
                ->timeout(30)
                ->post($baseUrl . $endpoint, $payload);

            if ($response->failed()) {
                throw new \RuntimeException(
                    "Hikvision API request failed: HTTP {$response->status()}"
                );
            }

            $body    = $response->json();
            $event   = $body['AcsEvent'] ?? [];
            $records = $event['InfoList'] ?? [];
            $matched = (int) ($event['numOfMatches'] ?? 0);
            $status  = $event['responseStatusStrg'] ?? 'NO MORE';

            foreach ($records as $item) {
                $all[] = [
                    'person_id'      => str_replace(',', '', (string) ($item['employeeNoString'] ?? $item['employeeNo'] ?? '')),
                    'person_name'    => (string) ($item['name'] ?? ''),
                    'event_datetime' => Carbon::parse($item['time'])->format('Y-m-d H:i:s'),
                    'check_point'    => $item['name'] ?? null,
                    'department'     => null,
                ];
            }

            $position += $matched;

        } while ($status === 'MORE' && $matched > 0);

        return $all;
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
        foreach ($chunks as $chunk) {
            DB::table('attendance_device_raw_events')->insertOrIgnore($chunk);
        }

        return count($events);
    }
}
