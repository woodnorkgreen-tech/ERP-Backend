<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;

class PettyCashTopUpController extends Controller
{
    protected $service;
    protected $repository;

    public function __construct(PettyCashService $service, PettyCashRepository $repository)
    {
        $this->service = $service;
        $this->repository = $repository;
    }

    /**
     * Display a listing of top-ups.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'payment_method', 'creator_id', 'start_date', 'end_date', 'search'
            ]);

            $perPage = $request->get('per_page', 15);
            $topUps = $this->repository->getTopUps($filters, $perPage);

            return response()->json([
                'success' => true,
                'data' => $topUps->items(),
                'meta' => [
                    'current_page' => $topUps->currentPage(),
                    'last_page' => $topUps->lastPage(),
                    'per_page' => $topUps->perPage(),
                    'total' => $topUps->total(),
                ],
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve top-ups',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created top-up.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validate the request data
            $validationErrors = $this->service->validateTopUpData($request->all());
            if (!empty($validationErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validationErrors,
                ], 422);
            }

            $topUp = $this->service->createTopUp($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Top-up created successfully',
                'data' => $topUp->load('creator'),
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create top-up',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Display the specified top-up.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $topUp = $this->repository->findTopUp($id);

            if (!$topUp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Top-up not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $topUp,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve top-up',
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
     * Get top-ups with available balance for disbursement selection.
     */
    public function available(): JsonResponse
    {
        try {
            $availableTopUps = $this->repository->getTopUpsWithAvailableBalance();

            return response()->json([
                'success' => true,
                'data' => $availableTopUps,
                'message' => $availableTopUps->isEmpty() ? 'No available top-ups found' : null,
            ]);
        } catch (Exception $e) {
            \Log::error('Error in available top-ups endpoint: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve available top-ups',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get available balance for a specific top-up.
     */
    public function availableBalance(int $id): JsonResponse
    {
        try {
            $availableBalance = $this->service->getTopUpAvailableBalance($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'top_up_id' => $id,
                    'available_balance' => $availableBalance,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve available balance',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get balance trends and history.
     */
    public function trends(Request $request): JsonResponse
    {
        try {
            $days = $request->get('days', 30);
            $trends = $this->service->getBalanceTrends($days);

            return response()->json([
                'success' => true,
                'data' => $trends,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve balance trends',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate top-up data without creating.
     */
    public function validate(Request $request): JsonResponse
    {
        try {
            $validationErrors = $this->service->validateTopUpData($request->all());

            if (!empty($validationErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validationErrors,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Validation passed',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get top-up statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'start_date', 'end_date', 'payment_method', 'creator_id'
            ]);

            $summary = $this->service->getTransactionSummary($filters);

            // Extract top-up specific statistics
            $topUpStats = [
                'total_amount' => $summary['total_top_ups'],
                'count' => $summary['top_up_count'],
                'average_amount' => $summary['average_top_up'],
                'current_balance' => $summary['current_balance'],
                'balance_status' => $summary['balance_status'],
            ];

            return response()->json([
                'success' => true,
                'data' => $topUpStats,
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve top-up statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get payment method breakdown for top-ups.
     */
    public function paymentMethods(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['start_date', 'end_date']);

            // Get top-ups grouped by payment method
            $topUps = $this->repository->getTopUps($filters, 1000);
            $paymentMethodBreakdown = $topUps->groupBy('payment_method')->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'total_amount' => $group->sum('amount'),
                    'average_amount' => $group->avg('amount'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $paymentMethodBreakdown,
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment method breakdown',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if sufficient balance exists for a disbursement amount.
     */
    public function checkBalance(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:0.01',
            ]);

            $amount = $request->get('amount');
            $currentBalance = $this->service->getCurrentBalanceInfo();

            $hasSufficientBalance = $currentBalance['current_balance'] >= $amount;

            return response()->json([
                'success' => true,
                'data' => [
                    'amount_requested' => $amount,
                    'current_balance' => $currentBalance['current_balance'],
                    'has_sufficient_balance' => $hasSufficientBalance,
                    'remaining_after_disbursement' => $hasSufficientBalance 
                        ? $currentBalance['current_balance'] - $amount 
                        : null,
                    'balance_status' => $currentBalance['status'],
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check balance',
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}