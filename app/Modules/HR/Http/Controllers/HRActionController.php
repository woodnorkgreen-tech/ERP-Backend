<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\HRAction;
use App\Modules\HR\Models\HRActionType;
use App\Modules\HR\Models\HRActionAttachment;
use App\Modules\Notifications\Services\NotificationService;
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

        $action = DB::transaction(function () use ($employee, $actionType, $validatedData, $previousSnapshot, $newData, $isFutureDated, $request) {
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

            return $action->load(['employee.user', 'type', 'attachments', 'recorder']);
        });

        $type = $isFutureDated
            ? 'hr_action_scheduled'
            : ($this->isFormalAction($actionType->code) ? 'hr_action_formal_notice' : 'hr_action_recorded');
        $this->notifyEmployee($action, $type,
            $isFutureDated ? 'HR action scheduled' : 'HR action recorded',
            $isFutureDated
                ? "An HR action has been scheduled for {$action->effective_date->format('d M Y')}."
                : 'An HR action has been recorded on your employee profile.');

        return response()->json([
            'message' => $isFutureDated
                ? 'HR Action scheduled for ' . $validatedData['effective_date']
                : 'HR Action recorded and executed successfully',
            'data' => $action,
        ], 201);
    }



    /**
     * Stream an attachment inline for preview (PDF, image, etc.)
     */
    public function viewAttachment(int $id): \Illuminate\Http\Response
    {
        $attachment = HRActionAttachment::findOrFail($id);

        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File not found');
        }

        $content  = Storage::disk('public')->get($attachment->file_path);
        $mimeType = Storage::disk('public')->mimeType($attachment->file_path) ?? 'application/octet-stream';
        $size     = Storage::disk('public')->size($attachment->file_path);

        return response($content, 200, [
            'Content-Type'        => $mimeType,
            'Content-Length'      => $size,
            'Content-Disposition' => 'inline; filename="' . $attachment->file_name . '"',
            'Cache-Control'       => 'private, no-cache',
        ]);
    }

    /**
     * Force-download an attachment.
     */
    public function downloadAttachment(int $id): \Illuminate\Http\Response
    {
        $attachment = HRActionAttachment::findOrFail($id);

        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File not found');
        }

        $content  = Storage::disk('public')->get($attachment->file_path);
        $mimeType = Storage::disk('public')->mimeType($attachment->file_path) ?? 'application/octet-stream';
        $size     = Storage::disk('public')->size($attachment->file_path);

        return response($content, 200, [
            'Content-Type'        => $mimeType,
            'Content-Length'      => $size,
            'Content-Disposition' => 'attachment; filename="' . $attachment->file_name . '"',
            'Cache-Control'       => 'private, no-cache',
        ]);
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

        $action = DB::transaction(function () use ($action) {
            // For future dated actions that are now being forced/approved
            if ($action->status === 'pending_execution') {
                 $action->employee->update($action->new_data);
            }

            $action->update([
                'status' => 'executed',
                'executed_at' => now(),
                'approved_by' => auth()->id()
            ]);

            return $action->load(['employee.user', 'type', 'recorder']);
        });

        $type = $this->isFormalAction($action->type?->code ?? $action->action_type)
            ? 'hr_action_formal_notice'
            : 'hr_action_executed';
        $this->notifyEmployee($action, $type, 'HR action executed',
            'A scheduled HR action has been approved and applied to your employee profile.');

        return response()->json([
            'message' => 'Action approved and executed successfully',
            'data' => $action,
        ]);
    }

    private function isFormalAction(?string $code): bool
    {
        return in_array(strtoupper((string) $code), ['WARNING', 'SUSPENSION', 'TERMINATION'], true);
    }

    private function notifyEmployee(HRAction $action, string $type, string $title, string $message): void
    {
        $action->loadMissing(['employee.user', 'recorder']);
        $employeeUser = $action->employee?->user;

        NotificationService::send(
            type: $type,
            title: $title,
            message: $message,
            module: 'hr',
            data: [
                'url' => "/hr/employees?profile={$action->employee_id}",
                'record_type' => 'hr_action',
                'record_id' => $action->id,
                'employee_id' => $action->employee_id,
                'employee_name' => $action->employee?->name,
                'action_type' => $action->type?->code ?? $action->action_type,
                'status' => $action->status,
                'actor_id' => auth()->id(),
            ],
            users: collect([$employeeUser, $action->recorder])->filter()->all(),
            emails: !$employeeUser && $action->employee?->email ? [$action->employee->email] : [],
        );
    }
}
