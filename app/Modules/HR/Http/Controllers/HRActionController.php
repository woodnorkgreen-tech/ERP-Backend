<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\HRAction;
use App\Modules\HR\Models\HRActionType;
use App\Modules\HR\Models\HRActionAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class HRActionController
{
    /**
     * Display a listing of HR actions for a specific employee.
     */
    public function index(Employee $employee): JsonResponse
    {
        return response()->json([
            'data' => $employee->actions()->with(['recorder', 'type', 'attachments'])->orderBy('effective_date', 'desc')->get()
        ]);
    }

    /**
     * Display a listing of available HR action types.
     */
    public function actionTypes(): JsonResponse
    {
        return response()->json([
            'data' => HRActionType::where('is_active', true)->get()
        ]);
    }

    /**
     * Store a newly created HR action.
     */
    public function store(Request $request): JsonResponse
    {
        // Decode new_data if sent as a JSON string (common with FormData/file uploads)
        if (is_string($request->new_data)) {
            $decoded = json_decode($request->new_data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $request->merge(['new_data' => $decoded]);
            }
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'action_type_id' => 'required|exists:hr_action_types,id',
            'effective_date' => 'required|date',
            'reason' => 'nullable|string',
            'new_data' => 'nullable|array',
            'attachments.*' => 'nullable|file|max:10240', // 10MB limit
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $validatedData = $validator->validated();
        $employee = Employee::findOrFail($validatedData['employee_id']);
        $actionType = HRActionType::findOrFail($validatedData['action_type_id']);
        
        $effectiveDate = Carbon::parse($validatedData['effective_date']);
        $isFutureDated = $effectiveDate->isFuture();
        $newData = $validatedData['new_data'] ?? [];
        
        // Capture a full snapshot of the employee before the change
        $previousSnapshot = $employee->toArray();

        return DB::transaction(function () use ($employee, $actionType, $validatedData, $previousSnapshot, $newData, $isFutureDated, $request) {
            // 1. Create the HR Action record
            $action = HRAction::create([
                'employee_id' => $employee->id,
                'action_type_id' => $actionType->id,
                'action_type' => $actionType->code, // Keep for legacy
                'previous_data' => $previousSnapshot,
                'new_data' => $newData,
                'effective_date' => $validatedData['effective_date'],
                'reason' => $validatedData['reason'],
                'status' => $isFutureDated ? 'pending_execution' : 'executed',
                'recorded_by' => auth()->id() ?? 1,
            ]);

            // 2. Handle Attachments
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('hr/actions/' . $action->id, 'public');
                    HRActionAttachment::create([
                        'hr_action_id' => $action->id,
                        'file_path' => $path,
                        'file_name' => $file->getClientOriginalName(),
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                        'uploaded_by' => auth()->id() ?? 1,
                    ]);
                }
            }

            // 3. Update the Employee record immediately if not future-dated
            // Some actions like "WARNING" might not update the main employee record (except maybe status)
            if (!$isFutureDated && $actionType->code !== 'WARNING') {
                $employee->update($newData);
            }

            return response()->json([
                'message' => $isFutureDated 
                    ? 'HR Action scheduled for ' . $validatedData['effective_date']
                    : 'HR Action recorded and executed successfully',
                'data' => $action->load(['employee', 'type', 'attachments'])
            ], 201);
        });
    }



    /**
     * HR: Approve a pending profile update or future-dated action.
     */
    public function approveAction(Request $request, $id): JsonResponse
    {
        $action = HRAction::with(['employee', 'type'])->findOrFail($id);

        if ($action->status !== 'pending_approval' && $action->status !== 'pending_execution') {
            return response()->json(['message' => 'Action cannot be approved in its current status'], 422);
        }

        return DB::transaction(function () use ($action) {
            // For future dated actions that are now being forced/approved
            if ($action->status === 'pending_execution') {
                 $action->employee->update($action->new_data);
            }

            $action->update([
                'status' => 'executed',
                'executed_at' => now(),
                'approved_by' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Action approved and executed successfully',
                'data' => $action->load('employee')
            ]);
        });
    }
}
