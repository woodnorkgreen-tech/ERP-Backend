<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Services\LeaveManagementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    public function __construct(private readonly LeaveManagementService $leaveService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canManage = $this->leaveService->canManage($user);

        $query = LeaveRequest::query()
            ->with(['employee.department', 'leaveType', 'creator', 'approver', 'contactEmployee.department'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('leave_type_id')) {
            $query->where('leave_type_id', $request->integer('leave_type_id'));
        }

        if ($request->filled('employee_id')) {
            $employee = $this->leaveService->resolveEmployeeForUser($user, $request->integer('employee_id'));
            if ($employee) {
                $query->where('employee_id', $employee->id);
            }
        } elseif (!$canManage) {
            $employee = $this->leaveService->resolveEmployeeForUser($user);
            $query->where('employee_id', $employee?->id ?? 0);
        }

        if ($request->filled('year')) {
            $query->whereYear('start_date', $request->integer('year'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->string('search');
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('reason', 'like', '%' . $search . '%')
                    ->orWhereHas('employee', function (Builder $employeeQuery) use ($search) {
                        $employeeQuery->where('first_name', 'like', '%' . $search . '%')
                            ->orWhere('last_name', 'like', '%' . $search . '%')
                            ->orWhere('employee_id', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('leaveType', function (Builder $typeQuery) use ($search) {
                        $typeQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        $requests = $query->paginate($request->integer('per_page', 12));

        return response()->json([
            'success' => true,
            'data' => $requests->items(),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorizeAccessToRequest($request->user(), $leaveRequest);

        return response()->json([
            'success' => true,
            'data' => $leaveRequest->load(['employee.department', 'leaveType', 'creator', 'approver', 'contactEmployee.department']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'contact_employee_id' => ['nullable', 'integer', 'different:employee_id', 'exists:employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'session' => ['required', Rule::in(['full_day', 'first_half', 'second_half'])],
            'reason' => ['required', 'string'],
            'explanation' => ['nullable', 'string'],
            'handover_notes' => ['nullable', 'string'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
        ]);

        $employee = $this->resolveTargetEmployee($user, $validated['employee_id'] ?? null);
        $leaveType = LeaveType::query()->whereKey($validated['leave_type_id'])->firstOrFail();
        $this->ensureContactEmployeeIsDifferent($employee->id, $validated['contact_employee_id'] ?? null);

        $this->leaveService->validateDateRange($validated['start_date'], $validated['end_date']);
        $this->leaveService->ensureNoOverlap($employee, $validated['start_date'], $validated['end_date']);

        $daysRequested = $this->leaveService->calculateBusinessDays(
            $validated['start_date'],
            $validated['end_date'],
            $validated['session']
        );
        $this->leaveService->ensureBalanceAvailable(
            $employee,
            $leaveType,
            $daysRequested,
            (int) date('Y', strtotime($validated['start_date'])),
            null,
            $validated['start_date']
        );

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'contact_employee_id' => $validated['contact_employee_id'] ?? null,
            'leave_type_id' => $leaveType->id,
            'created_by' => $user->id,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'days_requested' => $daysRequested,
            'session' => $validated['session'],
            'status' => LeaveRequest::STATUS_PENDING,
            'reason' => $validated['reason'],
            'explanation' => $validated['explanation'] ?? null,
            'handover_notes' => $validated['handover_notes'] ?? null,
            'attachment_path' => $validated['attachment_path'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Leave request submitted successfully.',
            'data' => $leaveRequest->load(['employee.department', 'leaveType', 'creator', 'approver', 'contactEmployee.department']),
        ], 201);
    }

    public function update(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $user = $request->user();
        $canManage = $this->leaveService->canManage($user);
        $this->authorizeAccessToRequest($user, $leaveRequest, allowManage: true);

        if ($leaveRequest->status !== LeaveRequest::STATUS_PENDING && !$canManage) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending requests can be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'contact_employee_id' => ['nullable', 'integer', 'different:employee_id', 'exists:employees,id'],
            'leave_type_id' => ['sometimes', 'required', 'integer', 'exists:leave_types,id'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after_or_equal:start_date'],
            'session' => ['sometimes', 'required', Rule::in(['full_day', 'first_half', 'second_half'])],
            'reason' => ['sometimes', 'required', 'string'],
            'explanation' => ['nullable', 'string'],
            'handover_notes' => ['nullable', 'string'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
            'review_notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in([
                LeaveRequest::STATUS_PENDING,
                LeaveRequest::STATUS_APPROVED,
                LeaveRequest::STATUS_REJECTED,
                LeaveRequest::STATUS_CANCELLED,
            ])],
        ]);

        if (array_key_exists('status', $validated) && !$canManage) {
            return response()->json([
                'success' => false,
                'message' => 'Only managers can change request status directly.',
            ], 403);
        }

        if (
            array_key_exists('status', $validated)
            && in_array($validated['status'], [LeaveRequest::STATUS_REJECTED, LeaveRequest::STATUS_CANCELLED], true)
            && blank($validated['review_notes'] ?? null)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'A reason is required when rejecting or cancelling a leave request.',
                'errors' => [
                    'review_notes' => ['A reason is required when rejecting or cancelling a leave request.'],
                ],
            ], 422);
        }

        $startDate = $validated['start_date'] ?? $leaveRequest->start_date?->toDateString();
        $endDate = $validated['end_date'] ?? $leaveRequest->end_date?->toDateString();
        $session = $validated['session'] ?? $leaveRequest->session;
        $leaveType = isset($validated['leave_type_id'])
            ? LeaveType::findOrFail($validated['leave_type_id'])
            : $leaveRequest->leaveType;
        $this->ensureContactEmployeeIsDifferent($leaveRequest->employee_id, $validated['contact_employee_id'] ?? $leaveRequest->contact_employee_id);

        $this->leaveService->validateDateRange($startDate, $endDate);
        $this->leaveService->ensureNoOverlap($leaveRequest->employee, $startDate, $endDate, $leaveRequest->id);

        $daysRequested = $this->leaveService->calculateBusinessDays($startDate, $endDate, $session);
        $this->leaveService->ensureBalanceAvailable(
            $leaveRequest->employee,
            $leaveType,
            $daysRequested,
            (int) date('Y', strtotime($startDate)),
            $leaveRequest->id,
            $startDate
        );

        $statusAttributes = [];
        if (array_key_exists('status', $validated)) {
            $statusAttributes = $this->statusTransitionAttributes(
                $leaveRequest,
                $validated['status'],
                $user->id,
                $validated['review_notes'] ?? $leaveRequest->review_notes
            );
        }

        $leaveRequest->update(array_merge($validated, $statusAttributes, [
            'days_requested' => $daysRequested,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'session' => $session,
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Leave request updated successfully.',
            'data' => $leaveRequest->fresh()->load(['employee.department', 'leaveType', 'creator', 'approver', 'contactEmployee.department']),
        ]);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        return $this->review($request, $leaveRequest, LeaveRequest::STATUS_APPROVED, 'Leave request approved.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        return $this->review($request, $leaveRequest, LeaveRequest::STATUS_REJECTED, 'Leave request rejected.');
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $user = $request->user();
        $this->authorizeAccessToRequest($user, $leaveRequest, allowManage: true);

        if (!in_array($leaveRequest->status, [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or approved requests can be cancelled.',
            ], 422);
        }

        if (blank($request->input('review_notes'))) {
            return response()->json([
                'success' => false,
                'message' => 'A reason is required when cancelling a leave request.',
                'errors' => [
                    'review_notes' => ['A reason is required when cancelling a leave request.'],
                ],
            ], 422);
        }

        $leaveRequest->update([
            'status' => LeaveRequest::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'review_notes' => $request->input('review_notes', $leaveRequest->review_notes),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Leave request cancelled successfully.',
            'data' => $leaveRequest->fresh()->load(['employee.department', 'leaveType', 'creator', 'approver', 'contactEmployee.department']),
        ]);
    }

    protected function review(
        Request $request,
        LeaveRequest $leaveRequest,
        string $status,
        string $message
    ): JsonResponse {
        if ($leaveRequest->status !== LeaveRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending requests can be reviewed.',
            ], 422);
        }

        $validated = $request->validate([
            'review_notes' => ['nullable', 'string'],
        ]);

        if ($status === LeaveRequest::STATUS_REJECTED && blank($validated['review_notes'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => 'A reason is required when rejecting a leave request.',
                'errors' => [
                    'review_notes' => ['A reason is required when rejecting a leave request.'],
                ],
            ], 422);
        }

        $leaveRequest->update([
            'status' => $status,
            'approved_by' => $request->user()->id,
            'approved_at' => $status === LeaveRequest::STATUS_APPROVED ? now() : null,
            'review_notes' => $validated['review_notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $leaveRequest->fresh()->load(['employee.department', 'leaveType', 'creator', 'approver', 'contactEmployee.department']),
        ]);
    }

    protected function statusTransitionAttributes(
        LeaveRequest $leaveRequest,
        string $status,
        int $actorId,
        ?string $reviewNotes = null
    ): array {
        return match ($status) {
            LeaveRequest::STATUS_PENDING => [
                'status' => LeaveRequest::STATUS_PENDING,
                'approved_by' => null,
                'approved_at' => null,
                'cancelled_at' => null,
                'review_notes' => $reviewNotes,
            ],
            LeaveRequest::STATUS_APPROVED => [
                'status' => LeaveRequest::STATUS_APPROVED,
                'approved_by' => $actorId,
                'approved_at' => $leaveRequest->status === LeaveRequest::STATUS_APPROVED && $leaveRequest->approved_at
                    ? $leaveRequest->approved_at
                    : now(),
                'cancelled_at' => null,
                'review_notes' => $reviewNotes,
            ],
            LeaveRequest::STATUS_REJECTED => [
                'status' => LeaveRequest::STATUS_REJECTED,
                'approved_by' => $actorId,
                'approved_at' => null,
                'cancelled_at' => null,
                'review_notes' => $reviewNotes,
            ],
            LeaveRequest::STATUS_CANCELLED => [
                'status' => LeaveRequest::STATUS_CANCELLED,
                'cancelled_at' => $leaveRequest->status === LeaveRequest::STATUS_CANCELLED && $leaveRequest->cancelled_at
                    ? $leaveRequest->cancelled_at
                    : now(),
                'review_notes' => $reviewNotes,
            ],
            default => [],
        };
    }

    protected function resolveTargetEmployee($user, ?int $employeeId): Employee
    {
        $employee = $this->leaveService->resolveEmployeeForUser($user, $employeeId);

        if (!$employee) {
            abort(422, 'No employee profile is linked to this account.');
        }

        return $employee;
    }

    protected function authorizeAccessToRequest($user, LeaveRequest $leaveRequest, bool $allowManage = false): void
    {
        if ($allowManage && $this->leaveService->canManage($user)) {
            return;
        }

        if ((int) $user->employee_id !== (int) $leaveRequest->employee_id) {
            abort(403, 'You are not allowed to access this leave request.');
        }
    }

    protected function ensureContactEmployeeIsDifferent(int $employeeId, ?int $contactEmployeeId): void
    {
        if ($contactEmployeeId !== null && $employeeId === $contactEmployeeId) {
            abort(422, 'Contact during leave must be a different employee.');
        }
    }
}
