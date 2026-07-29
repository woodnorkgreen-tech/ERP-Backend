<?php

namespace App\Modules\UniversalTask\Controllers;

use App\Modules\UniversalTask\Models\TaskDepartmentPrefix;
use App\Modules\UniversalTask\Models\TaskLabel;
use App\Modules\UniversalTask\Services\TaskPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TaskAdminSettingsController
{
    public function __construct(private TaskPermissionService $permissionService)
    {
    }

    public function labels(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => TaskLabel::orderBy('name')->get(),
        ]);
    }

    public function storeLabel(Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), $this->labelRules());
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $label = TaskLabel::create([
            ...$validator->validated(),
            'slug' => Str::slug($request->name),
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Label created successfully.',
            'data' => $label,
        ], 201);
    }

    public function updateLabel(Request $request, TaskLabel $label): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), $this->labelRules($label->id));
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $label->update([
            ...$validator->validated(),
            'slug' => Str::slug($request->name),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Label updated successfully.',
            'data' => $label,
        ]);
    }

    public function deleteLabel(TaskLabel $label): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }

        if ($label->tasks()->exists()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LABEL_IN_USE',
                    'message' => 'This label is attached to existing tasks. Deactivate it instead of deleting it.',
                ],
            ], 409);
        }

        $label->delete();

        return response()->json([
            'success' => true,
            'message' => 'Label deleted successfully.',
        ]);
    }

    public function departmentPrefixes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => TaskDepartmentPrefix::with('department:id,name')->orderBy('prefix')->get(),
        ]);
    }

    public function storeDepartmentPrefix(Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), $this->prefixRules());
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $prefix = TaskDepartmentPrefix::create([
            ...$validator->validated(),
            'prefix' => Str::upper($request->prefix),
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Department prefix created successfully.',
            'data' => $prefix->load('department:id,name'),
        ], 201);
    }

    public function updateDepartmentPrefix(Request $request, TaskDepartmentPrefix $prefix): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), $this->prefixRules($prefix->id));
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $prefix->update([
            ...$validator->validated(),
            'prefix' => Str::upper($request->prefix),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Department prefix updated successfully.',
            'data' => $prefix->load('department:id,name'),
        ]);
    }

    public function deleteDepartmentPrefix(TaskDepartmentPrefix $prefix): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }

        $prefix->delete();

        return response()->json([
            'success' => true,
            'message' => 'Department prefix deleted successfully.',
        ]);
    }

    private function labelRules(?int $labelId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('task_labels', 'name')->ignore($labelId)],
            'color' => ['required', 'string', 'max:20', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function prefixRules(?int $prefixId = null): array
    {
        return [
            'department_id' => [
                'required',
                'exists:departments,id',
                Rule::unique('task_department_prefixes', 'department_id')->ignore($prefixId),
            ],
            'prefix' => [
                'required',
                'string',
                'max:12',
                'regex:/^[A-Za-z0-9]+$/',
                Rule::unique('task_department_prefixes', 'prefix')->ignore($prefixId),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function canManage(): bool
    {
        $user = Auth::user();

        return $user && $this->permissionService->hasFullAccess($user);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => 'You do not have permission to manage task admin settings.',
            ],
        ], 403);
    }

    private function validationError($validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Invalid admin setting data.',
                'details' => $validator->errors(),
            ],
        ], 422);
    }
}
