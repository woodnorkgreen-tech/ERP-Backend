<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\DirectDisbursementRequest;
use App\Constants\Permissions;
use App\Modules\Finance\PettyCash\Requests\CreateDisbursementRequest;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use App\Modules\Finance\PettyCash\Imports\PettyCashDisbursementImport;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use App\Modules\Finance\PettyCash\Resources\PettyCashBalanceResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;

class PettyCashController extends Controller
{
    protected $service;
    protected $repository;

    public function __construct(PettyCashService $service, PettyCashRepository $repository)
    {
        $this->service = $service;
        $this->repository = $repository;
    }

    /**
     * Get approved projects list for petty cash forms
     * Proxies to the Projects module logic to ensure consistent data access
     */
    public function getProjects(): JsonResponse
    {
        try {
            $projects = \App\Models\ProjectEnquiry::query()
                ->where('quote_approved', true)
                ->whereNotNull('job_number')
                ->with('project:id,enquiry_id,project_id')
                ->select('id', 'job_number', 'title')
                ->latest('id')
                ->take(250)
                ->get()
                ->sortByDesc(fn ($enquiry) => $this->jobNumberSortKey($enquiry->job_number))
                ->take(100)
                ->values()
                ->map(function ($enquiry) {
                    return [
                        'id' => $enquiry->id,
                        'job_number' => $enquiry->job_number,
                        'project_id' => $enquiry->project?->project_id,
                        'project_record_id' => $enquiry->project?->id,
                        'title' => $enquiry->title,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $projects
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve approved projects',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function jobNumberSortKey(?string $jobNumber): string
    {
        preg_match_all('/\d+/', (string) $jobNumber, $matches);

        $parts = array_map('intval', $matches[0] ?? []);

        return sprintf(
            '%04d%02d%06d',
            $parts[1] ?? 0,
            $parts[0] ?? 0,
            $parts[2] ?? 0
        );
    }

    public function disbursementReferences(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'expense_codes' => \App\Modules\Finance\CostCollector\Models\ExpenseCode::active()
                    ->orderBy('sort_order')->orderBy('expense_type')->get([
                        'id', 'code', 'expense_family', 'expense_type', 'job_id_rule', 'key_control',
                    ]),
                'payment_sources' => \App\Modules\Finance\Models\PaymentSource::query()
                    ->where('is_active', true)
                    ->whereIn('type', ['petty_cash', 'bank', 'mobile_money', 'card'])
                    ->orderBy('name')->get(['id', 'code', 'name', 'type', 'currency']),
            ],
        ]);
    }

    /**
     * Get a compact finance workspace snapshot for dashboard refreshes.
     */
    public function workspace(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['start_date', 'end_date', 'classification', 'project_name']);

            $balance = $this->repository->getCurrentBalance();
            $summary = $this->service->getTransactionSummary($filters);

            // `budget_snapshot` removed: no client ever read it, and building it
            // ran a full scan of every budget with the summing done in PHP. It
            // also carried the same fabricated category split as the retired
            // Project Budgets tab — budget_category is null on every
            // disbursement, so three of its four figures were always zero.
            // Finance › Cost Accounts answers this from the cost ledger.
            return response()->json([
                'success' => true,
                'data' => [
                    'balance' => (new PettyCashBalanceResource($balance))->resolve(),
                    'summary' => $summary,
                    'recent_transactions' => $this->service->getRecentTransactions(5),
                    'requisition_snapshot' => $this->getRequisitionSnapshot(),
                ],
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve finance workspace',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getRequisitionSnapshot(): array
    {
        $statuses = ['pending', 'approved', 'disbursed', 'received', 'rejected'];
        $baseQuery = \App\Modules\Finance\PettyCash\Models\PettyCashRequisition::query();

        $user = Auth::user();
        if ($user && !$user->can('viewAllRequisitions', PettyCashDisbursement::class)) {
            $baseQuery->where('user_id', $user->id);
        }

        $statusRows = (clone $baseQuery)
            ->select('status', \Illuminate\Support\Facades\DB::raw('COUNT(*) as aggregate_count'), \Illuminate\Support\Facades\DB::raw('COALESCE(SUM(total_amount), 0) as aggregate_amount'))
            ->whereIn('status', $statuses)
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $stats = collect($statuses)->mapWithKeys(function ($status) use ($statusRows) {
            $row = $statusRows->get($status);

            return [
                $status => [
                    'count' => (int) ($row->aggregate_count ?? 0),
                    'amount' => (float) ($row->aggregate_amount ?? 0),
                ],
            ];
        })->all();

        $latest = (clone $baseQuery)
            ->with(['requester:id,name', 'project:id,enquiry_id,project_id', 'project.enquiry:id,title'])
            ->latest()
            ->take(5)
            ->get();

        return [
            'stats' => $stats,
            'latest' => $latest,
        ];
    }

    /**
     * Get budget items/categories for a specific project
     */
    public function getProjectBudgetItems(string $jobNumber): JsonResponse
    {
        try {
            $enquiry = \App\Models\ProjectEnquiry::where('job_number', $jobNumber)
                ->with(['enquiryTasks' => function($q) {
                    $q->where('type', 'budget')
                      ->where('status', 'completed')
                      ->whereHas('budgetData')
                      ->with('budgetData');
                }])
                ->first();

            if (!$enquiry) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project not found'
                ], 404);
            }

            $items = \App\Modules\Finance\CostCollector\Models\CostLine::query()
                ->where('project_enquiry_id', $enquiry->id)
                ->where('nature', \App\Modules\Finance\CostCollector\Models\CostLine::NATURE_PLANNED)
                ->where('status', \App\Modules\Finance\CostCollector\Models\CostLine::STATUS_VERIFIED)
                ->withSum([
                    'consumers as spent' => fn ($query) => $query
                        ->where('status', \App\Modules\Finance\CostCollector\Models\CostLine::STATUS_VERIFIED),
                ], 'net_amount')
                ->orderBy('id')
                ->get()
                ->map(fn ($line) => [
                    'id' => $line->id,
                    'name' => $line->description ?: $line->ref,
                    'ref' => $line->ref,
                    'category' => $line->details['budget_category'] ?? 'uncategorised',
                    'budgeted' => (string) $line->net_amount,
                    'spent' => number_format((float) ($line->spent ?? 0), 2, '.', ''),
                    'remaining' => bcsub((string) $line->net_amount, (string) ($line->spent ?: '0'), 2),
                ])->values();

            return response()->json([
                'success' => true,
                'data' => $items
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve project budget items',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all active Chart of Accounts
     */
    public function accounts(): JsonResponse
    {
        try {
            $accounts = \App\Modules\Finance\Models\ChartOfAccount::where('is_active', true)
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $accounts
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve Chart of Accounts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of disbursements.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'status', 'classification', 'payment_method', 'project_name', 
                'creator_id', 'start_date', 'end_date', 'search'
            ]);

            $perPage = $request->get('per_page', 15);
            $disbursements = $this->repository->getDisbursements($filters, $perPage);

            return response()->json([
                'success' => true,
                'data' => $disbursements->items(),
                'meta' => [
                    'current_page' => $disbursements->currentPage(),
                    'last_page' => $disbursements->lastPage(),
                    'per_page' => $disbursements->perPage(),
                    'total' => $disbursements->total(),
                ],
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve disbursements',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created disbursement.
     */
    public function store(CreateDisbursementRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            if (empty($validated['requisition_id'])) {
                $directRequest = DirectDisbursementRequest::firstOrCreate(
                    ['idempotency_key' => $validated['idempotency_key']],
                    [
                        'status' => 'pending_approval',
                        'payload' => $validated,
                        'requested_by' => $request->user()->id,
                    ],
                );

                if ($directRequest->wasRecentlyCreated) {
                    $this->service->logActivity(
                        'submitted', 'direct_disbursement_request', $directRequest->id,
                        'Exceptional direct payment submitted for approval',
                        ['amount' => $validated['amount'], 'receiver' => $validated['receiver']],
                    );
                }

                return response()->json([
                    'success' => true,
                    'pending_approval' => true,
                    'message' => 'Exceptional direct payment submitted for independent approval',
                    'data' => $directRequest,
                ], $directRequest->wasRecentlyCreated ? 202 : 200);
            }

            $result = $this->service->createDisbursement($validated);

            if (isset($result['success']) && !$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $result['errors'],
                ], 422);
            }

            $disbursement = $result['data'];

            return response()->json([
                'success' => true,
                'message' => ($result['replayed'] ?? false)
                    ? 'Disbursement already processed'
                    : 'Disbursement created successfully',
                'data' => $disbursement->load('topUp', 'creator'),
                'replayed' => (bool) ($result['replayed'] ?? false),
            ], ($result['replayed'] ?? false) ? 200 : 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create disbursement',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function approveDirectRequest(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE), 403);

        $claim = DB::transaction(function () use ($request, $id) {
            $directRequest = DirectDisbursementRequest::query()->lockForUpdate()->findOrFail($id);

            if ($directRequest->requested_by === $request->user()->id && ! \App\Support\SelfApproval::allowedFor($request->user())) {
                return ['error' => 'You raised this direct payment, so someone else has to approve it. If nobody else is available, an administrator can grant the "Approve Your Own Submissions" permission.'];
            }
            if ($directRequest->status !== 'pending_approval') {
                return ['error' => 'Only pending direct payments can be approved.'];
            }

            $directRequest->update(['status' => 'processing']);

            return ['request' => $directRequest];
        });

        if (isset($claim['error'])) {
            return response()->json(['success' => false, 'message' => $claim['error']], 422);
        }

        /** @var DirectDisbursementRequest $directRequest */
        $directRequest = $claim['request'];
        $payload = $directRequest->payload;
        $payload['created_by'] = $directRequest->requested_by;
        try {
            $result = $this->service->createDisbursement($payload);
        } catch (\Throwable $failure) {
            $directRequest->update(['status' => 'pending_approval']);
            throw $failure;
        }

        if (! ($result['success'] ?? false)) {
            $directRequest->update(['status' => 'pending_approval']);

            return response()->json(['success' => false, 'message' => 'Payment could not be disbursed.', 'errors' => $result['errors']], 422);
        }

        $directRequest->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'disbursement_id' => $result['data']->id,
        ]);
        $this->service->logActivity(
            'approved', 'direct_disbursement_request', $directRequest->id,
            'Exceptional direct payment independently approved and disbursed',
            ['disbursement_id' => $result['data']->id],
        );

        return response()->json([
            'success' => true,
            'message' => 'Direct payment approved and disbursed',
            'data' => $result['data']->load('topUp', 'creator'),
        ]);
    }

    public function directRequests(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_READ), 403);

        $query = DirectDisbursementRequest::query()->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return response()->json(['success' => true, 'data' => $query->paginate(25)]);
    }

    public function rejectDirectRequest(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE), 403);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);

        $result = DB::transaction(function () use ($request, $id, $validated) {
            $directRequest = DirectDisbursementRequest::query()->lockForUpdate()->findOrFail($id);

            if ($directRequest->requested_by === $request->user()->id && ! \App\Support\SelfApproval::allowedFor($request->user())) {
                return ['error' => 'You raised this direct payment, so someone else has to reject it. If nobody else is available, an administrator can grant the "Approve Your Own Submissions" permission.'];
            }
            if ($directRequest->status !== 'pending_approval') {
                return ['error' => 'Only pending direct payments can be rejected.'];
            }

            $directRequest->update([
                'status' => 'rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'rejection_reason' => $validated['reason'],
            ]);

            $this->service->logActivity(
                'rejected', 'direct_disbursement_request', $directRequest->id,
                'Exceptional direct payment rejected', ['reason' => $validated['reason']],
            );

            return ['request' => $directRequest];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Direct payment rejected',
            'data' => $result['request'],
        ]);
    }

    /**
     * Display the specified disbursement.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $disbursement = $this->repository->findDisbursement($id);

            if (!$disbursement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Disbursement not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $disbursement,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve disbursement',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified disbursement.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (!Auth::user()?->can('update', PettyCashDisbursement::class)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to update a disbursement.',
            ], 403);
        }

        try {
            $disbursement = $this->repository->findDisbursement($id);

            if (!$disbursement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Disbursement not found',
                ], 404);
            }

            // Check if disbursement can be updated
            if ($disbursement->is_voided) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update voided disbursement',
                ], 400);
            }

            // Validate the request data (pass true for isUpdate and the ID to support partial updates and correct balance checks)
            $validationErrors = $this->service->validateDisbursementData($request->all(), true, $id);
            if (!empty($validationErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validationErrors,
                ], 422);
            }

            $updatedDisbursement = $this->service->updateDisbursement($disbursement, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Disbursement updated successfully',
                'data' => $updatedDisbursement->load('topUp', 'creator'),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update disbursement',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Void the specified disbursement.
     */
    public function void(Request $request, int $id): JsonResponse
    {
        if (!Auth::user()?->can('void', PettyCashDisbursement::class)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to void a disbursement.',
            ], 403);
        }

        try {
            $disbursement = $this->repository->findDisbursement($id);

            if (!$disbursement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Disbursement not found',
                ], 404);
            }

            // Validate void reason
            $request->validate([
                'void_reason' => 'required|string|max:500',
            ]);

            $this->service->voidDisbursement($disbursement, $request->void_reason);

            return response()->json([
                'success' => true,
                'message' => 'Disbursement voided successfully',
                'data' => $disbursement->fresh()->load('topUp', 'creator', 'voidedBy'),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to void disbursement',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Delete the specified disbursement.
     */
    public function destroy(int $id): JsonResponse
    {
        if (!Auth::user()?->can('delete', PettyCashDisbursement::class)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete a disbursement.',
            ], 403);
        }

        try {
            $disbursement = $this->repository->findDisbursement($id);

            if (!$disbursement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Disbursement not found',
                ], 404);
            }

            $this->service->deleteDisbursement($disbursement);

            return response()->json([
                'success' => true,
                'message' => 'Disbursement deleted successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete disbursement',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Delete multiple disbursements.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        if (!Auth::user()?->can('delete', PettyCashDisbursement::class)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete a disbursement.',
            ], 403);
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:petty_cash_disbursements,id'
        ]);

        try {
            $result = $this->service->bulkDeleteDisbursements($request->ids);
            return response()->json($result);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to perform bulk deletion',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Clear all petty cash data.
     */
    public function clearAll(): JsonResponse
    {
        // Routed through the policy like everything else, but the policy keeps
        // this one Super-Admin-only on purpose — see PettyCashPolicy::clearAll().
        if (!Auth::user()?->can('clearAll', PettyCashDisbursement::class)) {
            return response()->json([
                'success' => false,
                'message' => 'Only a Super Admin may clear petty cash history.',
            ], 403);
        }

        try {
            $result = $this->service->clearAllData();

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear petty cash data',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get hierarchical transaction view (top-ups with disbursements).
     */
    public function transactions(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'payment_method', 'creator_id', 'start_date', 'end_date', 
                'search', 'status', 'classification', 'show_archived'
            ]);

            $perPage = $request->get('per_page', 15);
            $filters['page'] = $request->get('page', 1);
            $transactions = $this->repository->getFlatTransactions($filters, $perPage);

            return response()->json([
                'success' => true,
                'data' => $transactions->items(),
                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                ],
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get current balance information.
     */
    public function balance(): JsonResponse
    {
        try {
            $balance = $this->repository->getCurrentBalance();

            return response()->json([
                'success' => true,
                'data' => (new PettyCashBalanceResource($balance))->resolve(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve balance information',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get transaction summary and analytics.
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'start_date', 'end_date', 'classification', 'project_name'
            ]);

            $summary = $this->service->getTransactionSummary($filters);

            return response()->json([
                'success' => true,
                'data' => $summary,
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve transaction summary',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get data for a petty cash voucher.
     */
    public function voucher(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'start_date', 'end_date', 'classification', 'project_name'
            ]);

            $data = $this->repository->getVoucherData($filters);

            return response()->json([
                'success' => true,
                'data' => $data,
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve voucher data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function downloadVoucherPdf(Request $request)
    {
        try {
            $filters = $request->only([
                'start_date', 'end_date', 'classification', 'project_name'
            ]);

            $data = $this->repository->getVoucherData($filters);
            
            // Determine voucher title based on date range
            $startDate = \Carbon\Carbon::parse($data['period']['start']);
            $endDate = \Carbon\Carbon::parse($data['period']['end']);
            $diffDays = $startDate->diffInDays($endDate);

            if ($diffDays <= 1) {
                $title = 'Daily Petty Cash Voucher';
            } elseif ($diffDays <= 7) {
                $title = 'Weekly Petty Cash Voucher';
            } elseif ($diffDays <= 32) {
                $title = 'Monthly Petty Cash Voucher';
            } else {
                $title = 'Periodical Petty Cash Voucher';
            }

            $pdf = Pdf::loadView('reports.finance.voucher', array_merge($data, ['title' => $title]));
            $pdf->setPaper('A4', 'portrait');
            
            $filename = str_replace(' ', '-', strtolower($title)) . '-' . $startDate->format('Y-m-d') . '.pdf';
            
            return $pdf->download($filename);
        } catch (Exception $e) {
            \Log::error('Petty Cash Voucher PDF Generation Failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate PDF voucher',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent transactions for dashboard.
     */
    public function recent(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 10);
            $recentTransactions = $this->service->getRecentTransactions($limit);

            return response()->json([
                'success' => true,
                'data' => $recentTransactions,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve recent transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search across all transactions.
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'query' => 'required|string|min:2',
            ]);

            $filters = $request->only([
                'status', 'classification', 'payment_method', 'start_date', 'end_date'
            ]);

            $perPage = $request->get('per_page', 15);
            $results = $this->repository->searchTransactions($request->query, $filters, $perPage);

            return response()->json([
                'success' => true,
                'data' => $results->items(),
                'meta' => [
                    'current_page' => $results->currentPage(),
                    'last_page' => $results->lastPage(),
                    'per_page' => $results->perPage(),
                    'total' => $results->total(),
                ],
                'query' => $request->query,
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Recalculate balance (admin function).
     */
    public function recalculateBalance(): JsonResponse
    {
        try {
            $result = $this->service->recalculateBalance();

            return response()->json([
                'success' => true,
                'message' => 'Balance recalculated successfully',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to recalculate balance',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload Excel file with disbursement data.
     */
    public function uploadExcel(Request $request): JsonResponse
    {
        try {
            // Validate file upload
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:xlsx,xls|max:10240', // Max 10MB
            ], [
                'file.required' => 'Please upload an Excel file',
                'file.mimes' => 'Only Excel files (.xlsx, .xls) are allowed',
                'file.max' => 'File size must be less than 10MB',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $file = $request->file('file');
            
            \Illuminate\Support\Facades\DB::beginTransaction();

            try {
                // Process the Excel file
                $import = new PettyCashDisbursementImport($this->service);
                ExcelFacade::import($import, $file);

                $results = $import->getResults();

                // Log activity
                $this->service->logActivity('import', 'disbursement', null, "Excel import processed: " . $results['successful_imports'] . " successful, " . count($results['failed_rows']) . " failed", [
                    'total_rows' => $results['total_rows'],
                    'successful_imports' => $results['successful_imports'],
                    'failed_rows' => count($results['failed_rows'])
                ]);

                \Illuminate\Support\Facades\DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Excel file processed successfully',
                    'data' => [
                        'total_rows' => $results['total_rows'],
                        'processed_rows' => $results['processed_rows'],
                        'successful_imports' => $results['successful_imports'],
                        'failed_rows' => $results['failed_rows'],
                        'duplicates' => $results['duplicates'],
                    ],
                ]);
            } catch (Exception $e) {
                \Illuminate\Support\Facades\DB::rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process Excel file',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download Excel template for disbursement upload.
     */
    public function downloadTemplate(): \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $headers = [
                'Date', 'Receiver', 'Account', 'Amount', 'Description', 
                'Project Name', 'Tax', 'Classification', 'Job No.', 'Transaction Code'
            ];
            
            $tempFile = tempnam(sys_get_temp_dir(), 'petty_cash_template');
            $handle = fopen($tempFile, 'w');
            
            // Add BOM for Excel UTF-8 support
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Header row
            fputcsv($handle, $headers);
            
            // Sample data row
            fputcsv($handle, [
                date('Y-m-d'), 
                'John Doe', 
                'Office Supplies', 
                '1500.00', 
                'Stationery for HR department', 
                '', 
                'ETR', 
                'Admin', 
                '', 
                ''
            ]);
            
            // Another sample row for a project
            fputcsv($handle, [
                date('Y-m-d'), 
                'Project Team A', 
                'Transport', 
                '5000.00', 
                'Fuel for site visit', 
                'Building Site X', 
                'ETR', 
                'Operations', 
                'WNG-01-2026-001', 
                ''
            ]);
            
            fclose($handle);
            
            return response()->download($tempFile, 'petty_cash_disbursements_template.csv', [
                'Content-Type' => 'text/csv',
            ])->deleteFileAfterSend(true);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate template',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Archive a transaction.
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        if (!Auth::user()?->can('archive', PettyCashDisbursement::class)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to archive a disbursement.',
            ], 403);
        }

        try {
            $type = $request->get('type', 'disbursement');
            $userId = auth()->id();
            
            if ($type === 'disbursement') {
                $disbursement = $this->repository->findDisbursement($id);
                if (!$disbursement) {
                    return response()->json(['success' => false, 'message' => 'Disbursement not found'], 404);
                }
                $this->service->archiveDisbursement($disbursement);
            } else {
                $topUp = $this->repository->findTopUp($id);
                if (!$topUp) {
                    return response()->json(['success' => false, 'message' => 'Top-up not found'], 404);
                }
                $this->service->archiveTopUp($topUp);
            }

            return response()->json([
                'success' => true,
                'message' => 'Transaction archived successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to archive transaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk archive transactions.
     */
    public function bulkArchive(Request $request): JsonResponse
    {
        if (!Auth::user()?->can('archive', PettyCashDisbursement::class)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to archive a disbursement.',
            ], 403);
        }

        try {
            $ids = $request->get('ids', []);
            if (empty($ids)) {
                return response()->json(['success' => false, 'message' => 'No IDs provided'], 400);
            }

            $count = $this->service->bulkArchiveDisbursements($ids);

            return response()->json([
                'success' => true,
                'message' => "{$count} transactions archived successfully",
                'data' => ['count' => $count]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to bulk archive transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Archive a top-up and all its disbursements.
     */
    public function archiveGroup(Request $request, int $id): JsonResponse
    {
        if (!Auth::user()?->can('archive', PettyCashDisbursement::class)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to archive a disbursement.',
            ], 403);
        }

        try {
            $this->service->archiveGroup($id);

            return response()->json([
                'success' => true,
                'message' => 'Top-up and all related disbursements archived successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to archive group',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk archive multiple groups.
     */
    public function bulkArchiveGroups(Request $request): JsonResponse
    {
        if (!Auth::user()?->can('archive', PettyCashDisbursement::class)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to archive a disbursement.',
            ], 403);
        }

        try {
            $ids = $request->get('ids', []);
            if (empty($ids)) {
                return response()->json(['success' => false, 'message' => 'No IDs provided'], 400);
            }

            $count = $this->service->bulkArchiveGroups($ids);

            return response()->json([
                'success' => true,
                'message' => "{$count} groups archived successfully",
                'data' => ['count' => $count]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to bulk archive groups',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get activity logs for petty cash.
     */
    public function getActivityLogs(Request $request): JsonResponse
    {
        if (!Auth::user()?->can('viewActivityLogs', PettyCashDisbursement::class)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view petty cash activity logs.',
            ], 403);
        }

        try {
            $query = \App\Modules\Finance\PettyCash\Models\PettyCashActivityLog::with('user')
                ->latest();

            if ($request->filled('action')) {
                $query->where('action', $request->action);
            }

            if ($request->filled('transaction_type')) {
                $query->where('transaction_type', $request->transaction_type);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            $logs = $query->paginate($request->get('limit', 15));

            return response()->json([
                'success' => true,
                'data' => $logs->items(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch activity logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
