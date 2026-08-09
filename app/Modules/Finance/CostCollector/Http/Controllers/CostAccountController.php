<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostAccountService;
use Illuminate\Http\JsonResponse;

class CostAccountController extends Controller
{
    public function __construct(private CostAccountService $accounts) {}

    public function show(ProjectEnquiry $enquiry): JsonResponse
    {
        $this->authorize('viewAny', CostLine::class);

        return response()->json(['data' => $this->accounts->forEnquiry($enquiry)]);
    }
}
