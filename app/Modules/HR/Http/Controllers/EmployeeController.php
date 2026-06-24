<?php

namespace App\Modules\HR\Http\Controllers;

use App\Constants\Permissions;
use App\Modules\HR\Exports\EmployeesTemplateExport;
use App\Modules\HR\Imports\EmployeesTemplateImport;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

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

        // Deactivated staff (inactive / terminated) are hidden from general lists and
        // every employee dropdown by default — they must not be selectable anywhere.
        // The HR roster opts back in with include_inactive=true; an explicit status /
        // is_active filter above is honoured as-is and takes precedence over this.
        $hasExplicitStatusFilter = $request->filled('status')
            || ($request->has('is_active') && $request->is_active !== null);
        if (! $hasExplicitStatusFilter && ! $request->boolean('include_inactive', false)) {
            $query->whereNotIn('status', ['inactive', 'terminated']);
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $canViewSalary = auth()->user()?->can(Permissions::EMPLOYEE_VIEW_SALARY)
            || auth()->user()?->hasRole(['Super Admin', 'Admin', 'HR']);

        // Check if pagination is requested
        if ($request->has('per_page')) {
            $employees = $query->paginate($request->get('per_page', 15));

            // Unfiltered base for global KPI counts (ignores status/search/dept filters)
            $statsBase = Employee::query()->accessibleByUser();

            return response()->json([
                'data' => collect($employees->items())->map(fn ($e) => $this->maskSalary($e, $canViewSalary)),
                'meta' => [
                    'current_page' => $employees->currentPage(),
                    'per_page'     => $employees->perPage(),
                    'total'        => $employees->total(),
                    'last_page'    => $employees->lastPage(),
                    'from'         => $employees->firstItem(),
                    'to'           => $employees->lastItem(),
                    'stats' => [
                        'total'    => (clone $statsBase)->count(),
                        'active'   => (clone $statsBase)->where('status', 'active')->count(),
                        'on_leave' => (clone $statsBase)->where('status', 'on-leave')->count(),
                        'inactive' => (clone $statsBase)->whereIn('status', ['inactive', 'terminated'])->count(),
                    ],
                ]
            ]);
        } else {
            $employees = $query->get();

            return response()->json($employees->map(fn ($e) => $this->maskSalary($e, $canViewSalary)));
        }
    }

    /**
     * Store a newly created employee.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'nullable|string|unique:employees,employee_id',
            'hikvision_id' => 'nullable|string|max:50|unique:employees,hikvision_id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:employees,email',
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
            'payment_method' => ['nullable', Rule::in(['bank', 'mpesa', 'mobile_money', 'cash', 'cheque'])],
            'statutory_exemptions' => 'nullable|array',
            'statutory_exemptions.*' => ['string', Rule::in(Employee::STATUTORY_EXEMPTIONS)],
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
        $customId = $validatedData['employee_id'] ?? null;

        // employees.employee_id is NOT NULL + UNIQUE with no default, so it must be
        // present at insert. Use the supplied id, otherwise a transient unique
        // placeholder, then derive the final staff number from the real
        // auto-increment id (race-safe).
        $validatedData['employee_id'] = $customId ?: 'PENDING-' . uniqid('', true);

        $employee = Employee::create($validatedData);

        if (!$customId) {
            $employee->employee_id = 'EMP' . str_pad($employee->id, 4, '0', STR_PAD_LEFT);
            $employee->save();
        }

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
        $canViewSalary = auth()->user()?->can(Permissions::EMPLOYEE_VIEW_SALARY)
            || auth()->user()?->hasRole(['Super Admin', 'Admin', 'HR'])
            || auth()->user()?->employee_id === $employee->id; // own record

        $data = $employee->load(['department', 'manager']);

        return response()->json([
            'data' => $this->maskSalary($data, $canViewSalary)
        ]);
    }

    /**
     * Update the specified employee.
     */
    public function update(Request $request, Employee $employee): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => ['sometimes', Rule::unique('employees')->ignore($employee->id)],
            'hikvision_id' => ['nullable', 'string', 'max:50', Rule::unique('employees')->ignore($employee->id)],
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('employees')->ignore($employee->id)],
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
            'payment_method' => ['nullable', Rule::in(['bank', 'mpesa', 'mobile_money', 'cash', 'cheque'])],
            'statutory_exemptions' => 'nullable|array',
            'statutory_exemptions.*' => ['string', Rule::in(Employee::STATUTORY_EXEMPTIONS)],
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

        // Backfill employee_id if the record never had one
        if (empty($validatedData['employee_id']) && empty($employee->employee_id)) {
            $validatedData['employee_id'] = 'EMP' . str_pad($employee->id, 4, '0', STR_PAD_LEFT);
        }

        $employee->update($validatedData);

        return response()->json([
            'message' => 'Employee updated successfully',
            'data' => $employee->load(['department', 'manager'])
        ]);
    }

    /**
     * Terminate the specified employee (soft-delete + status transition).
     * Hard deletion is intentionally prevented to preserve payroll/audit history.
     */
    public function destroy(Employee $employee): JsonResponse
    {
        if ($employee->user) {
            return response()->json([
                'message' => 'Cannot terminate an employee who has an active user account. Deactivate their user account first.'
            ], 422);
        }

        $employee->update(['status' => 'terminated']);
        $employee->delete(); // soft delete — record is retained, deleted_at is set

        return response()->json([
            'message' => 'Employee record terminated and archived.'
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
        $user = auth()->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Authorize: user can update their own profile OR must have HR admin roles
        if ($user->employee_id != $employee->id && !$user->hasRole(['Admin', 'HR', 'Super Admin'])) {
            return response()->json(['message' => 'Unauthorized to update this profile photo.'], 403);
        }

        \Log::info("Uploading photo for employee {$employee->id}");
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        try {
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
        } catch (\Exception $e) {
            \Log::error("Failed to store profile photo: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to write profile photo to disk. Please verify storage permissions.'
            ], 500);
        }
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
     * Return company-wide employee counts by status.
     * Used by the roster KPI cards so they show totals across all pages.
     */
    public function stats(Request $request): JsonResponse
    {
        $base = Employee::query()->accessibleByUser();

        return response()->json([
            'total'      => (clone $base)->count(),
            'active'     => (clone $base)->where('status', 'active')->count(),
            'on_leave'   => (clone $base)->where('status', 'on-leave')->count(),
            'inactive'   => (clone $base)->whereIn('status', ['inactive', 'terminated'])->count(),
        ]);
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

    /**
     * Download the bulk-edit Excel template, pre-filled with every employee the
     * caller may access. Edit the rows and reupload via previewImport/commitImport.
     */
    public function downloadTemplate(Request $request)
    {
        $filename = 'Employees_' . now()->format('Y-m-d_His') . '.xlsx';

        return Excel::download(
            new EmployeesTemplateExport($this->canViewSalary()),
            $filename
        );
    }

    /**
     * Dry-run a reuploaded template: validate and return the create/update/error diff.
     * Nothing is written — the user reviews this before committing.
     */
    public function previewImport(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
        ]);

        $import = new EmployeesTemplateImport($this->canViewSalary(), $this->canCreate());
        Excel::import($import, $request->file('file'));

        return response()->json($import->getAnalysis());
    }

    /**
     * Commit a reuploaded template: apply every non-error row (create + update) in a
     * single transaction. Salary changes are routed to salary history by EmployeeObserver.
     */
    public function commitImport(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
        ]);

        $import = new EmployeesTemplateImport($this->canViewSalary(), $this->canCreate());
        Excel::import($import, $request->file('file'));

        $result = $import->apply();

        return response()->json([
            'message' => "Import complete: {$result['created']} created, {$result['updated']} updated, {$result['skipped']} skipped.",
            'result'  => $result,
        ]);
    }

    /**
     * Whether the current user may see/edit salary and bank columns.
     */
    private function canViewSalary(): bool
    {
        return auth()->user()?->can(Permissions::EMPLOYEE_VIEW_SALARY)
            || auth()->user()?->hasRole(['Super Admin', 'Admin', 'HR'])
            || false;
    }

    /**
     * Whether the current user may create new employees through the bulk template.
     * The commit route is gated on EMPLOYEE_UPDATE; without this check an update-only
     * user could add brand-new staff via spare rows, bypassing EMPLOYEE_CREATE.
     */
    private function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::EMPLOYEE_CREATE) ?? false;
    }

    /**
     * Strip salary and bank fields from an employee payload unless the caller
     * holds the employee.view_salary permission.
     */
    private function maskSalary(Employee $employee, bool $canView): array
    {
        $data = $employee->toArray();

        if (!$canView) {
            foreach (['salary', 'bank_name', 'bank_branch', 'bank_code', 'account_number', 'payment_method'] as $field) {
                unset($data[$field]);
            }
        }

        return $data;
    }
}
