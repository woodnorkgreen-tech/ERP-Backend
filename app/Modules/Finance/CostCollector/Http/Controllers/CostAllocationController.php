<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostAllocationService;
use App\Services\ProjectFinancialAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CostAllocationController extends Controller
{
    public function __construct(
        private CostAllocationService $service,
        private ProjectFinancialAccess $access,
    ) {}

    /** GET /api/costs/lines/{cost}/allocations */
    public function show(CostLine $cost): JsonResponse
    {
        abort_unless($this->access->canAllocate(request()->user()), 403);

        return response()->json(['data' => $this->service->getAllocations($cost)]);
    }

    /** POST /api/costs/lines/{cost}/allocations */
    public function store(Request $request, CostLine $cost): JsonResponse
    {
        abort_unless($this->access->canAllocate($request->user()), 403);

        $validated = $request->validate([
            'slices'              => 'required|array|min:2',
            'slices.*.enquiry_id' => 'required|integer|exists:project_enquiries,id',
            'slices.*.amount'     => 'required|numeric|min:0.01',
            'reason'              => 'required|string|max:500',
        ]);

        $this->service->allocate($cost, $validated['slices'], (int) $request->user()->id, $validated['reason']);

        return response()->json(['data' => $this->service->getAllocations($cost)], 201);
    }

    /** DELETE /api/costs/lines/{cost}/allocations */
    public function destroy(Request $request, CostLine $cost): JsonResponse
    {
        abort_unless($this->access->canAllocate($request->user()), 403);

        $this->service->deallocate($cost, (int) $request->user()->id);

        return response()->json(['message' => 'Allocations removed.']);
    }
}
