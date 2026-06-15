<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Exceptions\AttendanceRecordConflictException;
use App\Modules\HR\Models\AttendanceDeviceSyncLog;
use App\Modules\HR\Models\AttendanceSyncRequest;
use App\Modules\HR\Jobs\SyncHikvisionAttendanceJob;
use App\Modules\HR\Services\AttendanceCsvImportService;
use App\Modules\HR\Services\AttendanceService;
use App\Modules\HR\Services\AttendanceExceptionService;
use App\Modules\HR\Services\AttendanceReprocessingService;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly AttendanceCsvImportService $csvImportService,
        private readonly AttendanceExceptionService $exceptionService,
        private readonly AttendanceReprocessingService $reprocessingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $records = $this->attendanceService->getRecords($request);

            return response()->json([
                'success' => true,
                'data'    => $records->items(),
                'meta'    => [
                    'current_page' => $records->currentPage(),
                    'last_page'    => $records->lastPage(),
                    'per_page'     => $records->perPage(),
                    'total'        => $records->total(),
                    'from'         => $records->firstItem(),
                    'to'           => $records->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attendance records: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $record = $this->attendanceService->getRecord($id);

            return response()->json(['success' => true, 'data' => $record]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Record not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'date'        => 'required|date',
            'clock_in'    => 'nullable|date_format:H:i',
            'clock_out'   => 'nullable|date_format:H:i|after:clock_in',
            'notes'       => 'nullable|string|max:500',
            'correction_reason' => 'required|string|min:3|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $record = $this->attendanceService->createRecord(
                $validator->validated(),
                $request->user()?->id,
                $request->ip()
            );

            return response()->json([
                'success' => true,
                'message' => 'Attendance record created',
                'data'    => $record,
            ], 201);
        } catch (AttendanceRecordConflictException|\Illuminate\Database\QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'An attendance record already exists for this employee and date.',
            ], 409);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date'      => 'nullable|date',
            'clock_in'  => 'nullable|date_format:H:i',
            'clock_out' => 'nullable|date_format:H:i',
            'notes'     => 'nullable|string|max:500',
            'correction_reason' => 'required|string|min:3|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $record = $this->attendanceService->updateRecord(
                $id,
                $validator->validated(),
                $request->user()?->id,
                $request->ip()
            );

            return response()->json(['success' => true, 'message' => 'Record updated', 'data' => $record]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Record not found'], 404);
        } catch (AttendanceRecordConflictException|\Illuminate\Database\QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'An attendance record already exists for this employee and date.',
            ], 409);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'correction_reason' => 'required|string|min:3|max:500',
        ]);

        try {
            $this->attendanceService->deleteRecord(
                $id,
                $validated['correction_reason'],
                $request->user()?->id,
                $request->ip()
            );

            return response()->json(['success' => true, 'message' => 'Record deleted']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Record not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'correction_reason' => 'required|string|min:3|max:500',
        ]);

        try {
            $record = $this->attendanceService->restoreRecord(
                $id,
                $validated['correction_reason'],
                $request->user()?->id,
                $request->ip()
            );

            return response()->json([
                'success' => true,
                'message' => 'Attendance record restored',
                'data' => $record,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Record not found'], 404);
        } catch (AttendanceRecordConflictException|\Illuminate\Database\QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'An attendance record already exists for this employee and date.',
            ], 409);
        }
    }

    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'nullable|date',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        if ($request->date_from && $request->date_to && Carbon::parse($request->date_from)->diffInDays($request->date_to) > 366) {
            return response()->json([
                'success' => false,
                'message' => 'The summary range cannot exceed 366 days.',
            ], 422);
        }

        try {
            $summary = $this->attendanceService->getSummary(
                $request->date,
                $request->date_from,
                $request->date_to
            );

            return response()->json(['success' => true, 'data' => $summary]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function manualPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'clock_in' => 'nullable|date_format:H:i',
            'clock_out' => 'nullable|date_format:H:i',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->attendanceService->calculateManualRecord($validated),
        ]);
    }

    public function overtime(Request $request): JsonResponse
    {
        try {
            $records = $this->attendanceService->getOvertimeReport($request);

            return response()->json([
                'success' => true,
                'data'    => $records->items(),
                'meta'    => [
                    'current_page' => $records->currentPage(),
                    'last_page'    => $records->lastPage(),
                    'per_page'     => $records->perPage(),
                    'total'        => $records->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function exportOvertime(Request $request)
    {
        $request->merge(['per_page' => 10000]);
        $records = $this->attendanceService->getOvertimeReport($request)->items();

        return response()->streamDownload(function () use ($records) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'Employee',
                'Employee Number',
                'Date',
                'Clock In',
                'Clock Out',
                'Proposed Hours',
                'Approved Hours',
                'Approval Status',
                'Off-day/Holiday',
            ]);

            foreach ($records as $record) {
                fputcsv($stream, [
                    trim(($record->employee?->first_name ?? '') . ' ' . ($record->employee?->last_name ?? '')),
                    $record->employee?->employee_id,
                    $record->date->toDateString(),
                    $record->clock_in,
                    $record->clock_out,
                    $record->proposed_overtime_hours,
                    $record->approved_overtime_hours,
                    $record->overtime_status,
                    $record->holiday_name,
                ]);
            }

            fclose($stream);
        }, 'attendance-overtime-' . today()->toDateString() . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function sync(): JsonResponse
    {
        $syncRequest = AttendanceSyncRequest::query()
            ->whereIn('status', [
                AttendanceSyncRequest::STATUS_QUEUED,
                AttendanceSyncRequest::STATUS_RUNNING,
            ])
            ->latest()
            ->first();

        if (!$syncRequest) {
            $syncRequest = AttendanceSyncRequest::create([
                'status' => AttendanceSyncRequest::STATUS_QUEUED,
            ]);

            SyncHikvisionAttendanceJob::dispatch($syncRequest->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Attendance sync queued',
            'data' => $syncRequest,
        ], 202);
    }

    public function syncStatus(AttendanceSyncRequest $syncRequest): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $syncRequest->load('syncLog'),
        ]);
    }

    public function syncLogs(): JsonResponse
    {
        try {
            $logs = $this->attendanceService->getSyncLogs();

            return response()->json(['success' => true, 'data' => $logs]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function uploadPreview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,xlsx,xls|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $preview = $this->csvImportService->preview($request->file('file'));

            return response()->json(['success' => true, 'data' => $preview]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,xlsx,xls|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $syncLog = $this->csvImportService->commit($request->file('file'));

            return response()->json([
                'success' => true,
                'message' => "Import complete: {$syncLog->records_imported} events imported, {$syncLog->records_processed} records processed.",
                'data'    => $syncLog,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function unmappedExceptions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->exceptionService->unmapped(),
        ]);
    }

    public function mapUnmappedPerson(Request $request, string $personId): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
        ]);

        try {
            $result = $this->exceptionService->mapToEmployee(
                $personId,
                Employee::findOrFail($validated['employee_id'])
            );

            return response()->json([
                'success' => true,
                'message' => 'Person mapped and attendance history reprocessed.',
                'data' => $result,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'That Hikvision person ID is already assigned to another employee.',
            ], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function reprocess(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'person_id' => 'nullable|string|max:100',
        ]);

        $from = Carbon::parse($validated['date_from']);
        $to = Carbon::parse($validated['date_to']);
        if ($from->diffInDays($to) > 366) {
            return response()->json([
                'success' => false,
                'message' => 'The reprocessing range cannot exceed 366 days.',
            ], 422);
        }

        $result = $this->reprocessingService->reprocess(
            $from,
            $to,
            $validated['person_id'] ?? null
        );

        return response()->json(['success' => true, 'data' => $result]);
    }
}
