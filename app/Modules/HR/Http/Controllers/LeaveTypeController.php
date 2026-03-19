<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Services\LeaveManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveTypeController extends Controller
{
    public function __construct(private readonly LeaveManagementService $leaveService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->leaveService->syncDefaultLeaveTypes();

        if (!$request->boolean('include_inactive')) {
            return response()->json([
                'success' => true,
                'data' => $this->leaveService->getRequestableLeaveTypes(),
            ]);
        }

        $query = LeaveType::query()->orderBy('name');

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:leave_types,code'],
            'days_per_year' => ['required', 'integer', 'min:0', 'max:366'],
            'color' => ['nullable', 'string', 'max:32'],
            'icon' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'requires_attachment' => ['sometimes', 'boolean'],
        ]);

        $leaveType = LeaveType::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Leave policy created successfully.',
            'data' => $leaveType,
        ], 201);
    }

    public function update(Request $request, LeaveType $leaveType): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('leave_types', 'code')->ignore($leaveType->id)],
            'days_per_year' => ['sometimes', 'required', 'integer', 'min:0', 'max:366'],
            'color' => ['nullable', 'string', 'max:32'],
            'icon' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'requires_attachment' => ['sometimes', 'boolean'],
        ]);

        $leaveType->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Leave policy updated successfully.',
            'data' => $leaveType->fresh(),
        ]);
    }

    public function destroy(LeaveType $leaveType): JsonResponse
    {
        if ($leaveType->requests()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Leave policy cannot be deleted after requests have been recorded.',
            ], 422);
        }

        $leaveType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Leave policy deleted successfully.',
        ]);
    }
}
