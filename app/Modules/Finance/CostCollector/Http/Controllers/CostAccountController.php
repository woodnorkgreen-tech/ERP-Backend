<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CostAccountController extends Controller
{
    public function __construct(private CostAccountService $accounts) {}

    /** Every project's cost account, one row each — the accounts grid. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CostLine::class);

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json($this->accounts->index($validated, $validated['per_page'] ?? 25));
    }

    public function show(ProjectEnquiry $enquiry): JsonResponse
    {
        $this->authorize('viewAny', CostLine::class);

        return response()->json(['data' => $this->accounts->forEnquiry($enquiry)]);
    }
}
