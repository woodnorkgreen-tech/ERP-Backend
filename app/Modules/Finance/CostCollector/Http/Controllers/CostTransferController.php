<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostTransferService;
use App\Services\ProjectFinancialAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CostTransferController extends Controller
{
    public function __construct(
        private CostTransferService $service,
        private ProjectFinancialAccess $access,
    ) {}

    /** POST /api/costs/lines/{cost}/transfer */
    public function store(Request $request, CostLine $cost): JsonResponse
    {
        abort_unless($this->access->canTransfer($request->user()), 403);

        $validated = $request->validate([
            'destination_enquiry_id' => 'required|integer|exists:project_enquiries,id',
            'reason'                 => 'required|string|max:500',
        ]);

        $destination = ProjectEnquiry::findOrFail($validated['destination_enquiry_id']);
        $transfer = $this->service->transfer($cost, $destination, (int) $request->user()->id, $validated['reason']);

        return response()->json(['data' => [
            'transfer_id'    => $transfer->id,
            'out_ref'        => $transfer->outLine->ref,
            'in_ref'         => $transfer->inLine->ref,
            'reason'         => $transfer->reason,
            'transferred_by' => $transfer->transferred_by,
            'transferred_at' => $transfer->created_at->toIso8601String(),
        ]], 201);
    }
}
