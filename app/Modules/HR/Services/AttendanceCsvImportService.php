<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceDeviceRawEvent;
use App\Modules\HR\Models\AttendanceDeviceSyncLog;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AttendanceCsvImportService
{
    public function __construct(
        private readonly AttendanceProcessingService $processingService
    ) {}

    /**
     * Parse the file and return a preview summary without writing to the database.
     */
    public function preview(UploadedFile $file): array
    {
        $rows = $this->parseFile($file);

        if ($rows->isEmpty()) {
            return [
                'total_events' => 0,
                'unique_persons' => 0,
                'date_range' => null,
                'sample_rows' => [],
                'unmapped_persons' => [],
            ];
        }

        $personIds = $rows->pluck('person_id')
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $mappedIds = $this->mappedPersonIds($personIds);

        $unmappedPersons = $rows
            ->filter(fn ($row) => !in_array($row['person_id'], $mappedIds, true))
            ->unique('person_id')
            ->map(fn ($r) => ['person_id' => $r['person_id'], 'person_name' => $r['person_name']])
            ->values()
            ->toArray();

        $datetimes = $rows->pluck('event_datetime')->filter()->sort()->values();

        return [
            'total_events' => $rows->count(),
            'unique_persons' => count($personIds),
            'date_range' => $datetimes->isNotEmpty() ? [
                'from' => $datetimes->first(),
                'to' => $datetimes->last(),
            ] : null,
            'sample_rows' => $rows->take(10)->values()->toArray(),
            'unmapped_persons' => $unmappedPersons,
        ];
    }

    /**
     * Parse the file, insert raw events, and trigger attendance processing.
     * Returns the created sync log.
     */
    public function commit(UploadedFile $file): AttendanceDeviceSyncLog
    {
        $rows = $this->parseFile($file);

        $syncLog = AttendanceDeviceSyncLog::create([
            'device_id' => 'manual_upload',
            'device_name' => 'Manual CSV Upload',
            'synced_at' => now(),
            'records_imported' => 0,
            'records_processed' => 0,
            'status' => AttendanceDeviceSyncLog::STATUS_SUCCESS,
        ]);

        try {
            $imported = $this->insertRawEvents($rows, $syncLog->id, AttendanceDeviceRawEvent::SOURCE_CSV_UPLOAD);

            // Fetch all matching events by person_id + date range (not sync_log_id) so that
            // re-uploads of the same file still process correctly even when rows already exist.
            $personIds = $rows->pluck('person_id')->unique()->values()->toArray();
            $datetimes = $rows->pluck('event_datetime')->filter()->sort()->values();

            $query = AttendanceDeviceRawEvent::whereIn('person_id', $personIds);
            if ($datetimes->isNotEmpty()) {
                $minDate = Carbon::parse($datetimes->first())->toDateString();
                $maxDate = Carbon::parse($datetimes->last())->toDateString();
                $query->whereBetween(DB::raw('DATE(event_datetime)'), [$minDate, $maxDate]);
            }
            $savedEvents = $query->get();
            $processed = $this->processingService->processRawEvents($savedEvents, $syncLog->id);

            $syncLog->update([
                'records_imported' => $imported,
                'records_processed' => $processed,
                'status' => AttendanceDeviceSyncLog::STATUS_SUCCESS,
            ]);
        } catch (\Throwable $e) {
            Log::error('AttendanceCsvImportService commit failed: ' . $e->getMessage());
            $syncLog->update([
                'status' => AttendanceDeviceSyncLog::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $syncLog;
    }

    /**
     * Parse the uploaded file (CSV or Excel) and return a collection of normalised row arrays.
     */
    public function parseFile(UploadedFile $file): Collection
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'])) {
            return $this->parseExcel($file);
        }

        return $this->parseCsv($file);
    }

    private function parseCsv(UploadedFile $file): Collection
    {
        $rows = collect();
        $handle = fopen($file->getRealPath(), 'r');

        if (!$handle) {
            return $rows;
        }

        // Strip UTF-8 BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Auto-detect delimiter from the first line
        $firstLine = fgets($handle);
        $delimiter = str_contains($firstLine, "\t") ? "\t" : ',';
        rewind($handle);
        if ($bom === "\xEF\xBB\xBF") {
            fread($handle, 3); // re-skip BOM after rewind
        }

        $header = true;
        $seen = [];

        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($header) {
                $header = false;
                continue;
            }

            $parsed = $this->normaliseRow($line);
            if (!$parsed) {
                continue;
            }

            $key = $parsed['person_id'] . '|' . $parsed['event_datetime'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows->push($parsed);
        }

        fclose($handle);
        return $rows;
    }

    private function parseExcel(UploadedFile $file): Collection
    {
        // PhpSpreadsheet is available in most Laravel projects; use it if present.
        // If not installed, fall back to treating the file as CSV.
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            return $this->parseCsv($file);
        }

        $rows = collect();
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $firstRow = true;
        $seen = [];

        foreach ($sheet->getRowIterator() as $row) {
            if ($firstRow) {
                $firstRow = false;
                continue;
            }

            $cells = [];
            foreach ($row->getCellIterator('A', 'K') as $cell) {
                $cells[] = $cell->getFormattedValue();
            }

            $parsed = $this->normaliseRow($cells);
            if (!$parsed) {
                continue;
            }

            $key = $parsed['person_id'] . '|' . $parsed['event_datetime'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows->push($parsed);
        }

        return $rows;
    }

    /**
     * Normalise a raw CSV/Excel row into a consistent array.
     * Hikvision column order: PersonID | Name | Department | Time | AttendStatus | CheckPoint | CustomName | DataSource | HandlingType | Temperature | Abnormal
     */
    private function normaliseRow(array $cells): ?array
    {
        // Ensure we have at least 4 columns (PersonID, Name, Department, Time)
        if (count($cells) < 4) {
            return null;
        }

        // Column 0: Person ID - strip leading apostrophe and numeric formatting.
        $personId = preg_replace('/[\s,]/', '', ltrim(trim((string) ($cells[0] ?? '')), "'"));
        if ($personId === '') {
            return null;
        }

        $personName = trim((string) ($cells[1] ?? ''));
        $department = trim((string) ($cells[2] ?? '')) ?: null;

        // Column 3: Time — "YYYY-MM-DD HH:MM:SS"
        $rawTime = trim((string) ($cells[3] ?? ''));
        try {
            $eventDatetime = Carbon::createFromFormat('Y-m-d H:i:s', $rawTime);
        } catch (\Throwable) {
            return null; // Invalid datetime — skip row
        }

        // Column 5: Attendance Check Point — null if blank or "-"
        $checkPoint = trim((string) ($cells[5] ?? ''));
        if ($checkPoint === '' || $checkPoint === '-') {
            $checkPoint = null;
        }

        return [
            'person_id'       => $personId,
            'person_name'     => $personName,
            'department'      => $department,
            'event_datetime'  => $eventDatetime->format('Y-m-d H:i:s'),
            'check_point'     => $checkPoint,
        ];
    }

    /**
     * Bulk insert parsed rows into attendance_device_raw_events, ignoring duplicates.
     * Returns the count of newly inserted rows.
     */
    private function insertRawEvents(Collection $rows, int $syncLogId, string $source): int
    {
        if ($rows->isEmpty()) {
            return 0;
        }

        $now = now()->toDateTimeString();
        $chunks = $rows->map(fn ($r) => [
            'person_id'      => $r['person_id'],
            'person_name'    => $r['person_name'],
            'event_datetime' => $r['event_datetime'],
            'check_point'    => $r['check_point'],
            'department'     => $r['department'],
            'source'         => $source,
            'sync_log_id'    => $syncLogId,
            'created_at'     => $now,
            'updated_at'     => $now,
        ])->chunk(500);

        $inserted = 0;
        foreach ($chunks as $chunk) {
            $inserted += DB::table('attendance_device_raw_events')
                ->insertOrIgnore($chunk->toArray());
        }

        return $inserted;
    }

    /**
     * CSV Person ID is the Hikvision device ID. Keep id_number as a fallback for
     * older files or tenants where the device was configured with national IDs.
     */
    private function mappedPersonIds(array $personIds): array
    {
        $columns = ['id'];
        if (Schema::hasColumn('employees', 'hikvision_id')) {
            $columns[] = 'hikvision_id';
        }
        if (Schema::hasColumn('employees', 'id_number')) {
            $columns[] = 'id_number';
        }

        return Employee::query()
            ->get($columns)
            ->flatMap(function ($employee) {
                return [
                    trim((string) ($employee->hikvision_id ?? '')),
                    trim((string) ($employee->id_number ?? '')),
                ];
            })
            ->filter()
            ->intersect($personIds)
            ->values()
            ->toArray();
    }
}
