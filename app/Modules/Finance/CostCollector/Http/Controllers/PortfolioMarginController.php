<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Services\CostAccountService;
use App\Services\ProjectFinancialAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * W6-2: Portfolio margin — batched, not N+1.
 *
 * Opt-in: the main accounts grid (GET /api/costs/accounts) intentionally omits
 * margin because computing it for every project in the portfolio is expensive.
 * Finance requests margin separately when needed, passing the enquiry IDs
 * they want to view.
 */
class PortfolioMarginController extends Controller
{
    public function __construct(
        private CostAccountService $accounts,
        private ProjectFinancialAccess $access,
    ) {}

    /** POST /api/costs/portfolio-margin */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->access->canViewPortfolio($request->user()), 403);

        $validated = $request->validate([
            'enquiry_ids'   => 'required|array|min:1|max:200',
            'enquiry_ids.*' => 'required|integer',
        ]);

        $margins = $this->accounts->portfolioMargin($validated['enquiry_ids']);

        return response()->json(['data' => $margins]);
    }
}
