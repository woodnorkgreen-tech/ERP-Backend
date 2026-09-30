<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Printing\Services\PrintHistoryWorkbookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PrintHistoryImportController extends Controller
{
    public function __construct(private readonly PrintHistoryWorkbookService $workbook)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);
        $file = $request->file('file');
        $path = $file->storeAs('printing-history', Str::uuid() . '.xlsx', 'local');
        $cutoff = Carbon::today('Africa/Nairobi')->toDateString();
        try {
            $this->workbook->machineIds();
            $parsed = $this->workbook->parse(Storage::disk('local')->path($path), $cutoff);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            return response()->json(['message' => $error->getMessage()], 422);
        }

        $batchId = DB::table('print_history_import_batches')->insertGetId([
            'original_name' => $file->getClientOriginalName(),
            'file_sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
            'stored_path' => $path,
            'cutoff_date' => $cutoff,
            'status' => 'previewed',
            'preview_summary' => json_encode($parsed['summary'], JSON_INVALID_UTF8_SUBSTITUTE),
            'uploaded_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => [
            'batch_id' => $batchId,
            'file_name' => $file->getClientOriginalName(),
            'cutoff_date' => $cutoff,
            'summary' => $parsed['summary'],
        ]]);
    }

    public function commit(Request $request, int $batchId): JsonResponse
    {
        try {
            $result = DB::transaction(function () use ($batchId) {
                $batch = DB::table('print_history_import_batches')->where('id', $batchId)->lockForUpdate()->first();
                if (!$batch || (int)$batch->uploaded_by !== (int)auth()->id()) {
                    throw new RuntimeException('Import preview not found for this user.');
                }
                if ($batch->status === 'committed') {
                    return ['batch_id' => $batchId, 'imported' => (int)$batch->imported_count, 'already_committed' => true];
                }
                if ($batch->status !== 'previewed' || !Storage::disk('local')->exists($batch->stored_path)) {
                    throw new RuntimeException('The preview file is no longer available. Upload it again.');
                }
                $path = Storage::disk('local')->path($batch->stored_path);
                if (hash_file('sha256', $path) !== $batch->file_sha256) {
                    throw new RuntimeException('The uploaded workbook changed after preview. Upload it again.');
                }
                $machines = $this->workbook->machineIds();
                $materials = $this->workbook->materialIds();
                $parsed = $this->workbook->parse($path, $batch->cutoff_date);
                $count = 0;
                $now = now();
                foreach ($parsed['rows'] as $data) {
                    if ($data['exclusion']) continue;
                    $jobId = DB::table('print_jobs')->insertGetId([
                        'origin' => 'historical_import',
                        'job_number' => 'HIST-' . strtoupper(Str::slug(trim($data['sheet']), '-')) . '-' . $data['row'],
                        'project_name' => $this->short($data['project_name']),
                        'client_name' => $this->short($data['client']),
                        'title' => $this->short($data['item'] ?: ($data['project_name'] ?: 'Historical print job')),
                        'description' => $data['item'],
                        'order_type' => strtolower((string)$data['type']) === 'reprint' ? 'reprint' : 'original',
                        'status' => 'completed',
                        'completed_at' => $data['work_date'] . ' 00:00:00',
                        'design_height_m' => $this->decimal($data['art_width']),
                        'design_length_m' => $this->decimal($data['art_height']),
                        'print_width_m' => $this->decimal($data['print_width']),
                        'running_length_m' => $data['running_m'],
                        'artwork_quantity' => $this->decimal($data['quantity']),
                        'machine_asset_id' => $machines[$data['machine_name']],
                        'machine_name_snapshot' => $data['machine_name'],
                        'remarks' => 'Historical tracker import. Date has day precision; source tab and row are recorded separately.',
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('print_job_consumptions')->insert([
                        'print_job_id' => $jobId,
                        'print_roll_id' => null,
                        'material_id' => $materials[$data['material_family']] ?? null,
                        'material_name_snapshot' => $data['material_family'],
                        'material_family' => $data['material_family'],
                        'artwork_width_m' => $this->decimal($data['art_width']),
                        'artwork_height_m' => $this->decimal($data['art_height']),
                        'artwork_count' => max(1, (int)round((float)($data['quantity'] ?: 1))),
                        'quantity' => 1,
                        'tile_count' => 1,
                        'calculated_print_width_m' => $this->decimal($data['print_width']),
                        'calculated_print_length_m' => $this->decimal($data['print_length']),
                        'calculated_sqm' => $data['area_sqm'],
                        'calculated_running_m' => $data['running_m'],
                        'actual_running_m' => $data['running_m'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    DB::table('print_history_source_rows')->insert([
                        'print_history_import_batch_id' => $batchId,
                        'print_job_id' => $jobId,
                        'source_key' => $data['source_key'],
                        'sheet_name' => $data['sheet'],
                        'sheet_row' => $data['row'],
                        'date_source' => $data['date_source'],
                        'project_inferred' => $data['project_inferred'],
                        'project_reference' => $this->short($data['project_reference']),
                        'material_label' => $this->short($data['material_label']),
                        'material_family' => $data['material_family'],
                        'recorded_usage_value' => $data['recorded_usage_value'],
                        'recorded_usage_unit' => $data['usage_unit'],
                        'source_values' => json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    DB::table('print_job_events')->insert([
                        'print_job_id' => $jobId,
                        'event_type' => 'historical_imported',
                        'to_status' => 'completed',
                        'payload' => json_encode(['batch_id' => $batchId, 'sheet' => $data['sheet'], 'row' => $data['row']]),
                        'created_by' => auth()->id(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $count++;
                }
                DB::table('print_history_import_batches')->where('id', $batchId)->update([
                    'status' => 'committed', 'imported_count' => $count, 'committed_at' => now(), 'updated_at' => now(),
                ]);
                return ['batch_id' => $batchId, 'imported' => $count, 'summary' => $parsed['summary']];
            });
        } catch (RuntimeException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    private function short(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : mb_substr((string)$value, 0, 255);
    }

    private function decimal(mixed $value): ?float
    {
        return is_numeric($value) && (float)$value > 0 ? round((float)$value, 3) : null;
    }
}
