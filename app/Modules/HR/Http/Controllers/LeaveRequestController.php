<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Notifications\LeaveRequestApprovedNotification;
use App\Modules\HR\Notifications\LeaveRequestRejectedNotification;
use App\Modules\HR\Notifications\LeaveRequestSubmittedNotification;
use App\Modules\HR\Services\LeaveManagementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    public function __construct(private readonly LeaveManagementService $leaveService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isGlobal = $this->leaveService->isGlobalManager($user);
        $isDeptLead = $user->isDeptLead();

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
        } elseif ($isGlobal) {
            // Full visibility — no filter
        } elseif ($isDeptLead) {
            $accessibleDeptIds = $user->getAccessibleDepartments()->pluck('id')->toArray();
            $query->whereHas('employee', function (Builder $q) use ($accessibleDeptIds) {
                $q->whereIn('department_id', $accessibleDeptIds);
            });
        } else {
            $employee = $this->leaveService->resolveEmployeeForUser($user);
            $query->where('employee_id', $employee?->id ?? 0);
        }

        if ($request->filled('year')) {
            $query->whereYear('start_date', $request->integer('year'));
        }

        if ($request->filled('period')) {
            $period = (string) $request->string('period');

            if ($period === 'this_week') {
                $query->whereBetween('start_date', [
                    now()->startOfWeek()->toDateString(),
                    now()->endOfWeek()->toDateString(),
                ]);
            } elseif ($period === 'this_month') {
                $query->whereBetween('start_date', [
                    now()->startOfMonth()->toDateString(),
                    now()->endOfMonth()->toDateString(),
                ]);
            } elseif ($period === 'recent') {
                $query->where('created_at', '>=', now()->subDays(14));
            }
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
        $leaveTypeId = $request->input('leave_type_id');
        $leaveType = LeaveType::find($leaveTypeId);
        
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
            'attachment' => $leaveType && $leaveType->requires_attachment 
                ? ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:2048']
                : ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:2048'],
        ]);

        $employee = $this->resolveTargetEmployee($user, $validated['employee_id'] ?? null);
        $leaveType = LeaveType::query()->whereKey($validated['leave_type_id'])->firstOrFail();
        $this->ensureContactEmployeeIsDifferent($employee->id, $validated['contact_employee_id'] ?? null);

        try {
            $this->leaveService->validateDateRange($validated['start_date'], $validated['end_date']);
            $this->leaveService->ensureNoOverlap($employee, $validated['start_date'], $validated['end_date']);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [
                    'date_range' => [$e->getMessage()],
                ],
            ], 422);
        }

        $daysRequested = $this->leaveService->calculateBusinessDays(
            $validated['start_date'],
            $validated['end_date'],
            $validated['session']
        );
        
        try {
            $this->leaveService->ensureBalanceAvailable(
                $employee,
                $leaveType,
                $daysRequested,
                (int) date('Y', strtotime($validated['start_date'])),
                null,
                $validated['start_date']
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [
                    'balance' => [$e->getMessage()],
                ],
            ], 422);
        }

        // Handle file upload
        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('leave-attachments', 'public');
        }

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
            'attachment_path' => $attachmentPath,
        ]);

        // Return the response immediately; notify managers after the request finishes.
        $this->notifyManagersAfterResponse($leaveRequest->id);

        return response()->json([
            'success' => true,
            'message' => 'Leave request submitted successfully.',
            'data' => $leaveRequest->load(['employee.department', 'leaveType', 'creator', 'approver', 'contactEmployee.department']),
        ], 201);
    }

    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'session' => ['required', Rule::in(['full_day', 'first_half', 'second_half'])],
            'ignore_request_id' => ['nullable', 'integer', 'exists:leave_requests,id'],
        ]);

        $employee = $this->resolveTargetEmployee($user, $validated['employee_id'] ?? null);
        $leaveType = LeaveType::findOrFail($validated['leave_type_id']);
        $year = (int) date('Y', strtotime($validated['start_date']));
        $ignoreRequestId = $validated['ignore_request_id'] ?? null;

        try {
            $this->leaveService->validateDateRange($validated['start_date'], $validated['end_date']);
            $this->leaveService->ensureNoOverlap(
                $employee,
                $validated['start_date'],
                $validated['end_date'],
                $ignoreRequestId
            );

            $daysRequested = $this->leaveService->calculateBusinessDays(
                $validated['start_date'],
                $validated['end_date'],
                $validated['session']
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [
                    'date_range' => [$exception->getMessage()],
                ],
            ], 422);
        }

        $balance = $this->leaveService->getLeaveBalance($employee->id, $leaveType->id, $year);
        $requestableBefore = (float) ($balance['requestable_days'] ?? $balance['available_days'] ?? 0);
        $availableBefore = (float) ($balance['available_days'] ?? 0);
        $canRequest = true;
        $validationMessage = null;

        try {
            $this->leaveService->ensureBalanceAvailable(
                $employee,
                $leaveType,
                $daysRequested,
                $year,
                $ignoreRequestId,
                $validated['start_date']
            );
        } catch (\InvalidArgumentException $exception) {
            $canRequest = false;
            $validationMessage = $exception->getMessage();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'year' => $year,
                'days_requested' => $daysRequested,
                'resumption_date' => $this->leaveService
                    ->calculateResumptionDate($validated['end_date'])
                    ->toDateString(),
                'can_request' => $canRequest,
                'validation_message' => $validationMessage,
                'balance' => $balance,
                'projected_balance' => [
                    'available_days' => max($availableBefore - $daysRequested, 0),
                    'requestable_days' => max($requestableBefore - $daysRequested, 0),
                ],
                'working_days_policy' => [
                    'saturday_is_working_day' => true,
                    'sunday_is_working_day' => false,
                    'excluded_weekdays' => ['sunday'],
                ],
            ],
        ]);
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
            'attachment' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:2048'],
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

        // Handle file upload
        $updateData = array_merge($validated, $statusAttributes, [
            'days_requested' => $daysRequested,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'session' => $session,
        ]);

        if ($request->hasFile('attachment')) {
            $updateData['attachment_path'] = $request->file('attachment')->store('leave-attachments', 'public');
        }

        $leaveRequest->update($updateData);

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
        $user = $request->user();

        if (!$this->leaveService->canManage($user)) {
            abort(403, 'You are not authorised to review leave requests.');
        }

        if (!$this->leaveService->isGlobalManager($user) && $user->isDeptLead()) {
            $accessibleDeptIds = $user->getAccessibleDepartments()->pluck('id')->toArray();
            $employee = $leaveRequest->employee ?? $leaveRequest->load('employee')->employee;
            if (!$employee || !in_array($employee->department_id, $accessibleDeptIds)) {
                abort(403, 'You can only review leave requests for employees in your department.');
            }
        }

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

        // Avoid blocking the review response on notification delivery.
        $this->notifyEmployeeAfterResponse($leaveRequest->id, $status);

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
        if ($allowManage && $this->leaveService->isGlobalManager($user)) {
            return;
        }

        if ((int) $user->employee_id === (int) $leaveRequest->employee_id) {
            return;
        }

        if ($user->isDeptLead()) {
            $accessibleDeptIds = $user->getAccessibleDepartments()->pluck('id')->toArray();
            $employee = $leaveRequest->employee ?? $leaveRequest->load('employee')->employee;
            if ($employee && in_array($employee->department_id, $accessibleDeptIds)) {
                return;
            }
        }

        abort(403, 'You are not allowed to access this leave request.');
    }

    protected function ensureContactEmployeeIsDifferent(int $employeeId, ?int $contactEmployeeId): void
    {
        if ($contactEmployeeId !== null && $employeeId === $contactEmployeeId) {
            abort(422, 'Contact during leave must be a different employee.');
        }
    }

    protected function notifyManagersAfterResponse(int $leaveRequestId): void
    {
        dispatch(static function () use ($leaveRequestId): void {
            try {
                $leaveRequest = LeaveRequest::query()
                    ->with(['employee', 'leaveType'])
                    ->find($leaveRequestId);

                if (!$leaveRequest) {
                    return;
                }

                self::sendManagerNotifications($leaveRequest);
            } catch (\Throwable $exception) {
                \Log::warning('Failed to send leave request manager notifications.', [
                    'leave_request_id' => $leaveRequestId,
                    'error' => $exception->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    protected static function sendManagerNotifications(LeaveRequest $leaveRequest): void
    {
        $leaveRequest->loadMissing(['employee', 'leaveType']);

        $managers = User::query()
            ->whereHas('roles', function (Builder $query) {
                $query->whereIn('name', ['Super Admin', 'Admin', 'HR', 'Manager', 'Lead']);
            })
            ->whereNotNull('email')
            ->get();

        foreach ($managers as $manager) {
            $manager->notify(new LeaveRequestSubmittedNotification($leaveRequest));
        }
    }

    protected function notifyEmployeeAfterResponse(int $leaveRequestId, string $status): void
    {
        dispatch(static function () use ($leaveRequestId, $status): void {
            try {
                $leaveRequest = LeaveRequest::query()
                    ->with(['employee.user', 'leaveType', 'approver'])
                    ->find($leaveRequestId);

                if (!$leaveRequest) {
                    return;
                }

                self::sendEmployeeNotification($leaveRequest, $status);
            } catch (\Throwable $exception) {
                \Log::warning('Failed to send leave request employee notification.', [
                    'leave_request_id' => $leaveRequestId,
                    'status' => $status,
                    'error' => $exception->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    protected static function sendEmployeeNotification(LeaveRequest $leaveRequest, string $status): void
    {
        $leaveRequest->loadMissing(['employee.user', 'leaveType', 'approver']);

        $employee = $leaveRequest->employee;
        $user = $employee->user;

        if (!$user || !$user->email) {
            return;
        }

        if ($status === LeaveRequest::STATUS_APPROVED) {
            $user->notify(new LeaveRequestApprovedNotification($leaveRequest));
        } elseif ($status === LeaveRequest::STATUS_REJECTED) {
            $user->notify(new LeaveRequestRejectedNotification($leaveRequest));
        }
    }

    public function statistics(Request $request): JsonResponse
    {
        $user = $request->user();
        $year = $request->integer('year', now()->year);

        $query = LeaveRequest::query()
            ->whereYear('start_date', $year);

        if (!$this->leaveService->canManage($user)) {
            $employee = $this->leaveService->resolveEmployeeForUser($user);
            if ($employee) {
                $query->where('employee_id', $employee->id);
            }
        }

        $totalRequests = (clone $query)->count();
        $approvedRequests = (clone $query)->where('status', LeaveRequest::STATUS_APPROVED)->count();
        $pendingRequests = (clone $query)->where('status', LeaveRequest::STATUS_PENDING)->count();
        $rejectedRequests = (clone $query)->where('status', LeaveRequest::STATUS_REJECTED)->count();
        $cancelledRequests = (clone $query)->where('status', LeaveRequest::STATUS_CANCELLED)->count();

        $totalDays = (clone $query)->sum('days_requested');
        $approvedDays = (clone $query)->where('status', LeaveRequest::STATUS_APPROVED)->sum('days_requested');

        $byLeaveType = LeaveRequest::query()
            ->whereYear('start_date', $year)
            ->selectRaw('leave_type_id, COUNT(*) as count, SUM(days_requested) as total_days')
            ->groupBy('leave_type_id')
            ->with('leaveType:id,name,code,color')
            ->get()
            ->map(function ($item) {
                return [
                    'leave_type_id' => $item->leave_type_id,
                    'name' => $item->leaveType->name,
                    'code' => $item->leaveType->code,
                    'color' => $item->leaveType->color,
                    'count' => $item->count,
                    'total_days' => $item->total_days,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'total_requests' => $totalRequests,
                'approved_requests' => $approvedRequests,
                'pending_requests' => $pendingRequests,
                'rejected_requests' => $rejectedRequests,
                'cancelled_requests' => $cancelledRequests,
                'total_days' => $totalDays,
                'approved_days' => $approvedDays,
                'by_leave_type' => $byLeaveType,
            ],
        ]);
    }

    public function adjustBalance(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->leaveService->canManage($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Only managers can adjust leave balances.',
            ], 403);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'adjustment_days' => ['required', 'numeric', 'min:-365', 'max:365'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $leaveType = LeaveType::findOrFail($validated['leave_type_id']);
        $year = now()->year;

        // Create a special leave request for adjustment
        $adjustment = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'created_by' => $user->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'days_requested' => abs($validated['adjustment_days']),
            'session' => 'full_day',
            'status' => LeaveRequest::STATUS_APPROVED,
            'reason' => 'Balance adjustment: ' . $validated['reason'],
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Leave balance adjusted successfully.',
            'data' => $adjustment->load(['employee.department', 'leaveType']),
        ]);
    }

    public function recall(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $user = $request->user();

        if (!$this->leaveService->canManage($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Only managers can recall employees from leave.',
            ], 403);
        }

        if (!$this->leaveService->isGlobalManager($user) && $user->isDeptLead()) {
            $accessibleDeptIds = $user->getAccessibleDepartments()->pluck('id')->toArray();
            $employee = $leaveRequest->employee ?? $leaveRequest->load('employee')->employee;
            if (!$employee || !in_array($employee->department_id, $accessibleDeptIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only recall employees in your department.',
                ], 403);
            }
        }

        // Validate that the leave request is approved
        if ($leaveRequest->status !== LeaveRequest::STATUS_APPROVED) {
            return response()->json([
                'success' => false,
                'message' => 'Only approved leave requests can be recalled.',
            ], 422);
        }

        $validated = $request->validate([
            'recall_reason' => ['required', 'string', 'max:500'],
            'days_to_recall' => ['nullable', 'numeric', 'min:1', 'max:' . $leaveRequest->days_requested],
        ]);

        $daysToRecall = $validated['days_to_recall'] ?? $leaveRequest->days_requested;
        $remainingDays = $leaveRequest->days_requested - $daysToRecall;

        try {
            DB::beginTransaction();

            // Update the leave request status
            $leaveRequest->update([
                'status' => LeaveRequest::STATUS_RECALLED,
                'recalled_by' => $user->id,
                'recall_reason' => $validated['recall_reason'],
                'recalled_at' => now(),
            ]);

            // Restore the days to employee's leave balance
            $this->leaveService->restoreLeaveBalance($leaveRequest, $daysToRecall);

            // Log the recall action for audit purposes
            \Log::info('Leave recall audit', [
                'action' => 'leave_recalled',
                'leave_request_id' => $leaveRequest->id,
                'employee_id' => $leaveRequest->employee_id,
                'leave_type_id' => $leaveRequest->leave_type_id,
                'original_days' => $leaveRequest->days_requested,
                'days_recalled' => $daysToRecall,
                'remaining_days' => $remainingDays,
                'recalled_by' => $user->id,
                'recall_reason' => $validated['recall_reason'],
                'recalled_at' => now()->toDateTimeString(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Employee recalled from leave successfully.',
                'data' => [
                    'leave_request' => $leaveRequest->load(['employee.department', 'leaveType', 'recalledBy']),
                    'recall_summary' => [
                        'original_days' => $leaveRequest->days_requested,
                        'days_recalled' => $daysToRecall,
                        'remaining_days' => $remainingDays,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to recall employee from leave: ' . $e->getMessage(),
            ], 500);
        }
    }
}
