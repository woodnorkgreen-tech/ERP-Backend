<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Services\AttendanceCsvImportService;
use App\Modules\HR\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly AttendanceCsvImportService $csvImportService
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
            'status'      => ['required', Rule::in(AttendanceRecord::STATUSES)],
            'notes'       => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $record = $this->attendanceService->createRecord($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Attendance record created',
                'data'    => $record,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'clock_in'  => 'nullable|date_format:H:i',
            'clock_out' => 'nullable|date_format:H:i',
            'status'    => ['nullable', Rule::in(AttendanceRecord::STATUSES)],
            'notes'     => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $record = $this->attendanceService->updateRecord($id, $validator->validated());

            return response()->json(['success' => true, 'message' => 'Record updated', 'data' => $record]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Record not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->attendanceService->deleteRecord($id);

            return response()->json(['success' => true, 'message' => 'Record deleted']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Record not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function summary(Request $request): JsonResponse
    {
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

    public function sync(): JsonResponse
    {
        try {
            $log = $this->attendanceService->triggerSync();

            return response()->json([
                'success' => true,
                'message' => 'Sync triggered successfully',
                'data'    => $log,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
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
}
