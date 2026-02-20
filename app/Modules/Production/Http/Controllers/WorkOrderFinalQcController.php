<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderFinalQcCheck;
use App\Modules\Production\Models\WorkOrderRework;
use App\Modules\Production\Services\ProductionNcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderFinalQcController extends Controller
{
    public function __construct(private readonly ProductionNcrService $ncrService)
    {
    }

    public function index(WorkOrder $workOrder): JsonResponse
    {
        $checks = WorkOrderFinalQcCheck::where('work_order_id', $workOrder->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $checks
        ]);
    }

    public function store(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'checks' => 'required|array',
            'checks.*.category' => 'required|string|max:255',
            'checks.*.title' => 'required|string|max:255',
            'checks.*.notes' => 'nullable|string',
            'checks.*.status' => 'required|in:pending,passed,failed',
            'checks.*.failure_reason' => 'nullable|string'
        ]);

        foreach ($validated['checks'] as $check) {
            if ($check['status'] === 'failed' && empty($check['failure_reason'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Reason is required when a Final QC check fails.'
                ], 422);
            }
        }

        $saved = [];
        foreach ($validated['checks'] as $check) {
            $record = WorkOrderFinalQcCheck::updateOrCreate(
                [
                    'work_order_id' => $workOrder->id,
                    'category' => $check['category'],
                    'title' => $check['title']
                ],
                [
                    'notes' => $check['notes'] ?? null,
                    'status' => $check['status'],
                    'failure_reason' => $check['failure_reason'] ?? null,
                    'checked_by' => auth()->id(),
                    'checked_at' => now()
                ]
            );
            $saved[] = $record;

            if ($check['status'] === 'failed') {
                $rework = WorkOrderRework::updateOrCreate(
                    [
                        'work_order_id' => $workOrder->id,
                        'source_type' => 'final_qc',
                        'source_ref' => null,
                        'title' => $check['category'] . ': ' . $check['title']
                    ],
                    [
                        'reason' => $check['failure_reason'] ?? null,
                        'qc_stage' => 'final_qc',
                        'status' => 'open',
                        'is_change_request' => false,
                        'created_by' => auth()->id()
                    ]
                );

                $this->ncrService->upsertFromQcFailure([
                    'work_order_id' => $workOrder->id,
                    'work_order_rework_id' => $rework->id,
                    'source_type' => 'final_qc',
                    'source_ref' => $check['category'] . ':' . $check['title'],
                    'qc_stage' => 'final_qc',
                    'workstation' => null,
                    'description' => $check['category'] . ': ' . $check['title'] . '. ' . ($check['failure_reason'] ?? ''),
                    'detected_by' => auth()->id(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Final QC checks saved',
            'data' => $saved
        ]);
    }
}
