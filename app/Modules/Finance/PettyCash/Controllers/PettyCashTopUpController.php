<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use App\Constants\Permissions;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Services\LedgerEntry;
use App\Modules\Finance\PettyCash\Services\LedgerService;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Modules\Finance\PettyCash\Resources\PettyCashBalanceResource;
use Exception;

class PettyCashTopUpController extends Controller
{
    protected $service;
    protected $repository;
    protected LedgerService $ledger;

    public function __construct(PettyCashService $service, PettyCashRepository $repository, LedgerService $ledger)
    {
        $this->service = $service;
        $this->repository = $repository;
        $this->ledger = $ledger;
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
     * Update the specified top-up.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // Editing a top-up posts an adjustment entry for the amount delta, i.e.
        // it moves the cash balance. This endpoint previously had no
        // authorization of any kind (audit BE2).
        abort_unless(
            $request->user()?->can(Permissions::FINANCE_PETTY_CASH_EDIT_TOP_UP),
            403,
            'You do not have permission to edit a top-up.',
        );

        try {
            $topUp = $this->repository->findTopUp($id);

            if (!$topUp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Top-up not found',
                ], 404);
            }

            // Validate the request data
            $validationErrors = $this->service->validateTopUpData($request->all());
            if (!empty($validationErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validationErrors,
                ], 422);
            }

            $updatedTopUp = $this->service->updateTopUp($topUp, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Top-up updated successfully',
                'data' => $updatedTopUp->load('creator'),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update top-up',
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
     * Validate top-up data without creating.
     */
    public function validateTopUp(Request $request): JsonResponse
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

    /**
     * Get monthly balance movement trends.
     */
    public function trends(Request $request): JsonResponse
    {
        try {
            $months = max(1, min((int) $request->get('months', 12), 24));
            $startDate = now()->subMonths($months - 1)->startOfMonth();
            $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
            $periodExpression = $driver === 'sqlite'
                ? "strftime('%Y-%m', posted_at)"
                : "DATE_FORMAT(posted_at, '%Y-%m')";

            $ledgerRows = \Illuminate\Support\Facades\DB::table('petty_cash_ledger_entries')
                ->selectRaw($periodExpression . ' as period')
                ->selectRaw("SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as credits")
                ->selectRaw("SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as debits")
                ->where('posted_at', '>=', $startDate)
                ->groupBy('period')
                ->orderBy('period')
                ->get()
                ->keyBy('period');

            $openingCredits = (float) \Illuminate\Support\Facades\DB::table('petty_cash_ledger_entries')
                ->where('type', 'credit')
                ->where('posted_at', '<', $startDate)
                ->sum('amount');
            $openingDebits = (float) \Illuminate\Support\Facades\DB::table('petty_cash_ledger_entries')
                ->where('type', 'debit')
                ->where('posted_at', '<', $startDate)
                ->sum('amount');

            $runningBalance = $openingCredits - $openingDebits;
            $data = [];
            $cursor = $startDate->copy();

            while ($cursor <= now()->endOfMonth()) {
                $period = $cursor->format('Y-m');
                $row = $ledgerRows->get($period);
                $credits = (float) ($row->credits ?? 0);
                $debits = (float) ($row->debits ?? 0);
                $runningBalance += $credits - $debits;

                $data[] = [
                    'period' => $period,
                    'month' => $cursor->format('M Y'),
                    'credits' => $credits,
                    'debits' => $debits,
                    'net_flow' => $credits - $debits,
                    'closing_balance' => $runningBalance,
                ];

                $cursor->addMonth();
            }

            return response()->json([
                'success' => true,
                'data' => $data,
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
     * Get petty cash statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['start_date', 'end_date', 'classification', 'project_name']);
            $summary = $this->service->getTransactionSummary($filters);
            $availableTopUps = $this->repository->getTopUpsWithAvailableBalance();

            return response()->json([
                'success' => true,
                'data' => array_merge($summary, [
                    'available_top_up_count' => $availableTopUps->count(),
                    'available_top_up_balance' => (float) $availableTopUps->sum(function ($topUp) {
                        return (float) ($topUp->calculated_remaining_balance ?? $topUp->remaining_balance);
                    }),
                ]),
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get supported petty cash payment methods.
     */
    public function paymentMethods(): JsonResponse
    {
        $methods = [
            'cash' => 'Cash',
            'mpesa' => 'M-Pesa',
            'equity' => 'Equity',
            'stanbic' => 'Stanbic',
            'ncba' => 'NCBA',
            'kcb' => 'KCB',
            'family' => 'Family Bank',
            'bank_transfer' => 'Bank Transfer',
            'other' => 'Other',
        ];

        return response()->json([
            'success' => true,
            'data' => collect($methods)->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])->values(),
        ]);
    }

    /**
     * Remove the specified top-up from storage.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_unless(
            $request->user()?->can(Permissions::FINANCE_PETTY_CASH_DELETE_TOP_UP),
            403,
            'You do not have permission to delete a top-up.',
        );

        try {
            $topUp = $this->repository->findTopUp($id);

            if (!$topUp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Top-up not found',
                ], 404);
            }

            // Check if there are active disbursements linked to this top-up
            $activeDisbursements = $topUp->activeDisbursements()->get();
            if ($activeDisbursements->isNotEmpty()) {
                $details = $activeDisbursements->map(function($d) {
                    $formattedAmount = number_format((float)$d->amount, 2);
                    return "#{$d->id}: {$d->receiver} (KES {$formattedAmount})";
                })->implode(', ');

                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete top-up because it has active disbursements linked to it: {$details}. Please void or delete these disbursements first.",
                ], 400);
            }

            // Reverse before removing, in one transaction. Deleting the row on
            // its own left the original TOP-xxxxxx credit in the ledger and the
            // cached balance permanently overstated (audit BE1) — the ledger is
            // the source of truth, so it must be told.
            $balance = DB::transaction(function () use ($topUp, $request) {
                $balance = $this->ledger->post(
                    LedgerEntry::reversalForTopUp($topUp, $request->user()?->id),
                );

                $topUp->delete();

                return $balance;
            });

            return response()->json([
                'success' => true,
                'message' => 'Top-up deleted and its ledger entry reversed.',
                // The plain figure, not the full balance resource: this response
                // only needs to confirm where the balance landed, and building
                // the richer payload here would couple deletion to it.
                'data' => ['current_balance' => (float) $balance->current_balance],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete top-up',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
