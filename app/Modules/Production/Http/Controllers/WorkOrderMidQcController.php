<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderMidQcCheck;
use App\Modules\Production\Models\WorkOrderRework;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderMidQcController extends Controller
{
    public function index(WorkOrder $workOrder): JsonResponse
    {
        $checks = WorkOrderMidQcCheck::where('work_order_id', $workOrder->id)
            ->orderBy('workstation')
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
            'checks.*.workstation' => 'required|string|max:100',
            'checks.*.qc_stage' => 'required|in:mid_production,post_fabrication,post_assembly,post_event',
            'checks.*.category' => 'required|string|max:255',
            'checks.*.title' => 'required|string|max:255',
            'checks.*.notes' => 'nullable|string',
            'checks.*.status' => 'required|in:pending,passed,failed'
        ]);

        foreach ($validated['checks'] as $check) {
            if ($check['status'] === 'failed' && empty($check['notes'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Reason is required when a Mid-QC check fails.'
                ], 422);
            }
        }

        $saved = [];
        foreach ($validated['checks'] as $check) {
            $record = WorkOrderMidQcCheck::updateOrCreate(
                [
                    'work_order_id' => $workOrder->id,
                    'workstation' => $check['workstation'],
                    'qc_stage' => $check['qc_stage'],
                    'category' => $check['category'],
                    'title' => $check['title']
                ],
                [
                    'notes' => $check['notes'] ?? null,
                    'status' => $check['status'],
                    'checked_by' => auth()->id(),
                    'checked_at' => now()
                ]
            );
            $saved[] = $record;

            if ($check['status'] === 'failed') {
                WorkOrderRework::updateOrCreate(
                    [
                        'work_order_id' => $workOrder->id,
                        'source_type' => 'mid_qc',
                        'source_ref' => $check['workstation'],
                        'title' => $check['category'] . ': ' . $check['title']
                    ],
                    [
                        'reason' => $check['notes'] ?? null,
                        'qc_stage' => $check['qc_stage'],
                        'status' => 'open',
                        'is_change_request' => false,
                        'created_by' => auth()->id()
                    ]
                );
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Mid-QC checks saved',
            'data' => $saved
        ]);
    }
}
