<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
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
    public function store(Request $request): JsonResponse
    {
        try {
            // Validate the request data
            $validationErrors = $this->service->validateDisbursementData($request->all());
            if (!empty($validationErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validationErrors,
                ], 422);
            }

            $disbursement = $this->service->createDisbursement($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Disbursement created successfully',
                'data' => $disbursement->load('topUp', 'creator'),
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create disbursement',
                'error' => $e->getMessage(),
            ], 400);
        }
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

            // Validate the request data
            $validationErrors = $this->service->validateDisbursementData($request->all());
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
     * Get hierarchical transaction view (top-ups with disbursements).
     */
    public function transactions(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'payment_method', 'creator_id', 'start_date', 'end_date', 
                'search', 'disbursement_status', 'classification'
            ]);

            $perPage = $request->get('per_page', 15);
            $transactions = $this->repository->getHierarchicalTransactions($filters, $perPage);

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
            $balanceInfo = $this->service->getCurrentBalanceInfo();

            return response()->json([
                'success' => true,
                'data' => $balanceInfo,
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
     * Get spending analytics.
     */
    public function analytics(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'start_date', 'end_date', 'classification', 'project_name'
            ]);

            $analytics = $this->service->getSpendingAnalytics($filters);

            return response()->json([
                'success' => true,
                'data' => $analytics,
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve spending analytics',
                'error' => $e->getMessage(),
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
}