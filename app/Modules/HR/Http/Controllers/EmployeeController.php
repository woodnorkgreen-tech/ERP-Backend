<?php

namespace App\Modules\HR\Http\Controllers;

use App\Constants\Permissions;
use App\Modules\HR\Exports\EmployeesTemplateExport;
use App\Modules\HR\Imports\EmployeesTemplateImport;
use App\Modules\HR\Http\Requests\StoreEmployeeRequest;
use App\Modules\HR\Http\Requests\UpdateEmployeeRequest;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class EmployeeController
{
    /**
     * Display a listing of employees.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

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

        $canViewSalary = $this->canViewSalary();
        $canViewPii    = $this->canViewPii();

        // Check if pagination is requested
        if ($request->has('per_page')) {
            $employees = $query->paginate($request->get('per_page', 15));

            // Unfiltered base for global KPI counts (ignores status/search/dept filters)
            $statsBase = Employee::query()->accessibleByUser();

            return response()->json([
                'data' => collect($employees->items())->map(fn ($e) => $this->maskSensitive($e, $canViewSalary, $canViewPii)),
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

            return response()->json($employees->map(fn ($e) => $this->maskSensitive($e, $canViewSalary, $canViewPii)));
        }
    }

    /**
     * Store a newly created employee.
     */
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $customId      = $validatedData['employee_id'] ?? null;

        // employees.employee_id is NOT NULL + UNIQUE with no default, so it must be
        // present at insert. Use the supplied id, otherwise a transient unique
        // placeholder, then derive the final staff number from the real
        // auto-increment id (race-safe).
        $validatedData['employee_id'] = $customId ?: 'PENDING-' . uniqid('', true);

        $employee = Employee::create($validatedData);

        if (! $customId) {
            $employee->employee_id = 'EMP' . str_pad($employee->id, 4, '0', STR_PAD_LEFT);
            $employee->save();
        }

        return response()->json([
            'message' => 'Employee created successfully',
            'data'    => $employee->load(['department', 'manager'])
        ], 201);
    }

    /**
     * Display the specified employee.
     */
    public function show(Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);

        $data = $employee->load(['department', 'manager']);

        return response()->json([
            'data' => $this->maskSensitive(
                $data,
                Gate::allows('viewSalary', $employee),
                Gate::allows('viewPii', $employee)
            )
        ]);
    }

    /**
     * Update the specified employee.
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        // UpdateEmployeeRequest::authorize() already checks isAccessibleBy() + permission.

        $validatedData = $request->validated();

        // Backfill employee_id if the record never had one
        if (empty($validatedData['employee_id']) && empty($employee->employee_id)) {
            $validatedData['employee_id'] = 'EMP' . str_pad($employee->id, 4, '0', STR_PAD_LEFT);
        }

        $employee->update($validatedData);

        return response()->json([
            'message' => 'Employee updated successfully',
            'data'    => $employee->load(['department', 'manager'])
        ]);
    }

    /**
     * Terminate the specified employee (soft-delete + status transition).
     *
     * Unlike the old implementation, termination is allowed even when the employee
     * has a linked user account — HR should revoke the user account separately; blocking
     * the HR workflow because an IT task is outstanding is the wrong gate. Instead, we
     * warn the caller if the account is still active so they know to act on it.
     *
     * Hard deletion is intentionally prevented to preserve payroll/audit history.
     */
    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('delete', $employee);

        $request->validate([
            'termination_reason' => 'nullable|string|max:1000',
            'termination_type'   => ['nullable', \Illuminate\Validation\Rule::in([
                'resignation', 'dismissal', 'redundancy', 'contract_expiry',
                'retirement', 'mutual_agreement', 'other',
            ])],
            'termination_date'   => 'nullable|date',
        ]);

        $employee->update([
            'status'             => 'terminated',
            'termination_reason' => $request->termination_reason,
            'termination_type'   => $request->termination_type,
            'termination_date'   => $request->termination_date ?? now()->toDateString(),
        ]);

        $employee->delete(); // soft delete — deleted_at is set; record is retained

        $warnings = [];
        if ($employee->user) {
            $warnings[] = 'The employee\'s user account is still active. Deactivate it via User Management to revoke system access.';
        }

        return response()->json([
            'message'  => 'Employee record terminated and archived.',
            'warnings' => $warnings,
        ]);
    }

    /**
     * Reinstate a terminated (soft-deleted) employee.
     * Only HR admins may reinstate — gated by EmployeePolicy::restore.
     */
    public function restore(int $id): JsonResponse
    {
        $employee = Employee::withTrashed()->findOrFail($id);

        Gate::authorize('restore', $employee);

        $employee->restore();
        $employee->update([
            'status'             => 'active',
            'termination_reason' => null,
            'termination_type'   => null,
            'termination_date'   => null,
        ]);

        return response()->json([
            'message' => 'Employee reinstated successfully.',
            'data'    => $employee->load(['department', 'manager']),
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
        Gate::authorize('uploadPhoto', $employee);

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
                'data'    => $employee->fresh()->load(['department', 'manager']),
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
        Gate::authorize('viewAny', Employee::class);

        $base = Employee::query()->accessibleByUser();

        return response()->json([
            'total'    => (clone $base)->count(),
            'active'   => (clone $base)->where('status', 'active')->count(),
            'on_leave' => (clone $base)->where('status', 'on-leave')->count(),
            'inactive' => (clone $base)->whereIn('status', ['inactive', 'terminated'])->count(),
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
                    'id'            => $emp->id,
                    'user_id'       => $emp->user?->id,
                    'employee_id'   => $emp->id, // Frontend uses DB ID for balance fetch usually
                    'name'          => "{$emp->first_name} {$emp->last_name}",
                    'job_title'     => $emp->position,
                    'department_id' => $emp->department_id,
                    'manager_id'    => $emp->manager_id,
                    'ot_balance'    => $emp->ot_balance
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

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Whether the current user may see/edit salary and bank columns.
     * Uses the EmployeePolicy::viewSalary ability, falling back to role check
     * for calls where no specific Employee model is in scope (e.g. index).
     */
    private function canViewSalary(?Employee $employee = null): bool
    {
        if ($employee) {
            return Gate::allows('viewSalary', $employee);
        }

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
     * Whether the current user may see regulated personal data (national ID,
     * KRA PIN, NSSF/NHIF numbers, date of birth, home address, next-of-kin).
     *
     * Without a specific employee in scope (e.g. index), falls back to role check;
     * with a specific employee, delegates to EmployeePolicy::viewPii.
     */
    private function canViewPii(?Employee $employee = null): bool
    {
        if ($employee) {
            return Gate::allows('viewPii', $employee);
        }

        return auth()->user()?->hasRole(['Super Admin', 'Admin', 'HR']) ?? false;
    }

    /**
     * Strip fields the caller is not entitled to see from an employee payload:
     *  - salary + bank details unless they may view salary,
     *  - regulated PII (ID/KRA/NSSF/NHIF/DOB/address/next-of-kin) unless they may view PII.
     */
    private function maskSensitive(Employee $employee, bool $canViewSalary, bool $canViewPii): array
    {
        $data = $employee->toArray();

        if (! $canViewSalary) {
            foreach (['salary', 'bank_name', 'bank_branch', 'bank_code', 'account_number', 'payment_method'] as $field) {
                unset($data[$field]);
            }
        }

        if (! $canViewPii) {
            foreach (['id_number', 'kra_pin', 'nssf_id', 'nhif_id', 'date_of_birth', 'address', 'emergency_contact'] as $field) {
                unset($data[$field]);
            }
        }

        return $data;
    }
}
