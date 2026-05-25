<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
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
        $query->accessibleByUser();

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
            'salary' => 'nullable|numeric|min:0',
            'id_number' => 'nullable|string|max:20',
            'kra_pin' => 'nullable|string|max:20',
            'nssf_id' => 'nullable|string|max:20',
            'nhif_id' => 'nullable|string|max:20',
            'bank_name' => 'nullable|string|max:255',
            'bank_branch' => 'nullable|string|max:255',
            'bank_code' => 'nullable|string|max:20',
            'account_number' => 'nullable|string|max:50',
            'payment_method' => ['nullable', Rule::in(['bank', 'mobile_money', 'cheque', 'cash'])],
            'probation_end_date' => 'nullable|date',
            'is_on_probation' => 'nullable|boolean',
            'contract_end_date' => 'nullable|date',

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
            'salary' => 'nullable|numeric|min:0',
            'id_number' => 'nullable|string|max:20',
            'kra_pin' => 'nullable|string|max:20',
            'nssf_id' => 'nullable|string|max:20',
            'nhif_id' => 'nullable|string|max:20',
            'bank_name' => 'nullable|string|max:255',
            'bank_branch' => 'nullable|string|max:255',
            'bank_code' => 'nullable|string|max:20',
            'account_number' => 'nullable|string|max:50',
            'payment_method' => ['nullable', Rule::in(['bank', 'mobile_money', 'cheque', 'cash'])],
            'probation_end_date' => 'nullable|date',
            'is_on_probation' => 'nullable|boolean',
            'contract_end_date' => 'nullable|date',

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

    /**
     * Display the authenticated user's employee profile.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        if (!$user || !$user->employee_id) {
            return response()->json([
                'message' => 'No employee profile associated with this user account'
            ], 404);
        }

        $employee = Employee::with(['department', 'manager'])->find($user->employee_id);

        if (!$employee) {
            return response()->json([
                'message' => 'Employee profile not found'
            ], 404);
        }

        return response()->json([
            'data' => $employee
        ]);
    }

    /**
     * Upload / replace the employee's profile photo.
     */
    public function uploadPhoto(Request $request, Employee $employee): JsonResponse
    {
        \Log::info("Uploading photo for employee {$employee->id}");
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        // Delete previous photo
        if ($employee->profile_photo_path) {
            Storage::disk('public')->delete($employee->profile_photo_path);
        }

        $path = $request->file('photo')->store('employees/photos', 'public');
        \Log::info("Photo stored at: {$path}");

        $employee->update(['profile_photo_path' => $path]);

        return response()->json([
            'success' => true,
            'message' => 'Profile photo updated.',
            'data' => $employee->fresh()->load(['department', 'manager']),
        ]);
    }

    /**
     * Get the employee's profile photo.
     */
    public function getPhoto(Employee $employee)
    {
        \Log::info("Fetching photo for employee {$employee->id}. Path: {$employee->profile_photo_path}");
        if (!$employee->profile_photo_path || !Storage::disk('public')->exists($employee->profile_photo_path)) {
            \Log::warning("Photo not found for employee {$employee->id}");
            return response()->json(['message' => 'Photo not found'], 404);
        }

        return Storage::disk('public')->response($employee->profile_photo_path);
    }
    /**
     * Get a compact list of employees for dropdowns.
     */
    public function compact(Request $request): JsonResponse
    {
        $employees = Employee::query()
            ->accessibleByUser()
            ->with('user:id,employee_id')
            ->where('status', 'active')
            ->select(['id', 'employee_id', 'first_name', 'last_name', 'position', 'department_id', 'manager_id'])
            ->get()
            ->map(function ($emp) {
                return [
                    'id' => $emp->id,
                    'user_id' => $emp->user?->id,
                    'employee_id' => $emp->id, // Frontend uses DB ID for balance fetch usually
                    'name' => "{$emp->first_name} {$emp->last_name}",
                    'job_title' => $emp->position,
                    'department_id' => $emp->department_id,
                    'manager_id' => $emp->manager_id,
                    'ot_balance' => $emp->ot_balance
                ];
            });

        return response()->json($employees);
    }
}
