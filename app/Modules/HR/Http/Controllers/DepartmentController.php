<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class DepartmentController
{
    /**
     * Display a listing of departments.
     */
    public function index(Request $request): JsonResponse
    {
        // Log permission check details for debugging
        $user = auth()->user();
        \Log::info('DepartmentController@index - Permission Check', [
            'user_id' => $user ? $user->id : null,
            'user_email' => $user ? $user->email : null,
            'user_roles' => $user ? $user->roles->pluck('name')->toArray() : [],
            'user_permissions' => $user ? $user->getAllPermissions()->pluck('name')->toArray() : [],
            'has_department_read' => $user ? $user->hasPermissionTo('department.read') : false,
            'required_permission' => 'department.read',
            'request_method' => $request->method(),
            'request_url' => $request->fullUrl(),
        ]);

        $query = Department::with(['manager', 'employees']);

        // Apply filters
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
        }

        if ($request->has('location') && $request->location) {
            $query->where('location', $request->location);
        }

        if ($request->has('has_manager')) {
            $hasManager = $request->boolean('has_manager');
            if ($hasManager) {
                $query->whereNotNull('manager_id');
            } else {
                $query->whereNull('manager_id');
            }
        }

        $departments = $query->paginate($request->get('per_page', 15));

        // Add user_count to each department
        $departments->getCollection()->transform(function ($department) {
            $department->user_count = $department->employees->count();
            return $department;
        });

        return response()->json([
            'data' => $departments->items(),
            'meta' => [
                'current_page' => $departments->currentPage(),
                'per_page' => $departments->perPage(),
                'total' => $departments->total(),
                'last_page' => $departments->lastPage(),
            ]
        ]);
    }

    /**
     * Store a newly created department.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:departments,name',
            'description' => 'nullable|string',
            'manager_id' => 'nullable|exists:employees,id',
            'budget' => 'nullable|numeric|min:0',
            'location' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $department = Department::create($validator->validated());

        return response()->json([
            'message' => 'Department created successfully',
            'data' => $department->load('manager')
        ], 201);
    }

    /**
     * Display the specified department.
     */
    public function show(Department $department): JsonResponse
    {
        return response()->json([
            'data' => $department->load(['manager', 'employees'])
        ]);
    }

    /**
     * Update the specified department.
     */
    public function update(Request $request, Department $department): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:departments,name,' . $department->id,
            'description' => 'nullable|string',
            'manager_id' => 'nullable|exists:employees,id',
            'budget' => 'nullable|numeric|min:0',
            'location' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $department->update($validator->validated());

        return response()->json([
            'message' => 'Department updated successfully',
            'data' => $department->load(['manager', 'employees'])
        ]);
    }

    /**
     * Remove the specified department.
     */
    public function destroy(Department $department): JsonResponse
    {
        // Check if department has employees
        if ($department->employees()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete department with assigned employees'
            ], 422);
        }

        $department->delete();

        return response()->json([
            'message' => 'Department deleted successfully'
        ]);
    }
}
