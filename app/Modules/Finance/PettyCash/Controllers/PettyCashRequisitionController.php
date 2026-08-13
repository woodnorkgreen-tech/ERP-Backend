<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionItem;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\HR\Models\TechnicalLabour;
use Exception;
use App\Exceptions\GovernanceException;
use App\Services\Governance\ProjectGovernanceService;

class PettyCashRequisitionController extends Controller
{
    /**
     * Display a listing of requisitions.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $query = PettyCashRequisition::with(['requester.employee', 'department', 'approver', 'payee', 'project.enquiry', 'enquiry', 'items.payee'])
                ->withCount('items');

            // If not admin/finance, only show their own
            if ($user && !$user->can('viewAllRequisitions', PettyCashDisbursement::class)) {
                $query->where('user_id', $user->id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('requisition_number', 'like', "%{$search}%")
                      ->orWhere('category', 'like', "%{$search}%")
                      ->orWhere('purpose', 'like', "%{$search}%");
                });
            }

            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('created_at', [
                    $request->start_date . ' 00:00:00',
                    $request->end_date . ' 23:59:59'
                ]);
            }

            $perPage = $request->get('per_page', 15);
            $requisitions = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $requisitions->items(),
                'meta' => [
                    'current_page' => $requisitions->currentPage(),
                    'last_page' => $requisitions->lastPage(),
                    'per_page' => $requisitions->perPage(),
                    'total' => $requisitions->total(),
                ]
            ]);
        } catch (Exception $e) {
            \Log::error("Failed to retrieve requisitions: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve requisitions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get summary statistics for requisitions.
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $query = PettyCashRequisition::query();

            // Scope to user if not admin
            if ($user && !$user->can('viewAllRequisitions', PettyCashDisbursement::class)) {
                $query->where('user_id', $user->id);
            }

            $statuses = ['pending', 'approved', 'disbursed', 'received', 'rejected'];
            $stats = collect($statuses)
                ->mapWithKeys(fn ($status) => [
                    $status => ['count' => 0, 'amount' => 0.0],
                ])
                ->all();

            $statusRows = (clone $query)
                ->select('status', DB::raw('COUNT(*) as aggregate_count'), DB::raw('COALESCE(SUM(total_amount), 0) as aggregate_amount'))
                ->whereIn('status', $statuses)
                ->groupBy('status')
                ->get();

            foreach ($statusRows as $row) {
                $stats[$row->status] = [
                    'count' => (int) $row->aggregate_count,
                    'amount' => (float) $row->aggregate_amount,
                ];
            }

            $monthStart = now()->startOfMonth();
            $monthEnd = now()->endOfMonth();
            $todayStart = now()->startOfDay();
            $todayEnd = now()->endOfDay();

            $monthly = (clone $query)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(total_amount), 0) as aggregate_amount')
                ->first();

            $disbursedToday = (clone $query)
                ->where('status', 'disbursed')
                ->whereBetween('updated_at', [$todayStart, $todayEnd])
                ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(total_amount), 0) as aggregate_amount')
                ->first();

            $stats = [
                ...$stats,
                'monthly' => [
                    'count' => (int) ($monthly->aggregate_count ?? 0),
                    'amount' => (float) ($monthly->aggregate_amount ?? 0),
                ],
                'disbursed_today' => [
                    'count' => (int) ($disbursedToday->aggregate_count ?? 0),
                    'amount' => (float) ($disbursedToday->aggregate_amount ?? 0),
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stats',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created requisition.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'department_id' => 'required|exists:departments,id',
            'category' => 'required|string',
            'purpose' => 'required|string',
            'payee_id' => 'nullable|exists:employees,id',
            'payee_name' => 'nullable|string',
            'payee_phone' => 'nullable|string|max:255',
            'project_id' => 'nullable|exists:projects,id',
            'project_name' => 'nullable|string|max:255',
            'venue' => 'nullable|string|max:255',
            'enquiry_id' => 'nullable|exists:project_enquiries,id',
            'bill_id' => 'nullable|exists:bills,id',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.remarks' => 'nullable|string',
            'items.*.amount' => 'required|numeric|min:0.01',
            'items.*.payee_id' => 'nullable|exists:employees,id',
            'items.*.payee_name' => 'nullable|string',
            'items.*.payee_phone' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->category === 'Projects' && !$request->filled('project_id') && !$request->filled('enquiry_id') && !$request->filled('project_name')) {
            return response()->json([
                'success' => false,
                'errors' => ['project_id' => ['Project requisitions must be linked to a project, enquiry, or named project.']],
            ], 422);
        }

        try {
            DB::beginTransaction();

            $requisitions = [];
            $commonData = [
                'user_id' => Auth::id(),
                'department_id' => $request->department_id,
                'category' => $request->category,
                'purpose' => $request->purpose,
                'project_id' => $request->project_id,
                'project_name' => $request->project_name,
                'venue' => $request->venue,
                'enquiry_id' => $request->enquiry_id,
                'bill_id' => $request->bill_id,
                'status' => 'pending',
            ];

            $requisition = PettyCashRequisition::create(array_merge($commonData, [
                'requisition_number' => PettyCashRequisition::generateRequisitionNumber(),
                'payee_id' => $request->payee_id ?? null,
                'payee_name' => $request->payee_name ?? null,
                'payee_phone' => $request->payee_phone ?? null,
                'total_amount' => collect($request->items)->sum('amount'),
            ]));

            foreach ($request->items as $item) {
                $requisition->items()->create([
                    'description' => $item['description'],
                    'remarks' => $item['remarks'] ?? null,
                    'amount' => $item['amount'],
                    'payee_id' => $item['payee_id'] ?? null,
                    'payee_name' => $item['payee_name'] ?? null,
                    'payee_phone' => $item['payee_phone'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Requisition submitted successfully',
                'data' => $requisition->load('items.payee')
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            
            // Log detailed error information for debugging
            \Log::error('Petty Cash Requisition Creation Failed', [
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
                'user_id' => Auth::id()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit requisition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified requisition.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $requisition = PettyCashRequisition::with(['requester.employee', 'department', 'items.payee', 'approver', 'disbursement', 'payee', 'project.enquiry', 'enquiry'])
                ->findOrFail($id);

            if (!$this->mayView($requisition)) {
                return response()->json(['success' => false, 'message' => 'You may only view your own requisitions'], 403);
            }

            // Ensure signing token exists (lazy generation)
            if (!$requisition->signing_token) {
                $requisition->signing_token = \Illuminate\Support\Str::random(60);
                $requisition->save();
            }

            return response()->json([
                'success' => true,
                'data' => $requisition
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Requisition not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified requisition.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $requisition = PettyCashRequisition::findOrFail($id);

        if (!$this->mayEdit($requisition)) {
            return response()->json(['success' => false, 'message' => 'This requisition cannot be edited by this user or in its current state'], 403);
        }

        // Validation rules based on status
        $rules = [
            'department_id' => 'required|exists:departments,id',
            'category' => 'required|string',
            'purpose' => 'required|string',
            'project_id' => 'nullable|exists:projects,id',
            'project_name' => 'nullable|string|max:255',
            'venue' => 'nullable|string|max:255',
            'enquiry_id' => 'nullable|exists:project_enquiries,id',
            'bill_id' => 'nullable|exists:bills,id',
            'payee_id' => 'nullable|exists:employees,id',
            'payee_name' => 'nullable|string',
            'payee_phone' => 'nullable|string|max:255',
        ];

        // If not disbursed, allow updating items/amount
        if ($requisition->status !== 'disbursed') {
            $rules['items'] = 'required|array|min:1';
            $rules['items.*.description'] = 'required|string';
            $rules['items.*.remarks'] = 'nullable|string';
            $rules['items.*.amount'] = 'required|numeric|min:0.01';
            $rules['items.*.payee_id'] = 'nullable|exists:employees,id';
            $rules['items.*.payee_name'] = 'nullable|string';
            $rules['items.*.payee_phone'] = 'nullable|string|max:255';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->category === 'Projects' && !$request->filled('project_id') && !$request->filled('enquiry_id') && !$request->filled('project_name')) {
            return response()->json([
                'success' => false,
                'errors' => ['project_id' => ['Project requisitions must be linked to a project, enquiry, or named project.']],
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Handling Disbursed Requisitions
            if ($requisition->status === 'disbursed') {
                // Restricted update: Only non-financial fields
                $requisition->update([
                    'department_id' => $request->department_id,
                    'category' => $request->category,
                    'purpose' => $request->purpose,
                    'project_id' => $request->project_id,
                    'project_name' => $request->project_name,
                    'venue' => $request->venue,
                    'enquiry_id' => $request->enquiry_id,
                    'bill_id' => $request->bill_id,
                    // DO NOT update amount, items, or payee causing financial discrepancies
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Requisition details updated successfully (Financials locked)',
                    'data' => $requisition
                ]);
            }

            // Handling Pending/Approved/Rejected
            $commonData = [
                'department_id' => $request->department_id,
                'category' => $request->category,
                'purpose' => $request->purpose,
                'project_id' => $request->project_id,
                'project_name' => $request->project_name,
                'venue' => $request->venue,
                'enquiry_id' => $request->enquiry_id,
                'bill_id' => $request->bill_id,
                'payee_id' => $request->payee_id ?? null,
                'payee_name' => $request->payee_name ?? null,
                'payee_phone' => $request->payee_phone ?? null,
                'total_amount' => collect($request->items)->sum('amount'),
            ];

            // If it was approved, revert to pending for re-approval because details changed
            if ($requisition->status === 'approved') {
                $commonData['status'] = 'pending';
                $commonData['approved_by'] = null;
                $commonData['approved_at'] = null;
            }

            $requisition->update($commonData);

            // Sync Items: Delete old ones and create new ones
            // A smarter sync could update existing IDs, but replace is safer for integrity here
            $requisition->items()->delete();

            foreach ($request->items as $item) {
                $requisition->items()->create([
                    'description' => $item['description'],
                    'remarks' => $item['remarks'] ?? null,
                    'amount' => $item['amount'],
                    'payee_id' => $item['payee_id'] ?? null,
                    'payee_name' => $item['payee_name'] ?? null,
                    'payee_phone' => $item['payee_phone'] ?? null,
                    'requisition_id' => $requisition->id
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $requisition->wasChanged('status') && $requisition->status === 'pending' 
                    ? 'Requisition updated and reverted to Pending status' 
                    : 'Requisition updated successfully',
                'data' => $requisition->load('items.payee')
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update requisition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified requisition.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $requisition = PettyCashRequisition::findOrFail($id);

            if (!$this->mayEdit($requisition) || !in_array($requisition->status, ['pending', 'rejected'], true)) {
                return response()->json(['success' => false, 'message' => 'Only pending or rejected requisitions may be deleted'], 409);
            }

            $requisition->delete(); // Soft delete

            return response()->json([
                'success' => true,
                'message' => 'Requisition deleted successfully'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete requisition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve a requisition.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $requisition = PettyCashRequisition::findOrFail($id);

            if (!Auth::user()?->can('update', PettyCashDisbursement::class)) {
                return response()->json(['success' => false, 'message' => 'You are not authorized to approve requisitions'], 403);
            }
            $selfApproval = $requisition->user_id === Auth::id();
            if ($selfApproval && ! \App\Support\SelfApproval::allowed()) {
                return response()->json([
                    'success' => false,
                    'code' => 'SELF_APPROVAL_FORBIDDEN',
                    'message' => 'You raised this requisition, so someone else has to approve it. If nobody else is available, an administrator can grant the "Approve Your Own Submissions" permission.',
                ], 403);
            }
            if ($selfApproval) {
                $request->validate([
                    'override_reason' => ['required', 'string', 'min:15', 'max:1000'],
                ], [
                    'override_reason.required' => 'Explain why independent approval is unavailable.',
                    'override_reason.min' => 'The override reason must be at least 15 characters.',
                ]);
            }
            
            if ($requisition->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending requisitions can be approved'
                ], 400);
            }

            $enquiry = $requisition->enquiry ?? $requisition->project?->enquiry;
            if ($enquiry) {
                $gate = app(ProjectGovernanceService::class)->checkGate($enquiry, 'expenditure', [
                    'amount' => (float) $requisition->total_amount,
                    'source' => 'petty_cash_requisition',
                    'requisition_id' => $requisition->id,
                ]);
                if (!$gate->isAuthorized()) {
                    return response()->json([
                        'success' => false,
                        'message' => $gate->getMessage(),
                        'code' => 'PROJECT_BUDGET_NOT_READY',
                        'context' => $gate->context,
                    ], 422);
                }
            }

            $requisition->update([
                'status' => 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            if ($selfApproval) {
                app(PettyCashService::class)->logActivity(
                    'self_approval_override',
                    'requisition',
                    $requisition->id,
                    "Emergency self-approval override for {$requisition->requisition_number}",
                    [
                        'reason' => $request->string('override_reason')->toString(),
                        'amount' => (float) $requisition->total_amount,
                        'project_id' => $requisition->project_id,
                        'enquiry_id' => $requisition->enquiry_id,
                    ],
                );
            }

            return response()->json([
                'success' => true,
                'message' => $selfApproval
                    ? 'Requisition approved using the audited emergency override'
                    : 'Requisition marked as Approved',
                'data' => $requisition
            ]);
        } catch (GovernanceException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'PROJECT_BUDGET_NOT_READY',
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve requisition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Disburse a requisition.
     */
    public function disburse(Request $request, int $id): JsonResponse
    {
        try {
            $requisition = PettyCashRequisition::findOrFail($id);

            if (!Auth::user()?->can('create', PettyCashDisbursement::class)) {
                return response()->json(['success' => false, 'message' => 'You are not authorized to disburse requisitions'], 403);
            }
            if ($requisition->user_id === Auth::id() && ! \App\Support\SelfApproval::allowed()) {
                return response()->json(['success' => false, 'message' => 'You raised this requisition, so someone else has to pay it out. If nobody else is available, an administrator can grant the "Approve Your Own Submissions" permission.'], 403);
            }
            
            if ($requisition->status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only approved requisitions can be disbursed'
                ], 400);
            }

            $service = app(PettyCashService::class);

            $validatedPayment = $request->validate([
                'idempotency_key' => ['required', 'uuid'],
                'expense_code_id' => ['required', 'integer', 'exists:expense_codes,id'],
                'payment_source_id' => ['required', 'integer', 'exists:payment_sources,id'],
                'planned_cost_line_id' => ['nullable', 'integer', 'exists:cost_lines,id'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'receiver' => ['required', 'string', 'max:255'],
                'description' => ['required', 'string', 'max:1000'],
                'date_disbursed' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'transaction_cost' => ['nullable', 'numeric', 'min:0'],
                'transaction_code' => ['nullable', 'string', 'max:255'],
                'receipt_type' => ['required', 'in:etr,non_etr,none'],
                'receipt_number' => ['nullable', 'string', 'max:100', 'required_if:receipt_type,etr'],
                'tax_amount' => ['required', 'numeric', 'min:0', 'lte:amount'],
            ]);

            if (bccomp((string) $validatedPayment['amount'], (string) $requisition->total_amount, 2) !== 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'The payment must equal the approved requisition total. Edit and re-approve the requisition to change the amount.',
                    'errors' => ['amount' => ['Amount must equal the approved requisition total.']],
                ], 422);
            }
            
            // Prepare disbursement data
            $disbursementData = array_merge($request->only([
                'project_name', 'project_id', 'project_enquiry_id', 'job_number', 'venue',
            ]), $validatedPayment);
            $disbursementData['requisition_id'] = $requisition->id;
            $disbursementData['status'] = 'active';

            // Re-validate
            $validationErrors = $service->validateDisbursementData($disbursementData);
            if (!empty($validationErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Disbursement validation failed',
                    'errors' => $validationErrors,
                ], 422);
            }

            $result = $service->createDisbursement($disbursementData);
            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create disbursement',
                    'errors' => $result['errors'],
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Requisition disbursed. QR code link generated.',
                'data' => $requisition->fresh(['disbursement', 'requester', 'department', 'items'])
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to disburse requisition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject a requisition.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $requisition = PettyCashRequisition::findOrFail($id);

            if (!Auth::user()?->can('update', PettyCashDisbursement::class)) {
                return response()->json(['success' => false, 'message' => 'You are not authorized to reject requisitions'], 403);
            }
            if ($requisition->user_id === Auth::id() && ! \App\Support\SelfApproval::allowed()) {
                return response()->json([
                    'success' => false,
                    'code' => 'SELF_REVIEW_FORBIDDEN',
                    'message' => 'You raised this requisition, so someone else has to reject it. If nobody else is available, an administrator can grant the "Approve Your Own Submissions" permission.',
                ], 403);
            }

            if ($requisition->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending requisitions can be rejected'
                ], 400);
            }

            $requisition->update([
                'status' => 'rejected',
                'rejection_reason' => $request->reason,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Requisition rejected',
                'data' => $requisition
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject requisition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirm receipt of cash (requester).
     */
    public function confirmReceipt(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'signature' => 'required|string', // Base64 signature
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $requisition = PettyCashRequisition::findOrFail($id);

            if ($requisition->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the requester can confirm receipt'
                ], 403);
            }

            if ($requisition->status !== 'disbursed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Requisition must be disbursed before confirming receipt'
                ], 400);
            }

            $requisition->update([
                'status' => 'received',
                'digital_signature' => $request->signature,
                'received_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Receipt confirmed successfully',
                'data' => $requisition
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm receipt',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirm receipt of cash for a specific item (bulk mode).
     */
    public function confirmItemReceipt(Request $request, int $requisitionId, int $itemId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'signature' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $item = PettyCashRequisitionItem::where('requisition_id', $requisitionId)
                ->with('payee.user')
                ->findOrFail($itemId);

            $requisition = PettyCashRequisition::with('items')->findOrFail($requisitionId);
            $isRequester = $requisition->user_id === Auth::id();
            $isPayee = $item->payee?->user?->id === Auth::id();
            if (!$isRequester && !$isPayee) {
                return response()->json(['success' => false, 'message' => 'Only the requester or named recipient may confirm this item'], 403);
            }
            if ($requisition->status !== 'disbursed') {
                return response()->json(['success' => false, 'message' => 'Receipt can only be confirmed after disbursement'], 409);
            }
            
            $item->update([
                'digital_signature' => $request->signature,
                'received_at' => now(),
            ]);

            // Check if all items in the requisition are now received
            $requisition->load('items');
            $allItemsReceived = $requisition->items->every(fn($i) => $i->received_at !== null);

            if ($allItemsReceived) {
                $requisition->update(['status' => 'received']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Item receipt confirmed',
                'data' => $item
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm item receipt',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get categories and departments for the form.
     */
    public function getFormData(): JsonResponse
    {
        $departments = Department::select('id', 'name')->get();
        $employees = Employee::select('id', 'first_name', 'last_name', 'phone')->where('status', 'active')->orderBy('first_name')->get();
        
        // Fetch Projects and Enquiries
        $projects = Project::with('enquiry')
            ->whereIn('status', ['Planning', 'active', 'planning', 'Active', 'In Progress', 'in_progress'])
            ->get()
            ->map(function($p) {
                $title = $p->enquiry->title ?? 'No Title';
                $jobNumber = $p->project_id; // This is the WNG- prefix ID
                return [
                    'id' => $p->id,
                    'label' => "{$jobNumber} - {$title}",
                    'type' => 'project'
                ];
            });

        $enquiries = ProjectEnquiry::select('id', 'title', 'enquiry_number')
            ->whereDoesntHave('project') // Exclude those already converted to projects
            ->whereNotIn('status', ['lost', 'completed', 'quote_approved'])
            ->get()
            ->map(function($e) {
                $label = $e->enquiry_number ? "Enquiry #{$e->enquiry_number}: {$e->title}" : "Enquiry: {$e->title}";
                return [
                    'id' => $e->id,
                    'label' => $label,
                    'type' => 'enquiry'
                ];
            });

        $categories = [
            'Projects',
            'Office Supplies',
            'Transport',
            'Meals',
            'Repair & Maintenance',
            'Fuel & Lubricants',
            'Communication & Airtime',
            'Miscellaneous'
        ];

        return response()->json([
            'success' => true,
            'departments' => $departments,
            'employees' => $employees,
            'categories' => $categories,
            'projects' => $projects,
            'enquiries' => $enquiries
        ]);
    }

    /**
     * Get team members for a specific project or enquiry.
     */
    public function getProjectTeamMembers(\Illuminate\Http\Request $request): JsonResponse
    {
        $projectId = $request->query('project_id');
        $enquiryId = $request->query('enquiry_id');

        $project = null;
        $enquiry = null;

        if ($projectId) {
            $project = \App\Models\Project::with('enquiry')->find($projectId);
            if ($project) {
                $enquiryId = $project->enquiry_id;
                $enquiry = $project->enquiry;
            }
        } elseif ($enquiryId) {
            $enquiry = \App\Models\ProjectEnquiry::find($enquiryId);
        }
    
        if (!$projectId && !$enquiryId) {
            return response()->json(['success' => false, 'members' => []]);
        }

    $query = \App\Modules\Teams\Models\TeamsMember::with(['technicalLabour', 'teamsTask.category']);

    if ($projectId && $enquiryId) {
        $query->whereHas('teamsTask', function ($q) use ($projectId, $enquiryId) {
            $q->where('project_id', $projectId)
              ->orWhereHas('task', function ($tq) use ($enquiryId) {
                  $tq->where('project_enquiry_id', $enquiryId);
              });
        });
    } elseif ($projectId) {
        $query->whereHas('teamsTask', function ($q) use ($projectId) {
            $q->where('project_id', $projectId);
        });
    } else {
        $query->whereHas('teamsTask.task', function ($q) use ($enquiryId) {
            $q->where('project_enquiry_id', $enquiryId);
        });
    }

    $members = $query->get()->map(function ($m) {
        // Try to find corresponding employee if not directly linked
        $employee = null;
        if ($m->member_email) {
            $employee = \App\Modules\HR\Models\Employee::where('email', $m->member_email)->first();
        }
        
        if (!$employee && $m->member_name) {
            // ONLY match if the name is reasonably long and unique
            // Avoid matching short names like "true" or "cosmas" if they might be ambiguous
            if (strlen($m->member_name) > 3) {
                 $employee = \App\Modules\HR\Models\Employee::where(DB::raw("CONCAT(first_name, ' ', last_name)"), $m->member_name)
                    ->orWhere(DB::raw("CONCAT(last_name, ' ', first_name)"), $m->member_name)
                    ->first();
            }
        }

        $category = $m->teamsTask?->category?->name ?? 'Other';
        
        // Final fallback chain for phone: Member Record -> Employee Profile -> Tech Labour Profile
        $phone = $m->member_phone ?: ($employee?->phone ?: ($m->technicalLabour?->phone ?: ''));

        return [
            'id' => $m->id,
            'name' => trim($m->member_name),
            'payee_id' => $employee?->id,
            'payee_phone' => $phone,
            'role' => $m->member_role,
            'is_internal' => (bool)$employee,
            'category' => $category
        ];
    })
    ->sortByDesc(fn($item) => (bool)$item['payee_phone']) // Put records with phones at the top
    ->unique(function ($item) {
        // Robust unique key: normalized name + normalized category
        return strtolower(trim($item['name'])) . '|' . strtolower(trim($item['category']));
    })
    ->values();

        return response()->json([
            'success' => true,
            'members' => $members,
            'project' => $project,
            'enquiry' => $enquiry
        ]);
    }

    /**
     * Download requisition voucher PDF.
     */
    public function downloadVoucher(int $id)
    {
        $requisition = PettyCashRequisition::with([
            'requester', 'department', 'approver', 'payee',
            'project.enquiry', 'enquiry', 'items.payee',
            'disbursement'
        ])->findOrFail($id);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.finance.requisition-voucher', compact('requisition'));
        
        return $pdf->download("Voucher-{$requisition->requisition_number}.pdf");
    }

    /**
     * Search for payees (Employees + Technical Labour).
     */
    public function searchPayees(Request $request): JsonResponse
    {
        $query = $request->get('query', '');
        
        if (strlen($query) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // Search Employees
        $employees = Employee::where('status', 'active')
            ->where(function($q) use ($query) {
                $q->where('first_name', 'like', "%{$query}%")
                  ->orWhere('last_name', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%");
            })
            ->select('id', 'first_name', 'last_name', 'position', 'phone')
            ->limit(10)
            ->get()
            ->map(function($e) {
                return [
                    'id' => $e->id,
                    'name' => "{$e->first_name} {$e->last_name}",
                    'detail' => $e->position ?: 'Employee',
                    'type' => 'employee',
                    'phone' => $e->phone,
                    'payee_phone' => $e->phone
                ];
            });

        // Search Technical Labour
        $techLabour = \App\Modules\HR\Models\TechnicalLabour::where('status', 'active')
            ->where(function($q) use ($query) {
                $q->where('full_name', 'like', "%{$query}%")
                  ->orWhere('phone', 'like', "%{$query}%")
                  ->orWhere('specialization', 'like', "%{$query}%");
            })
            ->select('id', 'full_name', 'specialization', 'phone')
            ->limit(10)
            ->get()
            ->map(function($t) {
                return [
                    'id' => $t->id, // We'll need to handle ID collision in frontend if any
                    'name' => $t->full_name,
                    'detail' => $t->specialization ? "{$t->specialization} (Tech Labour)" : "Technical Labour",
                    'type' => 'technical_labour',
                    'phone' => $t->phone,
                    'payee_phone' => $t->phone
                ];
            });

        // Merge and limit
        $results = $employees->concat($techLabour)->take(15);

        return response()->json([
            'success' => true,
            'data' => $results->values()
        ]);
    }

    /**
     * Get requisition by signing token.
     */
    public function getByToken(string $token): JsonResponse
    {
        try {
            \Log::info('Public sign-off lookup', ['token' => $token]);
            
            $requisition = PettyCashRequisition::with(['requester.employee', 'department', 'disbursement', 'payee', 'project.enquiry', 'enquiry', 'items.payee'])
                ->where('signing_token', $token)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $requisition
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired signing link'
            ], 404);
        }
    }

    /**
     * Public sign-off via token.
     */
    public function publicSignOff(Request $request, string $token): JsonResponse
    {
        \Log::info('Public sign-off attempt', ['token' => $token, 'data' => $request->except('signature')]);
        
        $validator = Validator::make($request->all(), [
            'signature' => 'required|string',
            'received_by' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $requisition = PettyCashRequisition::where('signing_token', $token)->firstOrFail();

            if ($requisition->status !== 'disbursed') {
                return response()->json([
                    'success' => false,
                    'message' => 'This requisition is not in a signable state'
                ], 400);
            }

            $requisition->update([
                'status' => 'received',
                'digital_signature' => $request->signature,
                'received_by' => $request->received_by,
                'received_at' => now(),
                'signing_token' => null // Invalidate token after use
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Thank you! Your receipt of cash has been confirmed.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm receipt',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Public sign-off for a specific item via token.
     */
    public function publicItemSignOff(Request $request, string $token, int $itemId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'signature' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $requisition = PettyCashRequisition::where('signing_token', $token)->firstOrFail();

            if ($requisition->status !== 'disbursed') {
                return response()->json([
                    'success' => false,
                    'message' => 'This requisition is not in a signable state'
                ], 400);
            }

            $item = PettyCashRequisitionItem::where('requisition_id', $requisition->id)
                ->findOrFail($itemId);

            if ($item->received_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'This item has already been signed for'
                ], 400);
            }

            $item->update([
                'digital_signature' => $request->signature,
                'received_at' => now(),
            ]);

            // Check if all items in the requisition are now received
            $allItemsReceived = PettyCashRequisitionItem::where('requisition_id', $requisition->id)
                ->whereNull('received_at')
                ->count() === 0;

            if ($allItemsReceived) {
                $requisition->update([
                    'status' => 'received',
                    'received_at' => now(),
                    'received_by' => 'Bulk Recipients', // Fallback
                    'signing_token' => null // Invalidate token after all items signed
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Item receipt confirmed. Thank you!',
                'all_received' => $allItemsReceived
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm item receipt',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Get data for the public requisition form.
     */
    public function getPublicFormData(): JsonResponse
    {
        $departments = Department::select('id', 'name')->get();
        
        // Fetch Projects and Enquiries (Only active ones for public)
        $projects = Project::with('enquiry')
            ->whereIn('status', ['Planning', 'active', 'planning', 'Active', 'In Progress', 'in_progress'])
            ->get()
            ->map(function($p) {
                $title = $p->enquiry->title ?? 'No Title';
                $jobNumber = $p->project_id;
                return [
                    'id' => $p->id,
                    'label' => "{$jobNumber} - {$title}",
                    'type' => 'project'
                ];
            });

        $enquiries = ProjectEnquiry::select('id', 'title', 'enquiry_number')
            ->whereDoesntHave('project') // Exclude those already converted to projects
            ->whereNotIn('status', ['lost', 'completed', 'quote_approved'])
            ->get()
            ->map(function($e) {
                $label = $e->enquiry_number ? "Enquiry #{$e->enquiry_number}: {$e->title}" : "Enquiry: {$e->title}";
                return [
                    'id' => $e->id,
                    'label' => $label,
                    'type' => 'enquiry'
                ];
            });

        $categories = [
            'Projects',
            'Office Supplies',
            'Transport',
            'Meals',
            'Repair & Maintenance',
            'Fuel & Lubricants',
            'Communication & Airtime',
            'Miscellaneous'
        ];

        return response()->json([
            'success' => true,
            'departments' => $departments,
            'categories' => $categories,
            'projects' => $projects,
            'enquiries' => $enquiries
        ]);
    }

    /**
     * Store a public requisition.
     */
    public function publicStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'department_id' => 'required|exists:departments,id',
            'category' => 'required|string',
            'purpose' => 'required|string',
            'project_id' => 'nullable|exists:projects,id',
            'project_name' => 'nullable|string|max:255',
            'venue' => 'nullable|string|max:255',
            'enquiry_id' => 'nullable|exists:project_enquiries,id',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.remarks' => 'nullable|string',
            'items.*.amount' => 'required|numeric|min:0.01',
            'items.*.payee_name' => 'nullable|string',
            'items.*.payee_phone' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $requisition = PettyCashRequisition::create([
                'requisition_number' => PettyCashRequisition::generateRequisitionNumber(),
                'user_id' => null,
                'is_public' => true,
                'department_id' => $request->department_id,
                'category' => $request->category,
                'purpose' => $request->purpose,
                'project_id' => $request->project_id,
                'project_name' => $request->project_name,
                'venue' => $request->venue,
                'enquiry_id' => $request->enquiry_id,
                'status' => 'pending',
                'total_amount' => collect($request->items)->sum('amount'),
            ]);

            foreach ($request->items as $item) {
                $requisition->items()->create([
                    'description' => $item['description'],
                    'remarks' => $item['remarks'] ?? null,
                    'amount' => $item['amount'],
                    'payee_name' => $item['payee_name'] ?? null,
                    'payee_phone' => $item['payee_phone'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Public requisition submitted successfully',
                'data' => $requisition->load('items')
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            \Log::error('Public Petty Cash Requisition Failed', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit requisition',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Search for payees publicly (Limited data exposure).
     */
    public function publicSearchPayees(Request $request): JsonResponse
    {
        $query = $request->get('query', '');
        
        if (strlen($query) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // Search Employees (System Users)
        $employees = User::where('is_active', true)
            ->where('name', 'like', "%{$query}%")
            ->with(['department', 'employee'])
            ->limit(10)
            ->get()
            ->map(function($u) {
                return [
                    'id' => null, 
                    'name' => $u->name,
                    'detail' => $u->department->name ?? 'System User',
                    'type' => 'employee',
                    'payee_phone' => $u->employee->phone ?? '' 
                ];
            });

        // Search Technical Labour
        $techLabour = TechnicalLabour::where('status', 'active')
            ->where(function($q) use ($query) {
                $q->where('full_name', 'like', "%{$query}%")
                  ->orWhere('specialization', 'like', "%{$query}%");
            })
            ->select('id', 'full_name', 'specialization', 'phone')
            ->limit(10)
            ->get()
            ->map(function($t) {
                return [
                    'id' => null, 
                    'name' => $t->full_name,
                    'detail' => $t->specialization ? "{$t->specialization}" : "Technical Labour",
                    'type' => 'technical_labour',
                    'payee_phone' => $t->phone 
                ];
            });

        $results = $employees->concat($techLabour)->take(15);

        return response()->json([
            'success' => true,
            'data' => $results
        ]);
    }

    /**
     * Get team members for a specific project or enquiry (Public).
     */
    public function getPublicProjectTeamMembers(\Illuminate\Http\Request $request): JsonResponse
    {
        // Reuse the existing internal logic but wrap it for public safety if needed
        return $this->getProjectTeamMembers($request);
    }

    private function mayView(PettyCashRequisition $requisition): bool
    {
        return $requisition->user_id === Auth::id()
            || (Auth::user()?->can('viewAllRequisitions', PettyCashDisbursement::class) ?? false);
    }

    private function mayEdit(PettyCashRequisition $requisition): bool
    {
        if (Auth::user()?->can('update', PettyCashDisbursement::class)) {
            return true;
        }

        return $requisition->user_id === Auth::id()
            && in_array($requisition->status, ['pending', 'rejected'], true);
    }
}
