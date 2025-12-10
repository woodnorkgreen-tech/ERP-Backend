<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EmployeeController
{
    /**
     * Display a listing of employees.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Employee::with(['department', 'manager']);

        // Apply department access control first
        // Temporarily disabled for debugging
        // $query->accessibleByUser();

        // Apply filters
        if ($request->has('department_id') && $request->department_id) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Handle is_active filter from frontend (convert to status)
        if ($request->has('is_active') && $request->is_active !== null) {
            $status = $request->boolean('is_active') ? 'active' : 'inactive';
            $query->where('status', $status);
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Check if pagination is requested
        if ($request->has('per_page')) {
            // Return paginated results
            $employees = $query->paginate($request->get('per_page', 15));
            
            return response()->json([
                'data' => $employees->items(),
                'meta' => [
                    'current_page' => $employees->currentPage(),
                    'per_page' => $employees->perPage(),
                    'total' => $employees->total(),
                    'last_page' => $employees->lastPage(),
                    'from' => $employees->firstItem(),
                    'to' => $employees->lastItem(),
                ]
            ]);
        } else {
            // Return all employees (no pagination)
            $employees = $query->get();
            
            return response()->json($employees);
        }
    }

    /**
     * Store a newly created employee.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'nullable|string|unique:employees,employee_id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:employees,email',
            'phone' => 'nullable|string|max:20',
            'department_id' => 'required|exists:departments,id',
            'position' => 'required|string|max:255',
            'hire_date' => 'required|date',

            'status' => ['required', Rule::in(['active', 'inactive', 'terminated', 'on-leave'])],
            'employment_type' => ['nullable', Rule::in(['full-time', 'part-time', 'contract', 'intern'])],
            'manager_id' => 'nullable|exists:employees,id',
            'address' => 'nullable|string',

            'emergency_contact' => 'nullable|array',
            'emergency_contact.name' => 'nullable|string|max:255',
            'emergency_contact.relationship' => 'nullable|string|max:255',
            'emergency_contact.phone' => 'nullable|string|max:20',
            'performance_rating' => 'nullable|numeric|min:0|max:5',
            'last_review_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $validatedData = $validator->validated();

        // Generate employee_id if not provided
        if (empty($validatedData['employee_id'])) {
            $nextId = Employee::max('id') + 1;
            $validatedData['employee_id'] = 'EMP' . str_pad($nextId, 4, '0', STR_PAD_LEFT);
        }

        $employee = Employee::create($validatedData);

        return response()->json([
            'message' => 'Employee created successfully',
            'data' => $employee->load(['department', 'manager'])
        ], 201);
    }

    /**
     * Display the specified employee.
     */
    public function show(Employee $employee): JsonResponse
    {
        return response()->json([
            'data' => $employee->load(['department', 'manager'])
        ]);
    }

    /**
     * Update the specified employee.
     */
    public function update(Request $request, Employee $employee): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => ['sometimes', Rule::unique('employees')->ignore($employee->id)],
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('employees')->ignore($employee->id)],
            'phone' => 'nullable|string|max:20',
            'department_id' => 'sometimes|required|exists:departments,id',
            'position' => 'sometimes|required|string|max:255',
            'hire_date' => 'sometimes|required|date',

            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive', 'terminated', 'on-leave'])],
            'employment_type' => ['nullable', Rule::in(['full-time', 'part-time', 'contract', 'intern'])],
            'manager_id' => 'nullable|exists:employees,id',
            'address' => 'nullable|string',

            'emergency_contact' => 'nullable|array',
            'performance_rating' => 'nullable|numeric|min:0|max:5',
            'last_review_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $validatedData = $validator->validated();

        // Generate employee_id if not provided and employee doesn't have one
        if (empty($validatedData['employee_id']) && empty($employee->employee_id)) {
            $nextId = Employee::max('id') + 1;
            $validatedData['employee_id'] = 'EMP' . str_pad($nextId, 4, '0', STR_PAD_LEFT);
        }

        $employee->update($validatedData);

        return response()->json([
            'message' => 'Employee updated successfully',
            'data' => $employee->load(['department', 'manager'])
        ]);
    }

    /**
     * Remove the specified employee.
     */
    public function destroy(Employee $employee): JsonResponse
    {
        // Check if employee has associated user
        if ($employee->user) {
            return response()->json([
                'message' => 'Cannot delete employee with associated user account'
            ], 422);
        }

        $employee->delete();

        return response()->json([
            'message' => 'Employee deleted successfully'
        ]);
    }
}